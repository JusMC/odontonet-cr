<?php
// oauth_google_redirect.php — Inicia el flujo OAuth redirigiendo a Google.

session_start();

require_once 'assets/includes/config/oauth_config.php';

// state: token aleatorio que Google nos devolverá en el callback.
// Lo guardamos en sesión para verificar que la respuesta es legítima
// y no un ataque CSRF de terceros.
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$params = http_build_query([
    'client_id'             => GOOGLE_CLIENT_ID,
    'redirect_uri'          => GOOGLE_REDIRECT_URI,
    'response_type'         => 'code',
    'scope'                 => GOOGLE_SCOPES,
    'state'                 => $state,
    'access_type'           => 'online',
    'prompt'                => 'select_account', // Fuerza selección de cuenta aunque ya haya sesión Google activa
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;