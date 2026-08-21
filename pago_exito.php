<?php
/*
    pago_exito.php

    Stripe redirige aquí (success_url, ver stripe_config.php) después de que
    el cliente completa el pago con tarjeta en Checkout. Este archivo:

    1. Verifica con la API de Stripe que la sesión de pago realmente se
       completó (nunca confía en el simple hecho de haber sido redirigido
       aquí — eso se puede falsificar visitando la URL directamente).
    2. Reutiliza el mismo descuento atómico de stock que compra.php usa
       para efectivo/transferencia, para no vender productos vencidos,
       inactivos o sin stock suficiente aunque hayan cambiado mientras el
       cliente estaba en la pantalla de Stripe.
    3. Registra la venta y su detalle, igual que el resto de métodos de pago.
    4. Vacía el carrito y redirige a la factura digital.
*/

require_once __DIR__ . '/assets/includes/session_boot.php';
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/bitacora_helper.php';
require_once './assets/includes/config/stripe_config.php';
require_once './assets/includes/helpers/stripe_helper.php'; // función stripe_api_call()

// Necesitamos sesión de paciente y el session_id que Stripe agrega a la URL de retorno.
if (!isset($_SESSION["usuario_id"], $_SESSION["paciente_id"], $_GET['session_id'])) {
    header("Location: login.php");
    exit;
}

try {
    // Le preguntamos directamente a Stripe por esa sesión de pago (no confiamos solo en la redirección).
    $session = stripe_api_call('checkout/sessions/' . urlencode($_GET['session_id']), [], 'GET');

    if ($session['payment_status'] !== 'paid') {
        die("El pago no se completó. Si el problema persiste, contacta soporte.");
    }

    if (empty($_SESSION["carrito"])) {
        // Si ya no hay carrito, es que esta venta ya se procesó antes (o alguien repitió la URL).
        die("Esta sesión de pago ya fue procesada o el carrito ya no existe.");
    }

    // Mismo stored procedure que usa compra.php para efectivo/transferencia:
    // reconcilia precio/stock/vigencia de cada producto, descuenta stock de
    // forma atómica y crea la venta + detalle, con su propia transacción y
    // rollback si cualquier artículo del carrito falla.
    // Convertimos el carrito (guardado en sesión) al formato JSON que espera el procedimiento almacenado.
    $items_json = json_encode(array_map(
        fn($id, $cantidad) => ['id_producto' => (int) $id, 'cantidad' => (int) $cantidad],
        array_keys($_SESSION["carrito"]),
        array_values($_SESSION["carrito"])
    ));

    // Llamamos al procedimiento que valida stock/precio y registra la venta, todo en una sola transacción.
    $stmt_call = $pdo->prepare('CALL sp_registrar_venta_productos(:pac, :usr, :metodo, :items, @id_venta, @total)');
    $stmt_call->execute([
        ':pac'    => $_SESSION["paciente_id"],
        ':usr'    => $_SESSION["usuario_id"],
        ':metodo' => 'tarjeta',
        ':items'  => $items_json,
    ]);
    $stmt_call->closeCursor();

    // Recuperamos el id de la venta recién creada.
    $salida = $pdo->query('SELECT @id_venta AS id_venta')->fetch();
    $id_venta = (int) $salida['id_venta'];

    // Vaciamos el carrito: ya se convirtió en una venta real.
    $_SESSION["carrito"] = [];
    unset($_SESSION['pago_pendiente']);

    header("Location: ver_factura.php?id=" . $id_venta);
    exit;

} catch (\Throwable $e) {
    // sp_registrar_venta_productos usa SIGNAL para sus mensajes de
    // validación (producto no disponible, stock insuficiente, etc.),
    // ya aptos para mostrarlos directamente.
    $es_error_del_procedimiento = $e instanceof \PDOException && ($e->errorInfo[0] ?? '') === '45000';
    // Si es un error "esperado" del procedimiento, mostramos su mensaje; si no, el mensaje genérico de la excepción.
    $mensaje_error = $es_error_del_procedimiento && isset($e->errorInfo[2])
        ? $e->errorInfo[2]
        : $e->getMessage();

    if (!$es_error_del_procedimiento) {
        // El procedimiento ya registra sus propios errores en bitácora; esto
        // cubre lo que pasa ANTES de llegar a él (ej. la verificación con
        // Stripe falla) — el pago pudo haberse cobrado igual, es crítico.
        registrar_bitacora($pdo, 'ERROR', "Falló confirmar un pago con tarjeta (Stripe) en la tienda: " . $e->getMessage());
    }

    die("Ocurrió un error al confirmar el pago: " . htmlspecialchars($mensaje_error));
}
