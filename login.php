<?php

// login.php — Inicio de sesión de pacientes.

/* Este archivo hace DOS trabajos a la vez:

   1) Lógica de backend (arriba, en PHP puro): recibe el formulario por
      POST, valida la cédula/contraseña contra la base de datos y crea
      la sesión si todo está correcto.

   2) Vista (abajo, HTML): dibuja el formulario que el paciente ve.

*/

/* 

   Ambos viven en el mismo archivo a propósito: el formulario se envía a
   sí mismo (action=""), así que la validación tiene que estar aquí mismo
   para poder ejecutarse ANTES de imprimir el HTML (necesario para poder
   hacer header('Location: ...') más abajo, y para poder mostrar $error
   dentro de la página si el login falla).

*/

session_start();

require_once './assets/includes/config/config.php'; // Trae $pdo, la conexión a la base de datos
require_once './assets/includes/helpers/bitacora_helper.php';

$error = ''; // Mensaje de error a mostrar en el formulario, vacío si no hay error
$exito = ''; // Mensaje de éxito a mostrar en el formulario, vacío si no hay ninguno

// Mensajes de estado en la página de login.
if (isset($_GET['razon']) && $_GET['razon'] === 'inactivo') {
    $error = 'Tu cuenta ha sido desactivada. Contacta al administrador.';
}

if (isset($_GET['reset']) && $_GET['reset'] === 'exito') {
    $exito = 'Tu contraseña se restableció correctamente. Ya puedes iniciar sesión con ella.';
}

// Mensajes de error (Google OAuth)
if (isset($_GET['error'])) {
    $error = match($_GET['error']) {
        'oauth_state'  => 'Error de seguridad en el inicio con Google. Intenta de nuevo.',
        'oauth_code'   => 'Google no devolvió un código válido. Intenta de nuevo.',
        'oauth_token'  => 'No se pudo completar la autenticación con Google. Intenta de nuevo.',
        'oauth_perfil' => 'No se pudo obtener tu perfil de Google. Intenta de nuevo.',
        'oauth_db'     => 'Error al crear tu cuenta. Contacta al administrador.',
        'ms_state'     => 'Error de seguridad en el inicio con Microsoft. Intenta de nuevo.',
        'ms_code'      => 'Microsoft no devolvió un código válido. Intenta de nuevo.',
        'ms_token'     => 'No se pudo completar la autenticación con Microsoft. Intenta de nuevo.',
        'ms_perfil'    => 'No se pudo obtener tu perfil de Microsoft. Intenta de nuevo.',
        'ms_db'        => 'Error al crear tu cuenta. Contacta al administrador.',
        default        => 'Error desconocido. Intenta de nuevo.',
    };
}

/* ----------------------------------------------------------------------
 A dónde redirigir después de iniciar sesión con éxito.
 Por defecto va a inicio.php, pero si el paciente llegó aquí desde un
 enlace tipo 'login.php?redirect=citas.php' (por ejemplo, al hacer clic
 en "Agendar cita" en el index estando deslogueado), lo mandamos de
 vuelta a esa página en lugar de al panel principal.
 El "??" es el operador de coalescencia nula: usa el primer valor que
 no sea null, revisando primero la URL (GET) y luego el propio POST
 (por si el valor viene reenviado en el <input type="hidden">).
---------------------------------------------------------------------- */

// Guardamos si vino un redirect explícito (por URL o reenviado en el POST) para no sobreescribirlo con el destino por rol más abajo.
$redirect_explicito = $_GET['redirect'] ?? $_POST['redirect'] ?? null;
$redirect = $redirect_explicito ?? 'inicio.php';

// ── Rate limiting ──────────────────────────────────────────────────────────
define('LOGIN_MAX_INTENTOS',    5);
define('LOGIN_VENTANA_MINUTOS', 15);

// Cuenta cuántos intentos de login fallidos hubo recientemente para este usuario o esta IP.
function contar_intentos(PDO $pdo, string $identificador, string $ip): int {
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM login_intentos
        WHERE (identificador = :id OR ip = :ip)
          AND fecha >= DATE_SUB(NOW(), INTERVAL :ventana MINUTE)
    ');
    $stmt->execute([
        ':id'      => $identificador,
        ':ip'      => $ip,
        ':ventana' => LOGIN_VENTANA_MINUTOS,
    ]);
    return (int) $stmt->fetchColumn();
}

// Guarda un intento fallido nuevo.
function registrar_intento(PDO $pdo, string $identificador, string $ip): void {
    $stmt = $pdo->prepare('
        INSERT INTO login_intentos (identificador, ip) VALUES (:id, :ip)
    ');
    $stmt->execute([':id' => $identificador, ':ip' => $ip]);
}

// Borra el historial de intentos fallidos (se usa después de un login exitoso).
function limpiar_intentos(PDO $pdo, string $identificador, string $ip): void {
    $stmt = $pdo->prepare('
        DELETE FROM login_intentos WHERE identificador = :id OR ip = :ip
    ');
    $stmt->execute([':id' => $identificador, ':ip' => $ip]);
}
// ──────────────────────────────────────────────────────────────────────────

// Si el formulario fue enviado (método POST), procesamos el login.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $identificador = trim($_POST['identificador'] ?? '');
    $clave         = $_POST['clave'] ?? '';
    $ip            = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    /* Con el modelo usuarios + pacientes + doctores, la cédula solo existe en `pacientes`, así que un doctor/recepcionista/admin no puede entrar
       con cédula. Por eso el campo ahora acepta cédula O correo: primero intentamos el JOIN con `pacientes` (login de paciente, igual que antes); 
       si no hay match, caemos a buscar directo en `usuarios.correo` (cubre staff, y también pacientes que prefieran usar su correo). */
       
    // ── Verificar bloqueo ANTES de consultar la DB ─────────────────────────
    $intentos_actuales = contar_intentos($pdo, $identificador, $ip);

    if ($intentos_actuales >= LOGIN_MAX_INTENTOS) {
        $error = 'Demasiados intentos fallidos. Espera ' . LOGIN_VENTANA_MINUTOS . ' minutos e inténtalo de nuevo.';

    } else {

        // Primero intentamos encontrar el usuario por cédula (cualquier rol — LEFT JOIN para no excluir staff).
        $stmt = $pdo->prepare('
            SELECT u.id_usuario, u.id_rol, u.nombre, u.apellido, u.correo, u.cedula, u.contrasena, u.foto_perfil, p.id_paciente
            FROM usuarios u
            LEFT JOIN pacientes p ON p.id_usuario = u.id_usuario
            WHERE u.cedula = :identificador AND u.estado = 1
        ');
        $stmt->execute(['identificador' => $identificador]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            // No lo encontramos por cédula: probamos de nuevo, esta vez por correo.
            $stmt = $pdo->prepare('
                SELECT u.id_usuario, u.id_rol, u.nombre, u.apellido, u.correo, u.cedula, u.contrasena, u.foto_perfil, p.id_paciente
                FROM usuarios u
                LEFT JOIN pacientes p ON p.id_usuario = u.id_usuario
                WHERE u.correo = :identificador AND u.estado = 1
            ');
            $stmt->execute(['identificador' => $identificador]);
            $usuario = $stmt->fetch();
        }

        if ($usuario && password_verify($clave, $usuario['contrasena'] ?? '')) {

            // ── Login correcto ─────────────────────────────────────────────
            limpiar_intentos($pdo, $identificador, $ip);

            if ($redirect_explicito === null) {
                $redirect = match ((int) $usuario['id_rol']) {
                    4      => 'admin.php',
                    2, 3   => 'control_citas.php',
                    default => 'inicio.php',
                };
            }

            // Si tiene la verificación en dos pasos activa, la sesión
            // completa NO se crea todavía: queda pendiente hasta que
            // verificar_mfa.php confirme el código.
            require_once './assets/includes/config/mfa_config.php';
            require_once './assets/includes/helpers/mfa_helper.php';

            if (mfa_esta_activo($pdo, (int) $usuario['id_usuario'])) {
                $_SESSION['mfa_pendiente_usuario_id'] = (int) $usuario['id_usuario'];
                $_SESSION['mfa_pendiente_destino']    = '/OdontoNet/' . $redirect;
                header('Location: verificar_mfa.php');
                exit;
            }

            // Sin MFA (o ya verificado antes): guardamos los datos del usuario en la sesión, queda logueado.
            $_SESSION['usuario_id'] = $usuario['id_usuario'];
            $_SESSION['id_rol']     = (int) $usuario['id_rol'];
            $_SESSION['nombre']     = $usuario['nombre'];
            $_SESSION['apellido']   = $usuario['apellido'];
            $_SESSION['correo']     = $usuario['correo'];
            $_SESSION['cedula']     = $usuario['cedula'];
            $_SESSION['foto_perfil'] = $usuario['foto_perfil'];

            // Si es paciente, guardamos también su id_paciente.
            if ($usuario['id_paciente']) {
                $_SESSION['paciente_id'] = $usuario['id_paciente'];
            }

            registrar_bitacora($pdo, 'LOGIN', "Inicio de sesión de {$usuario['nombre']} (#{$usuario['id_usuario']}).", (int) $usuario['id_usuario']);

            header('Location: /OdontoNet/' . $redirect);
            exit;

        } else {

            // ── Login fallido: registrar intento ───────────────────────────
            registrar_intento($pdo, $identificador, $ip);
            registrar_bitacora(
                $pdo,
                'ERROR',
                "Intento de inicio de sesión fallido para '$identificador' (IP: $ip).",
                $usuario['id_usuario'] ?? null
            );

            // Calculamos cuántos intentos le quedan, para avisarle antes de que se bloquee.
            $intentos_tras_fallo = $intentos_actuales + 1;
            $restantes           = LOGIN_MAX_INTENTOS - $intentos_tras_fallo;

            if ($restantes <= 0) {
                $error = 'Cuenta bloqueada temporalmente. Espera ' . LOGIN_VENTANA_MINUTOS . ' minutos e inténtalo de nuevo.';
            } else {
                $error = 'Cédula/correo o contraseña incorrecta. Intentos restantes: ' . $restantes . '.';
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
        <title>OdontoNet — Iniciar sesión</title>
        <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

        <!-------------- CSS -------------->
        <link rel="preconnect" href="https://fonts.googleapis.com"> <!-- Conexión temprana con los servidores de Google Fonts -->
        <link rel="stylesheet" href="assets/css/shared/variables.css"> <!-- CSS de variables -->
        <link rel="stylesheet" href="assets/css/shared/global.css"> <!-- CSS de estilos globales -->
        <link rel="stylesheet" href="assets/css/shared/login.css"> <!-- CSS de la página de inicio de sesión (login) -->
        <link rel="stylesheet" href="assets/css/shared/layout.css"> <!-- CSS del layout (Navbar & Footer) -->
        <link rel="stylesheet" href="assets/css/shared/componentes.css"> <!-- CSS de los componentes (Botones y elementos) -->

    </head>

    <body>

        <!-- Mismos fondos decorativos que el index, para mantener la identidad visual (glow + grid) en toda la app. -->
        <div class="glow glow-a"></div>
        <div class="glow glow-b"></div>
        <div class="glow glow-c"></div>
        <div class="grid-overlay"></div>

        <!-- .auth-page centra la tarjeta de login en medio de la pantalla -->
        <main class="auth-page">

            <!-- Envoltorio con borde neón degradado -->
            <div class="login-card-glow">

                <div class="card login-card">
            
                    <!-- Logo de OdontoNet (svg) -->
                    <div class="login-logo">

                        <a href="index.php" class="logo">
                            <img src="assets/images/logos/logo-auth.svg" alt="OdontoNet" class="logo-img">
                        </a>

                    </div>
                
                    <h1>Iniciar sesión</h1>
                    <p class="login-subtitle">Accede a tu cuenta para gestionar tus citas y tu historial.</p>
                
                    <!-- Si el PHP de arriba marcó un error (login fallido),
                        se muestra este banner. htmlspecialchars() escapa
                        el texto para prevenir XSS por si el mensaje alguna
                        vez incluyera datos del usuario. -->
                    <?php if ($error): ?>
                        <div class="form-error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <?php if ($exito): ?>
                        <div class="alert alert-exito"><?= htmlspecialchars($exito) ?></div>
                    <?php endif; ?>

                    <!-- Botones de login social. Actualmente son solo visuales, quedan pendientes de conectar a un proveedor Auth real (Google/Apple) -->
                    <div class="login-socials">

                        <a href="oauth_google_redirect.php" class="btn-social btn-google">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M15.545 6.558a9.4 9.4 0 0 1 .139 1.626c0 2.434-.87 4.492-2.384 5.885h.002C11.978 15.292 10.158 16 8 16A8 8 0 1 1 8 0a7.7 7.7 0 0 1 5.352 2.082l-2.284 2.284A4.35 4.35 0 0 0 8 3.166c-2.087 0-3.86 1.408-4.492 3.304a4.8 4.8 0 0 0 0 3.063h.003c.635 1.893 2.405 3.301 4.492 3.301 1.078 0 2.004-.276 2.722-.764h-.003a3.7 3.7 0 0 0 1.599-2.431H8v-3.08z"/>
                            </svg>
                            Iniciar sesión con Google
                        </a>

                        <a href="oauth_microsoft_redirect.php" class="btn-social btn-apple">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M7.462 0H0v7.19h7.462zM16 0H8.538v7.19H16zM7.462 8.211H0V16h7.462zm8.538 0H8.538V16H16z"/>
                            </svg>
                            Iniciar sesión con Microsoft
                        </a>

                    </div>
                
                    <div class="divider">o con tu cédula</div>
                
                    <!-- action="" hace que el form se envíe a este mismo archivo, que es donde realmente vive la validación (arriba). -->
                    <form method="post" action="">

                        <!-- Viaja junto al POST para que, si el login falla y la página se recarga, no perdamos el destino original
                             (?redirect=citas.php, por ejemplo). -->
                        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
                
                        <div class="field">
                            <label for="identificador"><span class="dash-req">*</span> Cédula o correo</label>
                            <input type="text" name="identificador" id="identificador" placeholder="1-2345-6789 o tu@correo.com" required autocomplete="username">
                        </div>

                        <div class="field">
                            <label for="clave"><span class="dash-req">*</span> Contraseña</label>
                            <input type="password" name="clave" id="clave" placeholder="••••••••" required autocomplete="current-password">
                        </div>
                
                        <div class="login-forgot">
                            <a href="clave_olvidada.php" class="link">Olvidé mi contraseña</a>
                        </div>
                
                        <button class="btn-primary" type="submit">Iniciar sesión</button>
                        
                    </form>
                
                    <div class="login-footer">
                        <span class="paragraph">¿Eres nuevo?</span>
                        <a href="registro.php" class="link">Crear una cuenta</a>
                    </div>
            
                </div>

            </div>

        </main>

    </body>

</html>