<?php
// oauth_config.php — Credenciales y constantes de Google/Microsoft OAuth.
// Este archivo SÍ se versiona: no contiene ningún secreto real.
//
// En local, si existe oauth_config.local.php (no versionado, con los valores
// reales), se usa ese. En producción (ej. Vercel), los valores se toman de
// variables de entorno: GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET,
// GOOGLE_REDIRECT_URI, MS_CLIENT_ID, MS_CLIENT_SECRET, MS_TENANT_ID,
// MS_REDIRECT_URI.

require_once __DIR__ . '/env_helper.php';

$oauth_local = __DIR__ . '/oauth_config.local.php';

if (getenv('GOOGLE_CLIENT_ID') === false && file_exists($oauth_local)) {
    require_once $oauth_local;
} else {
    // Identifica esta aplicación ante Google. No es secreto, Google lo pide para saber quién pregunta.
    define('GOOGLE_CLIENT_ID', config_env('GOOGLE_CLIENT_ID', ''));
    // Esta sí es secreta: junto con el Client ID, prueba que el que llama a Google es realmente este servidor.
    define('GOOGLE_CLIENT_SECRET', config_env('GOOGLE_CLIENT_SECRET', ''));
    // Página a la que Google devuelve al usuario después de que inicia sesión con su cuenta de Google.
    define('GOOGLE_REDIRECT_URI', config_env('GOOGLE_REDIRECT_URI', ''));

    // Scopes: solo pedimos email y perfil básico.
    define('GOOGLE_SCOPES', implode(' ', [
        'openid',   // Confirma la identidad del usuario.
        'email',    // Pedimos su correo.
        'profile',  // Pedimos su nombre y foto básicos.
    ]));

    // ── Microsoft OAuth ────────────────────────────────────────────────────
    define('MS_CLIENT_ID', config_env('MS_CLIENT_ID', ''));
    define('MS_CLIENT_SECRET', config_env('MS_CLIENT_SECRET', ''));
    // Identifica el "tenant" (la organización) de Microsoft donde está registrada esta aplicación.
    define('MS_TENANT_ID', config_env('MS_TENANT_ID', ''));
    // Página a la que Microsoft devuelve al usuario después de iniciar sesión.
    define('MS_REDIRECT_URI', config_env('MS_REDIRECT_URI', ''));

    define('MS_SCOPES', implode(' ', [
        'openid',    // Confirma la identidad del usuario.
        'email',     // Pedimos su correo.
        'profile',   // Pedimos su nombre y foto básicos.
        'User.Read', // Permiso extra que pide Microsoft para leer datos básicos del perfil.
    ]));
}
