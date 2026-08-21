<?php
// env_helper.php — Lee una variable de entorno probando las tres formas en que
// PHP puede exponerla (getenv(), $_ENV, $_SERVER). Según el servidor/runtime, no
// siempre se llenan las tres, así que se revisan todas antes de rendirse y usar
// el valor por defecto. Compartido por config.php, oauth_config.php, mfa_config.php,
// mail_config.php y stripe_config.php — se define una sola vez para evitar el
// error fatal de PHP al redeclarar la misma función desde dos archivos distintos.

if (!function_exists('config_env')) {
    function config_env(string $nombre, string $por_defecto): string {
        $valor = getenv($nombre);
        if ($valor !== false && $valor !== '') {
            return $valor;
        }
        if (!empty($_ENV[$nombre])) {
            return $_ENV[$nombre];
        }
        if (!empty($_SERVER[$nombre])) {
            return $_SERVER[$nombre];
        }
        return $por_defecto;
    }
}
