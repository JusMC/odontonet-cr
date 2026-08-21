<?php
// oauth_microsoft_redirect.php — Inicia el flujo OAuth con Microsoft.

session_start();

require_once 'assets/includes/config/oauth_config.php';

// state: token aleatorio para verificar que la respuesta es legítima (anti-CSRF).
$state = bin2hex(random_bytes(16));
$_SESSION['ms_oauth_state'] = $state;

$params = http_build_query([
    'client_id'     => MS_CLIENT_ID,
    'redirect_uri'  => MS_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => MS_SCOPES,
    'state'         => $state,
    'prompt'        => 'select_account', // Fuerza selección de cuenta
]);

$url = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' . $params;

header('Location: ' . $url);
exit;