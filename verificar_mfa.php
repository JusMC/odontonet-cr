<?php
/**
 * verificar_mfa.php — Verificación en dos pasos (Google/Microsoft Authenticator)
 * en el momento de iniciar sesión.
 *
 * NO es accesible manualmente: solo se llega aquí cuando login.php,
 * oauth_google_callback.php u oauth_microsoft_callback.php ya validaron la
 * identidad (contraseña u OAuth) de alguien con MFA activo, pero todavía NO
 * le crearon la sesión completa — eso solo ocurre al confirmar el código.
 */

require_once __DIR__ . '/assets/includes/session_boot.php';
require_once './assets/includes/config/config.php';
require_once './assets/includes/helpers/bitacora_helper.php';
require_once './assets/includes/config/mfa_config.php';
require_once './assets/includes/helpers/mfa_helper.php';

define('MFA_MAX_INTENTOS', 5);
define('MFA_VENTANA_MINUTOS', 15);

// Sin un login pendiente de verificar (contraseña/OAuth ya validados), no hay nada que hacer aquí.
if (!isset($_SESSION['mfa_pendiente_usuario_id'])) {
    // Si ya hay una sesión completa (por ejemplo, un envío duplicado del
    // formulario que llega después de que el primero ya verificó el código
    // y cerró el trámite pendiente), lo mandamos a su panel en vez de login,
    // para no expulsar a alguien que en realidad ya inició sesión.
    if (isset($_SESSION['usuario_id'])) {
        $destino_ya_logueado = match ((int) ($_SESSION['id_rol'] ?? 0)) {
            4 => 'admin.php',
            2, 3 => 'control_citas.php',
            default => 'inicio.php',
        };
        header('Location: ' . $destino_ya_logueado);
        exit;
    }
    header('Location: login.php');
    exit;
}

$id_usuario = (int) $_SESSION['mfa_pendiente_usuario_id'];
$destino    = $_SESSION['mfa_pendiente_destino'] ?? 'citas.php'; // A dónde ir una vez verificado.
$ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Mismo patrón de bloqueo por intentos que login.php, reutilizando la tabla
// login_intentos con un identificador propio (prefijo "mfa:") para no
// mezclar los intentos de MFA con los de login normal.
// Cuenta cuántos intentos fallidos de MFA hubo recientemente (por usuario o por IP).
function mfa_contar_intentos(PDO $pdo, string $identificador, string $ip): int {
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM login_intentos
        WHERE (identificador = :id OR ip = :ip)
          AND fecha >= DATE_SUB(NOW(), INTERVAL :ventana MINUTE)
    ');
    $stmt->execute([':id' => $identificador, ':ip' => $ip, ':ventana' => MFA_VENTANA_MINUTOS]);
    return (int) $stmt->fetchColumn();
}
// Registra un intento fallido nuevo.
function mfa_registrar_intento(PDO $pdo, string $identificador, string $ip): void {
    $pdo->prepare('INSERT INTO login_intentos (identificador, ip) VALUES (:id, :ip)')
        ->execute([':id' => $identificador, ':ip' => $ip]);
}
// Borra el historial de intentos fallidos (se usa cuando el código sí es correcto).
function mfa_limpiar_intentos(PDO $pdo, string $identificador, string $ip): void {
    $pdo->prepare('DELETE FROM login_intentos WHERE identificador = :id OR ip = :ip')
        ->execute([':id' => $identificador, ':ip' => $ip]);
}

$identificador_intentos = 'mfa:' . $id_usuario;
$error = '';
$exito = false;

// Si el usuario envió el formulario con su código...
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $intentos_actuales = mfa_contar_intentos($pdo, $identificador_intentos, $ip);

    if ($intentos_actuales >= MFA_MAX_INTENTOS) {
        $error = 'Demasiados intentos fallidos. Espera ' . MFA_VENTANA_MINUTOS . ' minutos e inténtalo de nuevo.';
    } else {
        // El usuario puede verificar con la app autenticadora (totp) o con un código de respaldo.
        $modo = ($_POST['modo'] ?? 'totp') === 'respaldo' ? 'respaldo' : 'totp';

        if ($modo === 'respaldo') {
            $valido = mfa_verificar_codigo_respaldo($pdo, $id_usuario, $_POST['codigo_respaldo'] ?? '');
        } else {
            // El usuario puede tener varias apps registradas: se prueba el
            // código contra cada secreto confirmado hasta encontrar uno que
            // coincida, sin necesidad de preguntarle cuál app está usando.
            $stmt = $pdo->prepare('SELECT secreto_cifrado FROM mfa_totp WHERE id_usuario = :id AND confirmado = 1');
            $stmt->execute(['id' => $id_usuario]);
            $valido = false;
            foreach ($stmt->fetchAll() as $fila_secreto) {
                if (mfa_verificar_codigo(mfa_descifrar_secreto($fila_secreto['secreto_cifrado']), $_POST['codigo'] ?? '')) {
                    $valido = true;
                    break;
                }
            }
        }

        if ($valido) {
            // Código correcto: ahora sí se crea la sesión completa del usuario.
            $exito = true;
            mfa_limpiar_intentos($pdo, $identificador_intentos, $ip);
            mfa_finalizar_sesion($pdo, $id_usuario);
            registrar_bitacora($pdo, 'LOGIN', "Verificación en dos pasos exitosa para el usuario #$id_usuario" . ($modo === 'respaldo' ? ' (con código de respaldo).' : '.'), $id_usuario);
            unset($_SESSION['mfa_pendiente_usuario_id'], $_SESSION['mfa_pendiente_destino']);
        } else {
            mfa_registrar_intento($pdo, $identificador_intentos, $ip);
            $error = $modo === 'respaldo' ? 'Código de respaldo incorrecto o ya usado.' : 'Código incorrecto. Verifica los 6 dígitos e inténtalo de nuevo.';
        }
    }
}

$digitos_previos = str_split(preg_replace('/[^0-9]/', '', $_POST['codigo'] ?? ''));
?>

<!DOCTYPE html>
<html lang="es">

    <head>

        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>OdontoNet — Verificación en dos pasos</title>
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

                    <h1 id="mfaTitulo">Verificación en dos pasos</h1>
                    <p class="reset-subtitle" id="mfaSubtitulo">Abre tu app autenticadora (Google Authenticator, Microsoft Authenticator o similar) y escribe el código de 6 dígitos.</p>

                    <form method="post" action="" id="formMfa" novalidate>
                        <input type="hidden" name="modo" id="mfaModo" value="totp">

                        <div id="bloqueTotp">
                            <div class="token-input-group" id="tokenGroup">
                                <?php for ($i = 0; $i < 6; $i++): ?>
                                    <input type="text" class="token-box" inputmode="numeric"<?= $i === 0 ? ' autocomplete="one-time-code"' : '' ?> maxlength="1" data-index="<?= $i ?>" value="<?= htmlspecialchars($digitos_previos[$i] ?? '') ?>">
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="codigo" id="codigoCompleto">
                        </div>

                        <div id="bloqueRespaldo" class="hidden">
                            <div class="field">
                                <label for="codigo_respaldo">Código de respaldo</label>
                                <input type="text" id="codigo_respaldo" name="codigo_respaldo" placeholder="0000-0000" autocomplete="off">
                            </div>
                        </div>

                        <p class="token-status<?= $error ? ' is-error' : ($exito ? ' is-success' : '') ?>" id="tokenStatus">
                            <?= $exito ? '¡Código correcto! Redirigiendo…' : htmlspecialchars($error) ?>
                        </p>

                        <button class="btn-primary" type="submit" id="btnVerificarMfa" style="width:100%; justify-content:center;">Verificar</button>
                    </form>

                    <div class="reset-links">
                        <a href="#" class="link" id="linkAlternarModo">Usar un código de respaldo</a>
                        <a href="logout.php" class="link">Cancelar e iniciar sesión de nuevo</a>
                    </div>

                </div>

            </div>

        </main>

        <script>
            // Controla las 6 casillas del código TOTP, y el botón para cambiar entre "app autenticadora"
            // y "código de respaldo" (que usa un solo campo de texto en vez de las 6 casillas).
            (function () {
                const form = document.getElementById('formMfa');
                const campoModo = document.getElementById('mfaModo');
                const bloqueTotp = document.getElementById('bloqueTotp');
                const bloqueRespaldo = document.getElementById('bloqueRespaldo');
                const linkAlternar = document.getElementById('linkAlternarModo');
                const titulo = document.getElementById('mfaTitulo');
                const subtitulo = document.getElementById('mfaSubtitulo');

                const grupo = document.getElementById('tokenGroup');
                const cajas = Array.from(grupo.querySelectorAll('.token-box'));
                const campoOculto = document.getElementById('codigoCompleto');
                const estado = document.getElementById('tokenStatus');

                const huboError = <?= json_encode((bool) $error) ?>;
                const huboExito = <?= json_encode($exito) ?>;
                const modoServidor = <?= json_encode($_POST['modo'] ?? 'totp') ?>;

                function enfocarPrimeraVacia() {
                    const vacia = cajas.find(function (c) { return c.value === ''; });
                    (vacia || cajas[0]).focus();
                }
                function actualizarCampoOculto() {
                    campoOculto.value = cajas.map(function (c) { return c.value; }).join('');
                }
                let yaEnviado = false; // Evita que el mismo código se envíe dos veces (ej: autocompletado del sistema operativo disparando varios eventos).
                function enviarSiCompleto() {
                    if (!yaEnviado && cajas.every(function (c) { return c.value !== ''; })) {
                        yaEnviado = true;
                        actualizarCampoOculto();
                        form.requestSubmit();
                    }
                }

                cajas.forEach(function (caja, indice) {
                    caja.addEventListener('input', function () {
                        caja.value = caja.value.replace(/[^0-9]/g, '').slice(0, 1);
                        if (caja.value !== '' && indice < cajas.length - 1) cajas[indice + 1].focus();
                        enviarSiCompleto();
                    });
                    caja.addEventListener('keydown', function (e) {
                        if (e.key === 'Backspace' && caja.value === '' && indice > 0) cajas[indice - 1].focus();
                    });
                    caja.addEventListener('paste', function (e) {
                        const texto = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                        if (texto.length >= 1) {
                            e.preventDefault();
                            texto.slice(0, cajas.length).split('').forEach(function (d, i) { if (cajas[i]) cajas[i].value = d; });
                            enfocarPrimeraVacia();
                            enviarSiCompleto();
                        }
                    });
                });

                form.addEventListener('submit', function () {
                    if (campoModo.value === 'totp') actualizarCampoOculto();
                });

                function activarModoRespaldo() {
                    campoModo.value = 'respaldo';
                    bloqueTotp.classList.add('hidden');
                    bloqueRespaldo.classList.remove('hidden');
                    titulo.textContent = 'Código de respaldo';
                    subtitulo.textContent = 'Escribe uno de los códigos de respaldo que guardaste al activar la verificación en dos pasos.';
                    linkAlternar.textContent = 'Usar la app autenticadora';
                    document.getElementById('codigo_respaldo').focus();
                }
                function activarModoTotp() {
                    campoModo.value = 'totp';
                    bloqueRespaldo.classList.add('hidden');
                    bloqueTotp.classList.remove('hidden');
                    titulo.textContent = 'Verificación en dos pasos';
                    subtitulo.textContent = 'Abre tu app autenticadora (Google Authenticator, Microsoft Authenticator o similar) y escribe el código de 6 dígitos.';
                    linkAlternar.textContent = 'Usar un código de respaldo';
                    enfocarPrimeraVacia();
                }

                linkAlternar.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (campoModo.value === 'totp') activarModoRespaldo();
                    else activarModoTotp();
                });

                if (modoServidor === 'respaldo') activarModoRespaldo();

                if (huboExito) {
                    if (modoServidor !== 'respaldo') {
                        cajas.forEach(function (c) { c.disabled = true; });
                        grupo.classList.add('is-success');
                    }
                    setTimeout(function () {
                        window.location.href = <?= json_encode($destino) ?>;
                    }, 950);
                } else if (huboError && modoServidor !== 'respaldo') {
                    grupo.classList.add('is-error');
                    setTimeout(function () {
                        grupo.classList.remove('is-error');
                        cajas.forEach(function (c) { c.value = ''; });
                        actualizarCampoOculto();
                        enfocarPrimeraVacia();
                    }, 480);
                } else if (modoServidor !== 'respaldo') {
                    enfocarPrimeraVacia();
                }
            })();
        </script>

    </body>

</html>
