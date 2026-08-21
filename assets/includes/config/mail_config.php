<?php
// mail_config.php — Credenciales SMTP para el envío de correos (recuperación de contraseña, etc.).
// Este archivo SÍ se versiona: no contiene ningún secreto real.
//
// En local, si existe mail_config.local.php (no versionado, con los valores
// reales), se usa ese. En producción (ej. Vercel), los valores se toman de
// variables de entorno: MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION, MAIL_USERNAME,
// MAIL_PASSWORD, MAIL_FROM_NAME.
//
// MAIL_PASSWORD debe ser una "contraseña de aplicación" de Google si se usa
// Gmail (requiere verificación en dos pasos activada en la cuenta) — no la
// contraseña normal de la cuenta, esa no funcionará.

require_once __DIR__ . '/env_helper.php';

$mail_local = __DIR__ . '/mail_config.local.php';

if (getenv('MAIL_USERNAME') === false && file_exists($mail_local)) {
    require_once $mail_local;
} else {
    define('MAIL_HOST', config_env('MAIL_HOST', 'smtp.gmail.com'));
    define('MAIL_PORT', (int) config_env('MAIL_PORT', '587'));
    define('MAIL_ENCRYPTION', config_env('MAIL_ENCRYPTION', 'tls'));
    define('MAIL_USERNAME', config_env('MAIL_USERNAME', ''));
    define('MAIL_PASSWORD', config_env('MAIL_PASSWORD', ''));

    // Remitente que verá el usuario al recibir el correo.
    define('MAIL_FROM_ADDRESS', MAIL_USERNAME); // Usamos la misma cuenta como remitente.
    define('MAIL_FROM_NAME', config_env('MAIL_FROM_NAME', 'OdontoNet'));
}
