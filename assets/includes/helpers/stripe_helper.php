<?php
/**
 * stripe_helper.php — Llamada genérica a la API REST de Stripe (modo test).
 *
 * Requiere que stripe_config.php ya haya sido incluido antes (usa STRIPE_SECRET_KEY).
 *
 * Uso:
 *   require_once './assets/includes/config/stripe_config.php';
 *   require_once './assets/includes/helpers/stripe_helper.php';
 *   $session = stripe_api_call('checkout/sessions', $params, 'POST');
 */

function stripe_api_call(string $endpoint, array $params = [], string $method = 'POST'): array
{
    // Armamos la dirección completa a la que le vamos a hablar (ej: https://api.stripe.com/v1/checkout/sessions).
    $url = "https://api.stripe.com/v1/$endpoint";

    // Opciones de la llamada: que nos devuelva la respuesta como texto, el método (POST/GET) y
    // la llave secreta como "usuario" (así es como Stripe pide que te identifiques).
    $opciones_curl = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
    ];

    if ($method === 'GET') {
        // En un GET los parámetros van pegados a la URL (ej: ?param1=x&param2=y).
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
    } else {
        // En POST (o similar) los parámetros van en el cuerpo de la petición.
        $opciones_curl[CURLOPT_POSTFIELDS] = http_build_query($params);
    }

    // Abrimos la conexión, aplicamos las opciones y hacemos la llamada real a Stripe.
    $ch = curl_init($url);
    curl_setopt_array($ch, $opciones_curl);
    $respuesta = curl_exec($ch);

    // Si curl_exec devuelve false, es que ni siquiera se pudo conectar con Stripe.
    if ($respuesta === false) {
        $error_curl = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Error de conexión con Stripe: ' . $error_curl);
    }

    // Guardamos el código de estado HTTP (200 = éxito, 400+ = error) antes de cerrar la conexión.
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Convertimos la respuesta (que viene en formato JSON) a un array de PHP normal.
    $data = json_decode($respuesta, true);
    if ($http_code >= 400) {
        // Stripe respondió con un error: lanzamos una excepción con su mensaje.
        throw new RuntimeException($data['error']['message'] ?? 'Error al comunicarse con Stripe.');
    }
    return $data; // Todo bien: devolvemos los datos que mandó Stripe.
}
