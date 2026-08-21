<?php
// api/index.php — Front controller único para Vercel.
//
// Vercel solo permite funciones serverless dentro de /api, y el plan gratuito
// tiene un límite bajo de funciones por deployment. En vez de mover las ~40
// páginas de OdontoNet a /api (superando ese límite), este único archivo
// intercepta toda la navegación (ver vercel.json) y ejecuta el archivo real
// correspondiente, que sigue viviendo en su ubicación original del proyecto,
// tal como corre en un servidor tradicional (Apache/Laragon).

$raiz_proyecto = realpath(__DIR__ . '/..');

$ruta_uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($ruta_uri === '' || $ruta_uri === '/') {
    $ruta_uri = '/index.php';
}
$ruta_uri = str_replace('\\', '/', $ruta_uri);

// Solo se permite ejecutar archivos .php que existan realmente dentro del
// proyecto (protege contra path traversal con realpath + comparación de prefijo).
$ruta_completa = realpath($raiz_proyecto . $ruta_uri);

$es_valido = $ruta_completa !== false
    && strpos($ruta_completa, $raiz_proyecto . DIRECTORY_SEPARATOR) === 0
    && is_file($ruta_completa)
    && strtolower(pathinfo($ruta_completa, PATHINFO_EXTENSION)) === 'php';

if (!$es_valido) {
    http_response_code(404);
    echo '404 - Página no encontrada';
    exit;
}

// Igualamos el directorio de trabajo al de la propia página, igual que en un
// servidor tradicional, para que sus require_once('./algo') o require_once('algo')
// relativos se resuelvan exactamente igual que en local.
chdir(dirname($ruta_completa));
require $ruta_completa;
