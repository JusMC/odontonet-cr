<?php
/**
 * restablecer_contrasena.php — Paso 3 del flujo "Olvidé mi contraseña".
 *
 * NO es accesible manualmente: solo se llega aquí después de verificar el
 * código en verificar_token.php (que deja reset_paso = 'password' en
 * sesión). Cualquier visita sin ese estado se redirige al paso 1.
 */

require_once __DIR__ . '/assets/includes/session_boot.php';
require_once './assets/includes/config/config.php';
require_once './assets/includes/helpers/bitacora_helper.php';

// Guard: hace falta haber pasado por clave_olvidada.php Y verificar_token.php.
if (!isset($_SESSION['reset_id_usuario'], $_SESSION['reset_id_reset']) || ($_SESSION['reset_paso'] ?? '') !== 'password') {
    header('Location: clave_olvidada.php');
    exit;
}

$error = '';

// Si el usuario envió el formulario con su nueva contraseña...
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clave            = $_POST['clave'] ?? '';
    $clave_confirmar  = $_POST['clave_confirmar'] ?? '';

    if (!preg_match('/^(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}$/', $clave)) {
        $error = 'La contraseña debe tener al menos 8 caracteres, incluir una mayúscula, una minúscula, un número y un carácter especial.';
    } elseif ($clave !== $clave_confirmar) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        // La cuenta puede no tener contraseña todavía (creada por OAuth), en
        // cuyo caso no hay "contraseña actual" con la que comparar.
        $stmt_actual = $pdo->prepare('SELECT contrasena FROM usuarios WHERE id_usuario = :id');
        $stmt_actual->execute(['id' => $_SESSION['reset_id_usuario']]);
        $hash_actual = $stmt_actual->fetchColumn();

        if (!empty($hash_actual) && password_verify($clave, $hash_actual)) {
            $error = 'La nueva contraseña no puede ser igual a la actual.';
        }
    }

    // Si pasó todas las validaciones (sin errores), guardamos la nueva contraseña.
    if ($error === '') {
        try {
            $pdo->beginTransaction();

            // Actualizamos la contraseña del usuario (siempre cifrada/hasheada, nunca en texto plano).
            $pdo->prepare('UPDATE usuarios SET contrasena = :contrasena WHERE id_usuario = :id')
                ->execute([
                    'contrasena' => password_hash($clave, PASSWORD_BCRYPT),
                    'id'         => $_SESSION['reset_id_usuario'],
                ]);

            // Marcamos el código de recuperación como usado, para que no se pueda reutilizar.
            $pdo->prepare('UPDATE password_resets SET usado = 1 WHERE id_reset = :id')
                ->execute(['id' => $_SESSION['reset_id_reset']]);

            $pdo->commit();

            registrar_bitacora($pdo, 'UPDATE', "El usuario #{$_SESSION['reset_id_usuario']} restableció su contraseña mediante el flujo de recuperación.", (int) $_SESSION['reset_id_usuario']);

            // Limpiamos todo el estado del flujo de recuperación de la sesión.
            unset($_SESSION['reset_id_usuario'], $_SESSION['reset_id_reset'], $_SESSION['reset_correo'], $_SESSION['reset_paso']);

            header('Location: login.php?reset=exito');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            registrar_bitacora($pdo, 'ERROR', "Falló el restablecimiento de contraseña del usuario #{$_SESSION['reset_id_usuario']}: " . $e->getMessage(), (int) $_SESSION['reset_id_usuario']);
            $error = 'Ocurrió un error al restablecer tu contraseña. Intenta de nuevo.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">

    <head>

        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>OdontoNet — Restablecer contraseña</title>
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

                    <h1>Nueva contraseña</h1>
                    <p class="reset-subtitle">Ya verificamos tu código. Crea una nueva contraseña para tu cuenta.</p>

                    <?php if ($error): ?>
                        <div class="form-error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="post" action="">

                        <div class="field">
                            <label for="clave"><span class="dash-req">*</span> Nueva contraseña</label>
                            <input type="password" name="clave" id="clave" placeholder="••••••••" required autocomplete="new-password"
                                pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}"
                                title="La contraseña debe tener al menos 8 caracteres, incluir una mayúscula, una minúscula, un número y un carácter especial.">
                        </div>

                        <div class="field">
                            <label for="clave_confirmar"><span class="dash-req">*</span> Confirmar contraseña</label>
                            <input type="password" name="clave_confirmar" id="clave_confirmar" placeholder="••••••••" required autocomplete="new-password">
                        </div>

                        <button class="btn-primary" type="submit">Restablecer contraseña</button>

                    </form>

                </div>

            </div>

        </main>

    </body>

</html>
