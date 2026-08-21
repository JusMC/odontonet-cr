<?php
// stripe_config.php — Credenciales de Stripe (modo TEST).
// Este archivo SÍ se versiona: no contiene ningún secreto real.
//
// En local, si existe stripe_config.local.php (no versionado, con los valores
// reales), se usa ese. En producción (ej. Vercel), los valores se toman de
// variables de entorno: STRIPE_SECRET_KEY, STRIPE_PUBLISHABLE_KEY,
// STRIPE_CURRENCY, STRIPE_SUCCESS_URL, STRIPE_CANCEL_URL,
// STRIPE_SUCCESS_URL_SERVICIOS.

require_once __DIR__ . '/env_helper.php';

$stripe_local = __DIR__ . '/stripe_config.local.php';

if (getenv('STRIPE_SECRET_KEY') === false && file_exists($stripe_local)) {
    require_once $stripe_local;
} else {
    // Llave secreta: la usa el servidor para hablar con Stripe. Es privada, nunca debe verla el navegador.
    define('STRIPE_SECRET_KEY', config_env('STRIPE_SECRET_KEY', ''));
    // Llave pública: esta sí se puede mostrar en el navegador, Stripe la usa para el formulario de pago.
    define('STRIPE_PUBLISHABLE_KEY', config_env('STRIPE_PUBLISHABLE_KEY', ''));

    // Moneda para Checkout. CRC es soportada por Stripe.
    define('STRIPE_CURRENCY', config_env('STRIPE_CURRENCY', 'crc'));

    // A dónde manda Stripe al cliente cuando el pago de la tienda sale bien / si lo cancela.
    define('STRIPE_SUCCESS_URL', config_env('STRIPE_SUCCESS_URL', ''));
    define('STRIPE_CANCEL_URL', config_env('STRIPE_CANCEL_URL', ''));

    // Pago con tarjeta de una factura de servicios (facturar_servicios.php),
    // distinto del flujo de la tienda: usa su propio success_url porque la
    // reconciliación es diferente (ventas de tipo 'servicio', promociones,
    // pagos parciales) — ver pago_exito_servicios.php.
    define('STRIPE_SUCCESS_URL_SERVICIOS', config_env('STRIPE_SUCCESS_URL_SERVICIOS', ''));
}
