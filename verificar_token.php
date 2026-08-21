<?php
/**
 * verificar_token.php — Paso 2 del flujo "Olvidé mi contraseña".
 *
 * NO es accesible manualmente: solo se llega aquí con el estado de sesión
 * que deja clave_olvidada.php al enviar el código con éxito. Cualquier
 * visita sin ese estado se redirige de vuelta al paso 1.
 */

require_once __DIR__ . '/assets/includes/session_boot.php';
require_once './assets/includes/config/config.php';
require_once './assets/includes/helpers/bitacora_helper.php';

define('RESET_MAX_INTENTOS', 5);

// Guard: sin una solicitud de reset activa en sesión, no hay nada que verificar.
if (!isset($_SESSION['reset_id_usuario'], $_SESSION['reset_id_reset'])) {
    header('Location: clave_olvidada.php');
    exit;
}

// Si ya se verificó el código en esta misma sesión, seguir para adelante
// en vez de mandarlo de regreso al paso 1.
if (($_SESSION['reset_paso'] ?? '') === 'password') {
    header('Location: restablecer_contrasena.php');
    exit;
}

if (($_SESSION['reset_paso'] ?? '') !== 'token') {
    header('Location: clave_olvidada.php');
    exit;
}

// Buscamos la solicitud de recuperación activa para este usuario.
$stmt = $pdo->prepare('SELECT * FROM password_resets WHERE id_reset = :id AND id_usuario = :id_usuario LIMIT 1');
$stmt->execute(['id' => $_SESSION['reset_id_reset'], 'id_usuario' => $_SESSION['reset_id_usuario']]);
$reset = $stmt->fetch();

// Está vencido si no existe, ya se usó, o pasó la fecha de expiración.
$expirado = !$reset || (bool) $reset['usado'] || strtotime($reset['expira_en']) < time();

$error = '';
$exito = false;

// Si no está vencido y el usuario envió el código...
if (!$expirado && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo_ingresado = trim($_POST['codigo'] ?? '');

    if (preg_match('/^[0-9]{6}$/', $codigo_ingresado) && password_verify($codigo_ingresado, $reset['codigo_hash'])) {
        // El código coincide: avanzamos al siguiente paso del flujo.
        $exito = true;
        $_SESSION['reset_paso'] = 'password';
        registrar_bitacora($pdo, 'UPDATE', "Código de recuperación verificado correctamente para el usuario #{$_SESSION['reset_id_usuario']}.", (int) $_SESSION['reset_id_usuario']);
    } else {
        // Código incorrecto: sumamos un intento fallido más.
        $nuevos_intentos = (int) $reset['intentos'] + 1;

        if ($nuevos_intentos >= RESET_MAX_INTENTOS) {
            // Se agotaron los intentos: invalidamos el código por completo.
            $pdo->prepare('UPDATE password_resets SET intentos = :i, usado = 1 WHERE id_reset = :id')
                ->execute(['i' => $nuevos_intentos, 'id' => $reset['id_reset']]);
            $expirado = true;
            $error = 'Superaste el número máximo de intentos. Solicita un nuevo código.';
        } else {
            $pdo->prepare('UPDATE password_resets SET intentos = :i WHERE id_reset = :id')
                ->execute(['i' => $nuevos_intentos, 'id' => $reset['id_reset']]);
            $error = 'Código incorrecto. Verifica los 6 dígitos e inténtalo de nuevo.';
        }
    }
}

// Enmascara el correo para mostrarlo sin revelarlo por completo (ej. j***n@gmail.com).
function enmascarar_correo(string $correo): string {
    $partes = explode('@', $correo, 2); // Separamos "usuario" de "dominio.com".
    if (count($partes) !== 2) {
        return $correo;
    }
    [$usuario, $dominio] = $partes;
    $largo = mb_strlen($usuario);
    if ($largo <= 2) {
        // Nombres muy cortos: los ocultamos por completo.
        $visible = str_repeat('*', $largo);
    } else {
        // Mostramos solo la primera y la última letra, el resto con asteriscos.
        $visible = mb_substr($usuario, 0, 1) . str_repeat('*', $largo - 2) . mb_substr($usuario, -1, 1);
    }
    return $visible . '@' . $dominio;
}

// Cuántos segundos le quedan al código antes de vencer (para el contador visual de la página).
$segundos_restantes = $expirado ? 0 : max(0, strtotime($reset['expira_en']) - time());

// Para que, tras el POST, las casillas conserven visualmente los dígitos
// que la persona acaba de escribir (y la animación de éxito/error se vea
// sobre esos dígitos en vez de sobre casillas vacías).
$digitos_previos = str_split(preg_replace('/[^0-9]/', '', $_POST['codigo'] ?? ''));
?>

<!DOCTYPE html>
<html lang="es">

    <head>

        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>OdontoNet — Verificar código</title>
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

                    <?php if ($expirado): ?>

                        <h1>Código vencido</h1>
                        <div class="reset-expirado">
                            <p><?= $error ? htmlspecialchars($error) : 'Tu código de verificación venció o ya no es válido. Solicita uno nuevo para continuar.' ?></p>
                            <a href="clave_olvidada.php" class="btn-primary" style="display:inline-flex;">Solicitar un nuevo código</a>
                        </div>

                    <?php else: ?>

                        <h1>Verifica tu código</h1>
                        <p class="reset-subtitle">
                            Enviamos un código de 6 dígitos a
                            <span class="reset-correo-destacado"><?= htmlspecialchars(enmascarar_correo($_SESSION['reset_correo'] ?? '')) ?></span>.
                        </p>

                        <form method="post" action="" id="formToken" novalidate>
                            <input type="hidden" name="codigo" id="codigoCompleto">

                            <div class="token-input-group" id="tokenGroup">
                                <?php for ($i = 0; $i < 6; $i++): ?>
                                    <input type="text" class="token-box" inputmode="numeric"<?= $i === 0 ? ' autocomplete="one-time-code"' : '' ?> maxlength="1" data-index="<?= $i ?>" value="<?= htmlspecialchars($digitos_previos[$i] ?? '') ?>">
                                <?php endfor; ?>
                            </div>

                            <p class="token-status<?= $error ? ' is-error' : ($exito ? ' is-success' : '') ?>" id="tokenStatus">
                                <?= $exito ? '¡Código correcto! Redirigiendo…' : htmlspecialchars($error) ?>
                            </p>

                            <p class="token-countdown" id="tokenCountdown">
                                Vence en <strong id="tokenCountdownValor">15:00</strong>
                            </p>

                            <button class="btn-primary" type="submit" style="width:100%; justify-content:center;">Verificar código</button>
                        </form>

                        <div class="reset-links">
                            <a href="clave_olvidada.php" class="link">¿No te llegó? Solicitar un nuevo código</a>
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </main>

        <?php if (!$expirado): ?>
        <script>
            // Controla las 6 casillas del código: mueve el foco automáticamente entre ellas,
            // permite pegar el código completo de una vez, y envía el formulario solo cuando las 6 están llenas.
            (function () {
                const grupo = document.getElementById('tokenGroup');
                const cajas = Array.from(grupo.querySelectorAll('.token-box'));
                const campoOculto = document.getElementById('codigoCompleto');
                const form = document.getElementById('formToken');
                const estado = document.getElementById('tokenStatus');

                const huboError = <?= json_encode((bool) $error) ?>;
                const huboExito = <?= json_encode($exito) ?>;
                let segundosRestantes = <?= (int) $segundos_restantes ?>;

                function enfocarPrimeraVacia() {
                    const vacia = cajas.find(function (c) { return c.value === ''; });
                    (vacia || cajas[0]).focus();
                }

                function actualizarCampoOculto() {
                    campoOculto.value = cajas.map(function (c) { return c.value; }).join('');
                }

                function enviarSiCompleto() {
                    if (cajas.every(function (c) { return c.value !== ''; })) {
                        actualizarCampoOculto();
                        form.requestSubmit();
                    }
                }

                cajas.forEach(function (caja, indice) {
                    caja.addEventListener('input', function () {
                        caja.value = caja.value.replace(/[^0-9]/g, '').slice(0, 1);
                        if (caja.value !== '' && indice < cajas.length - 1) {
                            cajas[indice + 1].focus();
                        }
                        enviarSiCompleto();
                    });

                    caja.addEventListener('keydown', function (e) {
                        if (e.key === 'Backspace' && caja.value === '' && indice > 0) {
                            cajas[indice - 1].focus();
                        }
                    });

                    caja.addEventListener('paste', function (e) {
                        const texto = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                        if (texto.length >= 1) {
                            e.preventDefault();
                            texto.slice(0, cajas.length).split('').forEach(function (digito, i) {
                                if (cajas[i]) cajas[i].value = digito;
                            });
                            enfocarPrimeraVacia();
                            enviarSiCompleto();
                        }
                    });
                });

                form.addEventListener('submit', function () {
                    actualizarCampoOculto();
                });

                if (huboExito) {
                    cajas.forEach(function (c) { c.disabled = true; });
                    grupo.classList.add('is-success');
                    setTimeout(function () {
                        window.location.href = 'restablecer_contrasena.php';
                    }, 950);
                } else if (huboError) {
                    grupo.classList.add('is-error');
                    setTimeout(function () {
                        grupo.classList.remove('is-error');
                        cajas.forEach(function (c) { c.value = ''; });
                        actualizarCampoOculto();
                        enfocarPrimeraVacia();
                    }, 480);
                    enfocarPrimeraVacia();
                } else {
                    enfocarPrimeraVacia();
                }

                // Cuenta regresiva puramente visual — la validación real de
                // expiración siempre ocurre en el servidor. Al llegar a 0
                // se recarga la página para que el servidor confirme el
                // vencimiento y muestre la pantalla de "código vencido".
                const valorCountdown = document.getElementById('tokenCountdownValor');
                const intervalo = setInterval(function () {
                    segundosRestantes--;
                    if (segundosRestantes <= 0) {
                        clearInterval(intervalo);
                        window.location.reload();
                        return;
                    }
                    const min = Math.floor(segundosRestantes / 60);
                    const seg = segundosRestantes % 60;
                    valorCountdown.textContent = min + ':' + String(seg).padStart(2, '0');
                }, 1000);
            })();
        </script>
        <?php endif; ?>

    </body>

</html>
