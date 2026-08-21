<?php
// mfa_config.php — Clave de cifrado para los secretos TOTP (Google/Microsoft
// Authenticator) guardados en la tabla mfa_totp.
// Este archivo SÍ se versiona: no contiene ningún secreto real.
//
// En local, si existe mfa_config.local.php (no versionado, con el valor real),
// se usa ese. En producción (ej. Vercel), el valor se toma de la variable de
// entorno MFA_ENCRYPTION_KEY.
//
// A diferencia de una contraseña, el secreto TOTP de cada usuario se cifra
// (no se hashea) porque el servidor necesita poder leerlo de vuelta para
// calcular y comparar códigos en cada login. Esta clave es la que protege
// esos secretos si la base de datos llegara a filtrarse — se genera una vez
// (ej. con sodium_crypto_secretbox_keygen()) y NUNCA debe cambiarse después
// de que exista al menos un usuario con MFA activo (si cambia, todos los
// secretos ya guardados quedan ilegibles y esos usuarios pierden el acceso
// por MFA hasta que lo reconfiguren).

require_once __DIR__ . '/env_helper.php';

$mfa_local = __DIR__ . '/mfa_config.local.php';

if (getenv('MFA_ENCRYPTION_KEY') === false && file_exists($mfa_local)) {
    require_once $mfa_local;
} else {
    define('MFA_ENCRYPTION_KEY', config_env('MFA_ENCRYPTION_KEY', ''));
}
