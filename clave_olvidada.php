<?php
/**
 * clave_olvidada.php — Paso 1 del flujo "Olvidé mi contraseña".
 *
 * Punto de entrada público (igual que login.php/registro.php): pide la
 * cédula o correo del paciente, genera un código de 6 dígitos válido por
 * 15 minutos, lo guarda (hasheado) en password_resets y lo envía por
 * correo. Si todo sale bien, deja el estado del flujo en sesión y manda
 * al usuario a verificar_token.php — esa página (y restablecer_contrasena.php)
 * no se pueden abrir directamente sin haber pasado por aquí primero.
 */

require_once __DIR__ . '/assets/includes/session_boot.php';
require_once './assets/includes/config/config.php';
require_once './assets/includes/helpers/bitacora_helper.php';
require_once './assets/includes/config/mail_config.php';
require_once './assets/includes/helpers/mail_helper.php';

define('RESET_CODIGO_MINUTOS', 15);   // El código dura 15 minutos antes de vencer.
define('RESET_MAX_SOLICITUDES', 3);   // Máximo de códigos que se pueden pedir seguidos.
define('RESET_VENTANA_MINUTOS', 15);  // ...dentro de una ventana de 15 minutos.

$error = '';
$mensaje_enviado = false;

// Si el usuario envió el formulario con su cédula o correo...
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identificador = trim($_POST['identificador'] ?? '');

    if ($identificador === '') {
        $error = 'Ingresa tu cédula o correo.';
    } else {
        // Buscamos una cuenta activa que coincida con ese correo o cédula.
        $stmt = $pdo->prepare('SELECT id_usuario, nombre, apellido, correo FROM usuarios WHERE (correo = :id OR cedula = :id) AND estado = 1 LIMIT 1');
        $stmt->execute(['id' => $identificador]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            $error = 'No encontramos ninguna cuenta activa con esa cédula o correo.';
        } else {
            // Límite de solicitudes: máximo RESET_MAX_SOLICITUDES códigos por
            // usuario en los últimos RESET_VENTANA_MINUTOS, para evitar que
            // alguien spamee de correos a otra persona.
            $stmt_limite = $pdo->prepare('
                SELECT COUNT(*) FROM password_resets
                WHERE id_usuario = :id AND fecha_creacion >= DATE_SUB(NOW(), INTERVAL :ventana MINUTE)
            ');
            $stmt_limite->execute(['id' => $usuario['id_usuario'], 'ventana' => RESET_VENTANA_MINUTOS]);
            $solicitudes_recientes = (int) $stmt_limite->fetchColumn();

            if ($solicitudes_recientes >= RESET_MAX_SOLICITUDES) {
                $error = 'Ya solicitaste varios códigos. Espera unos minutos antes de intentarlo de nuevo.';
            } else {
                // Generamos un código de 6 dígitos al azar (con ceros a la izquierda si hace falta).
                $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $codigo_hash = password_hash($codigo, PASSWORD_BCRYPT); // Se guarda hasheado, nunca en texto plano.
                $expira_en = (new DateTime())->modify('+' . RESET_CODIGO_MINUTOS . ' minutes')->format('Y-m-d H:i:s');

                // Guardamos el código en la base de datos junto con su fecha de vencimiento.
                $stmt_ins = $pdo->prepare('
                    INSERT INTO password_resets (id_usuario, codigo_hash, expira_en)
                    VALUES (:id_usuario, :codigo_hash, :expira_en)
                ');
                $stmt_ins->execute([
                    'id_usuario'  => $usuario['id_usuario'],
                    'codigo_hash' => $codigo_hash,
                    'expira_en'   => $expira_en,
                ]);
                $id_reset = (int) $pdo->lastInsertId();

                // Enviamos el código (en texto plano, tal cual el usuario lo debe escribir) por correo.
                $nombre_completo = trim($usuario['nombre'] . ' ' . $usuario['apellido']);
                $enviado = enviar_codigo_reset($usuario['correo'], $nombre_completo, $codigo);

                if (!$enviado) {
                    // No se pudo enviar: se descarta el código para no dejar
                    // uno "vivo" que el usuario nunca podrá recibir.
                    $pdo->prepare('DELETE FROM password_resets WHERE id_reset = :id')->execute(['id' => $id_reset]);
                    registrar_bitacora($pdo, 'ERROR', "Falló el envío del correo de recuperación de contraseña al usuario #{$usuario['id_usuario']} ({$usuario['correo']}).", (int) $usuario['id_usuario']);
                    $error = 'No pudimos enviar el correo con el código. Intenta de nuevo en unos minutos.';
                } else {
                    registrar_bitacora($pdo, 'UPDATE', "Se solicitó un código de recuperación de contraseña para el usuario #{$usuario['id_usuario']}.", (int) $usuario['id_usuario']);

                    // Guardamos en sesión en qué paso del flujo va el usuario, para que verificar_token.php sepa que puede continuar.
                    $_SESSION['reset_id_usuario'] = (int) $usuario['id_usuario'];
                    $_SESSION['reset_id_reset']   = $id_reset;
                    $_SESSION['reset_correo']     = $usuario['correo'];
                    $_SESSION['reset_paso']       = 'token';

                    header('Location: verificar_token.php');
                    exit;
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">

    <head>

        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>OdontoNet — Olvidé mi contraseña</title>
        <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="stylesheet" href="assets/css/shared/variables.css">
        <link rel="stylesheet" href="assets/css/shared/global.css">
        <link rel="stylesheet" href="assets/css/shared/login.css">
        <link rel="stylesheet" href="assets/css/shared/password_reset.css">
        <link rel="stylesheet" href="assets/css/shared/componentes.css">

    </head>

    <body>

        <div class="glow glow-a"></div>
        <div class="glow glow-b"></div>
        <div class="glow glow-c"></div>
        <div class="grid-overlay"></div>

        <main class="auth-page">

            <div class="login-card-glow">

                <div class="card login-card">

                    <div class="login-logo">
                        <a href="index.php" class="logo">
                            <img src="assets/images/logos/logo-auth.svg" alt="OdontoNet" class="logo-img">
                        </a>
                    </div>

                    <h1>Olvidé mi contraseña</h1>
                    <p class="reset-subtitle">Ingresa tu cédula o correo y te enviaremos un código de 6 dígitos para restablecer tu contraseña.</p>

                    <?php if ($error): ?>
                        <div class="form-error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="post" action="">

                        <div class="field">
                            <label for="identificador"><span class="dash-req">*</span> Cédula o correo</label>
                            <input type="text" name="identificador" id="identificador" placeholder="1-2345-6789 o tu@correo.com" required autocomplete="username" value="<?= htmlspecialchars($_POST['identificador'] ?? '') ?>">
                        </div>

                        <button class="btn-primary" type="submit">Enviar código</button>

                    </form>

                    <div class="login-footer">
                        <span class="paragraph">¿Ya lo recordaste?</span>
                        <a href="login.php" class="link">Volver a iniciar sesión</a>
                    </div>

                </div>

            </div>

        </main>

    </body>

</html>
