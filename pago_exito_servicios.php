<?php
/*
    pago_exito_servicios.php

    Stripe redirige aquí (STRIPE_SUCCESS_URL_SERVICIOS, ver stripe_config.php)
    después de que recepción/administración completa el pago con tarjeta de
    una factura de servicios en facturar_servicios.php. Este archivo:

    1. Verifica con la API de Stripe que la sesión de pago realmente se
       completó (nunca confía en el simple hecho de haber sido redirigido
       aquí — eso se puede falsificar visitando la URL directamente).
    2. Vuelve a calcular precios de servicios y descuento de promoción con
       los datos ACTUALES de la base de datos (igual que facturar_servicios.php
       hace para efectivo/transferencia) — nunca confía en montos guardados
       en sesión desde antes de pasar por Stripe.
    3. Guarda la venta (y el pago parcial, si aplica) con el mismo helper
       que usa el pago en efectivo/transferencia.
    4. Redirige a la factura digital.
*/

session_start();
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/bitacora_helper.php';
require_once './assets/includes/helpers/facturacion_servicios_helper.php';
require_once './assets/includes/config/stripe_config.php';
require_once './assets/includes/helpers/stripe_helper.php'; // función stripe_api_call()
require_once './assets/includes/models/facturar_servicios_model.php';

// Guard: solo recepcionista (id_rol = 3) o administrador (id_rol = 4), con un pago pendiente en sesión.
$id_rol = (int) ($_SESSION['id_rol'] ?? 0);
if (!in_array($id_rol, [3, 4], true) || !isset($_SESSION['pago_pendiente_servicio'], $_GET['session_id'])) {
    header('Location: facturar_servicios.php');
    exit;
}

$fecha_hoy = date('Y-m-d');
$datos_venta = $_SESSION['pago_pendiente_servicio']; // Lo que se había guardado antes de mandar al usuario a Stripe.

try {
    // Le preguntamos directamente a Stripe si el pago realmente se completó.
    $session = stripe_api_call('checkout/sessions/' . urlencode($_GET['session_id']), [], 'GET');

    if ($session['payment_status'] !== 'paid') {
        die("El pago no se completó. Si el problema persiste, contacta soporte.");
    }

    // Precios REALES y vigentes de los servicios seleccionados (nunca los de sesión).
    $servicios_ids = $datos_venta['servicios_ids'];
    $servicios_encontrados = facturar_servicios_buscar_por_ids($pdo, $servicios_ids);

    if (count($servicios_encontrados) !== count(array_unique($servicios_ids))) {
        // Algún servicio se desactivó mientras el usuario estaba pagando en Stripe.
        die("Uno o más servicios facturados ya no están disponibles. El pago fue exitoso; contacta al administrador para resolver la factura manualmente.");
    }

    $precios_por_servicio = array_column($servicios_encontrados, 'precio', 'id_servicio');
    $datos_venta['precios_por_servicio'] = $precios_por_servicio;

    // Revalidar la promoción (si había una) igual que en facturar_servicios.php.
    $descuento = 0.0;
    $promo_valida = null;
    if ($datos_venta['id_promocion'] !== null) {
        $promo_valida = facturar_servicios_obtener_promocion_vigente($pdo, $datos_venta['id_promocion'], $fecha_hoy);

        if (!$promo_valida) {
            // La promoción se desactivó o venció mientras el usuario pagaba.
            die("La promoción aplicada ya no está activa. El pago fue exitoso; contacta al administrador para resolver la factura manualmente.");
        }

        $datos_venta['nombre_promocion'] = $promo_valida['nombre'];

        $servicios_promo = facturar_servicios_listar_servicios_de_promocion($pdo, $promo_valida['id_promocion']);

        $promo_valida['servicios_ids'] = array_column($servicios_promo, 'id_servicio');
        $promo_valida['servicios_nombres'] = array_column($servicios_promo, 'nombre');

        $resultado = calcular_descuento_promocion($promo_valida, $servicios_ids, $precios_por_servicio);

        if ($resultado['error'] !== null) {
            die($resultado['error'] . " El pago fue exitoso; contacta al administrador para resolver la factura manualmente.");
        }

        $descuento = $resultado['descuento'];
    }

    $datos_venta['descuento'] = $descuento;

    // Mismo procedimiento que usa facturar_servicios.php para efectivo/
    // transferencia: inserta venta + detalle, descuenta insumos y registra
    // el pago parcial si aplica, todo en una sola transacción con rollback.
    // Armamos el paquete de datos con toda la información ya revalidada.
    $id_venta_nueva = facturar_servicios_registrar_venta($pdo, [
        'id_paciente'           => $datos_venta['id_paciente'],
        'id_usuario'            => $datos_venta['id_usuario'],
        'servicios_ids'         => $servicios_ids,
        'precios_por_servicio'  => $precios_por_servicio,
        'id_promocion'          => $datos_venta['id_promocion'],
        'nombre_promocion'      => $datos_venta['nombre_promocion'] ?? null,
        'descuento'             => $descuento,
        'metodo_pago'           => 'tarjeta',
        'tipo_pago'             => $datos_venta['tipo_pago'],
        'monto_abonado_inicial' => $datos_venta['monto_abonado_inicial'],
        'fecha_limite_pago'     => $datos_venta['fecha_limite_pago'],
    ]);

    // Limpiamos el pago pendiente de sesión: ya se convirtió en una factura real.
    unset($_SESSION['pago_pendiente_servicio']);

    header("Location: ver_factura.php?id=" . $id_venta_nueva);
    exit;

} catch (\Throwable $e) {
    // El pago con tarjeta ya se cobró en Stripe en este punto; si falla el
    // registro en la base de datos, es crítico que quede constancia para
    // reconciliar manualmente (el dinero sí se cobró, la factura no se guardó).
    // sp_registrar_venta_servicios ya registra sus propios fallos en
    // bitácora; esto solo cubre lo que pasa ANTES de llegar a él.
    $es_error_del_procedimiento = $e instanceof \PDOException && ($e->errorInfo[0] ?? '') === '45000';
    if (!$es_error_del_procedimiento) {
        registrar_bitacora($pdo, 'ERROR', "Pago con tarjeta confirmado por Stripe pero falló registrar la factura de servicios (paciente #{$datos_venta['id_paciente']}): " . $e->getMessage());
    }
    die("Ocurrió un error al confirmar el pago: " . htmlspecialchars($e->getMessage()));
}
