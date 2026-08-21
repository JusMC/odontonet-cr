<?php
/*
    pago_cancelado.php

    Stripe redirige aquí (cancel_url, ver stripe_config.php) cuando el
    cliente cancela el pago con tarjeta desde la pantalla de Checkout,
    o cierra esa pestaña sin completar el pago.

    No se toca la base de datos: el carrito sigue intacto en sesión
    (compra.php nunca lo vacía hasta que el pago se confirma), así que el
    paciente puede volver a intentar el pago sin perder lo que tenía
    seleccionado.
*/

require_once __DIR__ . '/assets/includes/session_boot.php';
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';

// Esta pantalla es compartida entre el pago con tarjeta de la tienda
// (compra.php) y el de facturación de servicios (facturar_servicios.php);
// el mensaje y los enlaces cambian según de dónde venía el intento de pago.
$es_facturacion = isset($_SESSION['pago_pendiente_servicio']);

$year = date("Y");
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OdontoNet — Pago cancelado</title>

    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/compra_pago_cancelado.css">
    <link rel="stylesheet" href="assets/css/pages/pago_cancelado.css">
</head>

<body>

    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>
    <div class="glow glow-c"></div>
    <div class="grid-overlay"></div>

    <!-- NAVBAR -->
    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <main class="wrap tienda-main">

        <section class="tienda-hero">
            <span class="eyebrow">Pago con tarjeta</span>
            <h1>Pago cancelado</h1>
            <?php if ($es_facturacion): ?>
                <p>
                    No se completó el pago con tarjeta y no se realizó ningún cargo.
                    La factura no se registró; puedes volver a intentarlo o elegir otro método de pago.
                </p>
            <?php else: ?>
                <p>
                    No se completó el pago con tarjeta y no se realizó ningún cargo.
                    Los productos siguen guardados en tu carrito, puedes volver a intentarlo
                    cuando quieras o elegir otro método de pago.
                </p>
            <?php endif; ?>
        </section>

        <section class="compra-box">
            <h2>¿Qué quieres hacer ahora?</h2>
            <?php if ($es_facturacion): ?>
                <p>No se registró ningún cambio; puedes rehacer la factura cuando quieras.</p>
                <div style="display:flex; flex-wrap:wrap; gap:14px;">
                    <a href="facturar_servicios.php" class="btn-primary">
                        Volver a facturar
                    </a>
                </div>
            <?php else: ?>
                <p>Tu carrito no se modificó, así que no perdiste los productos seleccionados.</p>
                <div style="display:flex; flex-wrap:wrap; gap:14px;">
                    <a href="compra.php" class="btn-primary">
                        Volver a intentar el pago
                    </a>
                    <a href="tienda.php" class="btn-ghost">
                        Volver a la tienda
                    </a>
                </div>
            <?php endif; ?>
        </section>

    </main>

    <!-- Footer -->
    <?php require_once 'assets/includes/partials/footer.php'; ?>

</body>
</html>
