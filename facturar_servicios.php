<?php
/*
    facturar_servicios.php — Facturación de tratamientos/servicios.

    Recepción o administración seleccionan un paciente y los tratamientos
    realizados; si hay una promoción activa aplicable (paquete o
    descuento), el sistema la valida y aplica el descuento correspondiente
    antes de generar la factura (ventas + detalle_venta, tipo 'servicio').

    Los precios y la vigencia de la promoción SIEMPRE se recalculan aquí
    con los datos actuales de la base de datos al confirmar — nunca se
    confía en los montos que pudiera mandar el navegador.
*/

session_start();
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/bitacora_helper.php';
require_once './assets/includes/helpers/facturacion_servicios_helper.php';
require_once './assets/includes/config/stripe_config.php';
require_once './assets/includes/helpers/stripe_helper.php'; // función stripe_api_call()
require_once './assets/includes/models/facturar_servicios_model.php';

// Guard: solo recepcionista (id_rol = 3) o administrador (id_rol = 4).
$id_rol = (int) ($_SESSION['id_rol'] ?? 0);
if (!in_array($id_rol, [3, 4], true)) {
    header('Location: inicio.php');
    exit;
}

$mensaje = "";
$tipo_mensaje = "";
$fecha_hoy = date('Y-m-d');

// PROCESAR FACTURACIÓN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['facturar'])) {
    $id_paciente     = intval($_POST['id_paciente'] ?? 0);
    $servicios_ids   = isset($_POST['servicios']) ? array_map('intval', (array) $_POST['servicios']) : [];
    $id_promocion    = !empty($_POST['id_promocion']) ? intval($_POST['id_promocion']) : null;
    $metodo_pago     = $_POST['metodo_pago'] ?? '';
    $metodos_validos = ['efectivo', 'tarjeta', 'transferencia'];
    $tipo_pago       = $_POST['tipo_pago'] ?? 'completo';
    $monto_abonado_inicial = (float) ($_POST['monto_abonado_inicial'] ?? 0);
    $fecha_limite_pago     = trim($_POST['fecha_limite_pago'] ?? '');

    // Validaciones en cadena: la primera que falle define el mensaje de error.
    if (empty($id_paciente) || count($servicios_ids) === 0) {
        $mensaje = "Selecciona el paciente y al menos un servicio.";
        $tipo_mensaje = "error";
    } elseif (!in_array($metodo_pago, $metodos_validos, true)) {
        $mensaje = "Selecciona un método de pago válido.";
        $tipo_mensaje = "error";
    } elseif ($tipo_pago === 'parcial' && ($fecha_limite_pago === '' || $fecha_limite_pago < $fecha_hoy)) {
        $mensaje = "Indica una fecha límite de pago válida (hoy o una fecha futura).";
        $tipo_mensaje = "error";
    } elseif ($tipo_pago === 'parcial' && $monto_abonado_inicial < 0) {
        $mensaje = "El monto abonado no puede ser negativo.";
        $tipo_mensaje = "error";
    } else {
        // Precios REALES y vigentes de los servicios seleccionados (nunca los que pudo mandar el navegador).
        $servicios_encontrados = facturar_servicios_buscar_por_ids($pdo, $servicios_ids);

        if (count($servicios_encontrados) !== count(array_unique($servicios_ids))) {
            $mensaje = "Uno o más servicios seleccionados ya no están disponibles.";
            $tipo_mensaje = "error";
        } else {
            $precios_por_servicio = array_column($servicios_encontrados, 'precio', 'id_servicio');
            $nombres_por_servicio = array_column($servicios_encontrados, 'nombre', 'id_servicio');

            $descuento = 0.0;
            $promo_valida = null;

            if ($id_promocion !== null) {
                $promo_valida = facturar_servicios_obtener_promocion_vigente($pdo, $id_promocion, $fecha_hoy);
            }

            if ($id_promocion !== null && !$promo_valida) {
                // Se eligió una promoción, pero ya no está vigente (se desactivó o venció mientras se llenaba el formulario).
                $mensaje = "La promoción seleccionada ya no está activa. Vuelve a revisar el formulario.";
                $tipo_mensaje = "error";
            } else {
                if ($promo_valida) {
                    $servicios_promo = facturar_servicios_listar_servicios_de_promocion($pdo, $promo_valida['id_promocion']);

                    $promo_valida['servicios_ids'] = array_column($servicios_promo, 'id_servicio');
                    $promo_valida['servicios_nombres'] = array_column($servicios_promo, 'nombre');

                    $resultado = calcular_descuento_promocion($promo_valida, $servicios_ids, $precios_por_servicio);

                    if ($resultado['error'] !== null) {
                        $mensaje = $resultado['error'];
                        $tipo_mensaje = "error";
                    } else {
                        $descuento = $resultado['descuento'];
                    }
                }

                if ($mensaje === '') {
                    // Recalculamos los totales con el descuento ya validado arriba.
                    $subtotal = array_sum($precios_por_servicio);
                    $impuesto = ($subtotal - $descuento) * 0.13;
                    $total    = ($subtotal - $descuento) + $impuesto;

                    // Pago parcial: solo si se pidió Y el abono inicial no cubre el total
                    // (si el abono ya cubre todo, se factura como pago completo).
                    $es_pago_parcial = $tipo_pago === 'parcial' && $monto_abonado_inicial < $total;
                    $monto_a_cobrar = $es_pago_parcial ? $monto_abonado_inicial : $total;

                    $datos_venta = [
                        'id_paciente'            => $id_paciente,
                        'id_usuario'             => $_SESSION['usuario_id'],
                        'servicios_ids'          => $servicios_ids,
                        'precios_por_servicio'   => $precios_por_servicio,
                        'id_promocion'           => $promo_valida['id_promocion'] ?? null,
                        'nombre_promocion'       => $promo_valida['nombre'] ?? null,
                        'descuento'              => $descuento,
                        'metodo_pago'            => $metodo_pago,
                        'tipo_pago'              => $tipo_pago,
                        'monto_abonado_inicial'  => $monto_abonado_inicial,
                        'fecha_limite_pago'      => $fecha_limite_pago,
                    ];

                    if ($metodo_pago === 'tarjeta' && $monto_a_cobrar > 0) {
                        // Pago con tarjeta: igual que en la tienda (compra.php), se
                        // redirige a Stripe Checkout para introducir los datos de la
                        // tarjeta. La venta se registra recién cuando Stripe confirma
                        // el pago (ver pago_exito_servicios.php) — nunca antes.
                        try {
                            $nombre_linea = $es_pago_parcial
                                ? 'Abono a factura de servicios odontológicos'
                                : 'Servicios odontológicos (' . count($servicios_ids) . ')';

                            $line_items = [
                                'line_items[0][price_data][currency]' => STRIPE_CURRENCY,
                                'line_items[0][price_data][product_data][name]' => $nombre_linea,
                                'line_items[0][price_data][unit_amount]' => (int) round($monto_a_cobrar * 100),
                                'line_items[0][quantity]' => 1,
                            ];

                            // Si se cobra el total ahora (no un abono parcial), se
                            // desglosa el IVA en una línea aparte para que Stripe lo
                            // muestre — igual que compra.php.
                            if (!$es_pago_parcial) {
                                $line_items['line_items[0][price_data][product_data][name]'] = 'Servicios odontológicos (' . count($servicios_ids) . ') — subtotal con descuento';
                                $line_items['line_items[0][price_data][unit_amount]'] = (int) round(($subtotal - $descuento) * 100);
                                $line_items['line_items[1][price_data][currency]'] = STRIPE_CURRENCY;
                                $line_items['line_items[1][price_data][product_data][name]'] = 'IVA (13%)';
                                $line_items['line_items[1][price_data][unit_amount]'] = (int) round($impuesto * 100);
                                $line_items['line_items[1][quantity]'] = 1;
                            }

                            // Guardamos todo lo necesario para reconstruir y validar
                            // la venta de nuevo cuando Stripe confirme el pago.
                            $_SESSION['pago_pendiente_servicio'] = $datos_venta;

                            $params = array_merge($line_items, [
                                'mode'        => 'payment',
                                'success_url' => STRIPE_SUCCESS_URL_SERVICIOS,
                                'cancel_url'  => STRIPE_CANCEL_URL,
                            ]);

                            $session = stripe_api_call('checkout/sessions', $params);

                            header("Location: " . $session['url']);
                            exit;

                        } catch (\Throwable $e) {
                            registrar_bitacora($pdo, 'ERROR', "Falló iniciar el pago con tarjeta (Stripe) al facturar servicios para el paciente #$id_paciente: " . $e->getMessage());
                            $mensaje = "No se pudo iniciar el pago con tarjeta: " . $e->getMessage();
                            $tipo_mensaje = "error";
                        }
                    } else {
                        // sp_registrar_venta_servicios inserta la venta + detalle,
                        // descuenta insumos (servicio_productos) de forma agregada
                        // y registra el pago parcial si aplica — todo en una sola
                        // transacción con rollback.
                        try {
                            $id_venta_nueva = facturar_servicios_registrar_venta($pdo, [
                                'id_paciente'           => $id_paciente,
                                'id_usuario'            => $_SESSION['usuario_id'],
                                'servicios_ids'         => $servicios_ids,
                                'precios_por_servicio'  => $precios_por_servicio,
                                'id_promocion'          => $promo_valida['id_promocion'] ?? null,
                                'nombre_promocion'      => $promo_valida['nombre'] ?? null,
                                'descuento'             => $descuento,
                                'metodo_pago'           => $metodo_pago,
                                'tipo_pago'             => $tipo_pago,
                                'monto_abonado_inicial' => $monto_abonado_inicial,
                                'fecha_limite_pago'     => $fecha_limite_pago,
                            ]);
                            header("Location: ver_factura.php?id=" . $id_venta_nueva);
                            exit;
                        } catch (\PDOException $e) {
                            registrar_bitacora($pdo, 'ERROR', "Falló registrar la factura de servicios para el paciente #$id_paciente: " . $e->getMessage());
                            $mensaje = "Ocurrió un error al registrar la factura.";
                            $tipo_mensaje = "error";
                        }
                    }
                }
            }
        }
    }
}

// CONSULTAS PARA LOS CAMPOS DESPLEGABLES
$pacientes = facturar_servicios_listar_pacientes_activos($pdo);
$servicios_lista = facturar_servicios_listar_servicios_activos($pdo);

// Promociones vigentes HOY (para el selector; la vigencia se vuelve a validar al guardar).
$promociones_activas = facturar_servicios_listar_promociones_vigentes($pdo, $fecha_hoy);

// Servicios incluidos por cada promoción activa (para la lógica en JS que calcula el descuento en vivo).
$ids_promos = array_column($promociones_activas, 'id_promocion');
$mapa_promo_servicios = facturar_servicios_mapa_promocion_servicios($pdo, $ids_promos);
foreach ($promociones_activas as &$p) {
    $p['servicios_ids'] = $mapa_promo_servicios[(int) $p['id_promocion']] ?? [];
}
unset($p); // Buena práctica: se rompe la referencia "&$p" para que no queden datos pegados por accidente.
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facturar Tratamientos - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/home.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <link rel="stylesheet" href="assets/css/shared/gestionCitas.css">
    <!-- Estilos exclusivos del formulario de facturación (wizard de 4 pasos) -->
    <link rel="stylesheet" href="assets/css/pages/facturar_servicios.css">
    <link rel="stylesheet" href="assets/css/shared/toast.css">
</head>

<body>

    <div class="grid-overlay"></div>
    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>

    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <section class="wrap">
        <div class="dash-header">
            <div>
                <span class="eyebrow">Módulo de Facturación</span>
                <h1>Facturar <span class="grad">Tratamientos</span></h1>
                <p>Selecciona el paciente y los servicios realizados. Si hay una promoción activa aplicable, el descuento se calcula automáticamente.</p>
            </div>
        </div>
    </section>

    <section class="wrap citas-form-wrap">

        <div class="card citas-card">
            <form action="facturar_servicios.php" method="POST" id="formFacturar">
                <input type="hidden" name="facturar" value="1">

                <!-- BARRA DE PROGRESO (4 PASOS) -->
                <div class="wizard-steps">
                    <div class="wizard-step active" data-step="1">
                        <span class="wizard-step-circle">1</span>
                        <span class="wizard-step-label">Paciente</span>
                    </div>
                    <div class="wizard-step-line"></div>
                    <div class="wizard-step" data-step="2">
                        <span class="wizard-step-circle">2</span>
                        <span class="wizard-step-label">Servicios</span>
                    </div>
                    <div class="wizard-step-line"></div>
                    <div class="wizard-step" data-step="3">
                        <span class="wizard-step-circle">3</span>
                        <span class="wizard-step-label">Promoción</span>
                    </div>
                    <div class="wizard-step-line"></div>
                    <div class="wizard-step" data-step="4">
                        <span class="wizard-step-circle">4</span>
                        <span class="wizard-step-label">Pago</span>
                    </div>
                </div>

                <!-- PASO 1: PACIENTE -->
                <div class="wizard-panel active" data-panel="1">
                    <div class="sec-head citas-section-head">
                        <span class="eyebrow">Paso 1</span>
                        <h2>Paciente</h2>
                    </div>

                    <div class="form-group">
                        <label for="id_paciente"><span class="dash-req">*</span> Paciente</label>
                        <select name="id_paciente" id="id_paciente" required>
                            <option value="">-- Selecciona un paciente --</option>
                            <?php foreach ($pacientes as $pac): ?>
                                <option value="<?= $pac['id_paciente'] ?>">
                                    <?= htmlspecialchars($pac['nombre'] . ' ' . $pac['apellido'] . ' (Cédula: ' . $pac['cedula'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- PASO 2: SERVICIOS -->
                <div class="wizard-panel" data-panel="2">
                    <div class="sec-head citas-section-head">
                        <span class="eyebrow">Paso 2</span>
                        <h2>Servicios / Tratamientos Realizados</h2>
                    </div>

                    <div style="margin-bottom: 24px;">
                        <?php if (count($servicios_lista) === 0): ?>
                            <p class="muted-text">No hay servicios activos registrados.</p>
                        <?php else: ?>
                            <div class="servicios-checklist" id="lista_servicios">
                                <?php foreach ($servicios_lista as $srv): ?>
                                    <label>
                                        <input type="checkbox" name="servicios[]" class="chk-servicio" value="<?= $srv['id_servicio'] ?>" data-precio="<?= $srv['precio'] ?>">
                                        <?= htmlspecialchars($srv['nombre']) ?> (₡<?= number_format($srv['precio'], 0, ',', '.') ?>)
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- PASO 3: PROMOCIÓN -->
                <div class="wizard-panel" data-panel="3">
                    <div class="sec-head citas-section-head">
                        <span class="eyebrow">Paso 3</span>
                        <h2>Promoción (opcional)</h2>
                    </div>

                    <div class="form-group">
                        <label for="id_promocion">Promoción activa a aplicar</label>
                        <select name="id_promocion" id="id_promocion">
                            <option value="">-- Sin promoción --</option>
                            <?php foreach ($promociones_activas as $promo): ?>
                                <option value="<?= $promo['id_promocion'] ?>"
                                        data-tipo="<?= $promo['tipo'] ?>"
                                        data-valor="<?= $promo['valor_descuento'] ?>"
                                        data-precio-paquete="<?= $promo['precio_paquete'] ?>"
                                        data-servicios="<?= htmlspecialchars(implode(',', $promo['servicios_ids'])) ?>">
                                    <?= htmlspecialchars($promo['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p id="aviso_paquete"></p>
                    </div>
                </div>

                <!-- PASO 4: PAGO -->
                <div class="wizard-panel" data-panel="4">
                    <div class="sec-head citas-section-head">
                        <span class="eyebrow">Paso 4</span>
                        <h2>Pago</h2>
                    </div>

                    <div class="form-group" style="max-width: 320px;">
                        <label for="metodo_pago"><span class="dash-req">*</span> Método de pago</label>
                        <select name="metodo_pago" id="metodo_pago" required>
                            <option value="efectivo">Efectivo</option>
                            <option value="tarjeta">Tarjeta</option>
                            <option value="transferencia">Transferencia</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 24px;">
                        <label style="display:block; margin-bottom:10px;">¿El paciente paga el total ahora?</label>
                        <div style="display:flex; gap:24px; align-items:center;">
                            <label style="display:flex; align-items:center; gap:8px; font-weight:normal;">
                                <input type="radio" name="tipo_pago" value="completo" id="tipo_pago_completo" checked style="width:auto;"> Sí, paga completo
                            </label>
                            <label style="display:flex; align-items:center; gap:8px; font-weight:normal;">
                                <input type="radio" name="tipo_pago" value="parcial" id="tipo_pago_parcial" style="width:auto;"> No, deja un saldo pendiente
                            </label>
                        </div>
                    </div>

                    <div id="bloque_pago_parcial" class="grid-dos-columnas" style="display: none;">
                        <div>
                            <label for="monto_abonado_inicial">Monto que abona ahora (₡)</label>
                            <input type="number" step="0.01" min="0" id="monto_abonado_inicial" name="monto_abonado_inicial" value="0">
                        </div>
                        <div>
                            <label for="fecha_limite_pago"><span class="dash-req">*</span> Fecha límite de pago</label>
                            <input type="date" id="fecha_limite_pago" name="fecha_limite_pago" min="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                </div>

                <!-- RESUMEN: siempre visible, se actualiza en cualquier paso -->
                <div class="resumen-factura">
                    <div class="fila"><span>Subtotal</span><span id="resumen_subtotal">₡0</span></div>
                    <div class="fila descuento"><span>Descuento</span><span id="resumen_descuento">-₡0</span></div>
                    <div class="fila"><span>IVA (13%)</span><span id="resumen_iva">₡0</span></div>
                    <div class="fila total"><span>Total a pagar</span><span id="resumen_total">₡0</span></div>
                </div>

                <!-- NAVEGACIÓN DEL ASISTENTE -->
                <div class="wizard-nav">
                    <button type="button" id="btnAtras" class="btn-cancel invisible">Atrás</button>
                    <button type="button" id="btnSiguiente" class="btn-primary">Siguiente</button>
                    <button type="submit" id="btnGenerarFactura" class="btn-primary btn-guardar" style="display:none;">Generar Factura</button>
                </div>
            </form>
        </div>
    </section>

    <?php require_once 'assets/includes/partials/footer.php'; ?>

    <!-- NOTIFICACIÓN TEMPORAL (ver assets/js/shared/toast.js) -->
    <div id="toastNotificacion" class="toast-notification" data-mensaje="<?= htmlspecialchars($mensaje) ?>" data-tipo="<?= htmlspecialchars($tipo_mensaje) ?>" role="alert" aria-live="assertive">
        <span class="toast-icon" aria-hidden="true"></span>
        <div class="toast-body">
            <strong id="toastTitulo"></strong>
            <p id="toastMensaje"></p>
        </div>
        <button type="button" class="toast-close" onclick="cerrarToast()" aria-label="Cerrar notificación">&times;</button>
    </div>

    <script src="assets/js/shared/toast.js"></script>
    <script src="assets/js/pages/facturar_servicios.js"></script>

</body>
</html>
