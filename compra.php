<?php
/*
    compra.php

    Esta página finaliza la compra de los productos agregados al carrito.

    Qué hace:
    1. Verifica si el usuario inició sesión.
    2. Lee los productos guardados en $_SESSION["carrito"] (id_producto => cantidad).
    3. Reconcilia el carrito contra el estado real en la base de datos: si un
       producto fue inactivado, se venció o ya no tiene stock suficiente
       desde que se agregó al carrito, se ajusta o se remueve y se avisa.
    4. Calcula el subtotal, IVA (13%) y total con los precios REALES de la
       base de datos (nunca se confía en nada guardado en sesión).
    5. Al confirmar, vuelve a validar y descuenta el stock de forma atómica
       (evita vender productos vencidos, inactivos o sin stock, incluso si
       cambiaron justo en ese instante).
    6. Guarda la venta en la tabla ventas y el detalle en detalle_venta.
    7. Vacía el carrito después de registrar la venta.
    8. Redirige a la factura digital (ver_factura.php).
*/

session_start();

require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/bitacora_helper.php';
require_once './assets/includes/config/stripe_config.php';
require_once './assets/includes/helpers/stripe_helper.php'; // función stripe_api_call()
require_once './assets/includes/helpers/paginacion_helper.php';

/*
    Si el usuario no inició sesión, no puede finalizar la compra.
*/
if (!isset($_SESSION["usuario_id"]) || !isset($_SESSION["paciente_id"])) {
    header("Location: login.php?redirect=compra.php");
    exit;
}

/*
    Si el carrito no existe, lo creamos vacío.
*/
if (!isset($_SESSION["carrito"])) {
    $_SESSION["carrito"] = [];
}

// FILTRO Y PAGINACIÓN DE LA VISTA DEL CARRITO (no afectan qué se compra:
// al confirmar siempre se procesa el carrito completo, sin importar la
// página o el buscador que se esté usando en este momento).
$busqueda_carrito = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$pagina_carrito    = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$query_carrito     = $busqueda_carrito !== '' ? '&buscar=' . urlencode($busqueda_carrito) : '';

// QUITAR UN PRODUCTO DEL CARRITO
// Si el botón "×" de un producto trae un ?quitar=X en la URL...
if (isset($_GET['quitar'])) {
    unset($_SESSION["carrito"][intval($_GET['quitar'])]);
    header('Location: compra.php?msg=quitado' . ($pagina_carrito > 1 ? '&pagina=' . $pagina_carrito : '') . $query_carrito);
    exit;
}

// ACTUALIZAR LA CANTIDAD DE UN PRODUCTO DEL CARRITO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_cantidad'])) {
    $id_actualizar = intval($_POST['id_producto'] ?? 0);
    $cantidad_nueva = intval($_POST['cantidad'] ?? 0);

    if (isset($_SESSION["carrito"][$id_actualizar])) {
        if ($cantidad_nueva <= 0) {
            // Poner 0 (o menos) en el campo de cantidad equivale a quitar el producto.
            unset($_SESSION["carrito"][$id_actualizar]);
        } else {
            // No dejamos poner más unidades de las que realmente hay en stock.
            $stmt_stock_chk = $pdo->prepare("SELECT stock FROM productos WHERE id_producto = :id AND activo = 1");
            $stmt_stock_chk->execute([':id' => $id_actualizar]);
            $stock_disponible = $stmt_stock_chk->fetchColumn();
            $_SESSION["carrito"][$id_actualizar] = $stock_disponible !== false
                ? min($cantidad_nueva, (int) $stock_disponible)
                : $cantidad_nueva;
        }
    }
    header('Location: compra.php?msg=actualizado' . ($pagina_carrito > 1 ? '&pagina=' . $pagina_carrito : '') . $query_carrito);
    exit;
}

$mensaje = "";
$avisos = [];
$fecha_hoy = date('Y-m-d');

if (isset($_GET['msg']) && $_GET['msg'] === 'quitado') {
    $avisos[] = 'Producto quitado del carrito.';
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'actualizado') {
    $avisos[] = 'Cantidad actualizada.';
}

/*
    Cargamos de la base de datos los productos que están en el carrito.
    Esto siempre refleja el estado más reciente (precio, stock, vigencia).
*/
$productos_carrito = [];
if (!empty($_SESSION["carrito"])) {
    $ids = array_map('intval', array_keys($_SESSION["carrito"]));
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $stmt_ids = $pdo->prepare("SELECT id_producto, nombre, imagen, precio_unitario, stock, activo, fecha_caducidad FROM productos WHERE id_producto IN ($marcadores)");
    $stmt_ids->execute($ids);
    foreach ($stmt_ids->fetchAll() as $p) {
        $productos_carrito[(int) $p['id_producto']] = $p;
    }
}

/*
    Reconciliamos el carrito contra el estado real: si un producto ya no
    existe, fue inactivado, se venció o ya no alcanza el stock, se ajusta
    o se elimina del carrito y se le avisa al paciente.
*/
foreach ($_SESSION["carrito"] as $id_producto => $cantidad) {
    $id_producto = (int) $id_producto;

    // Caso 1: el producto ya ni siquiera existe en la base de datos.
    if (!isset($productos_carrito[$id_producto])) {
        unset($_SESSION["carrito"][$id_producto]);
        $avisos[] = "Un producto de tu carrito ya no existe y fue removido.";
        continue;
    }

    $p = $productos_carrito[$id_producto];

    // Caso 2: lo desactivaron desde que se agregó al carrito.
    if ((int) $p['activo'] !== 1) {
        unset($_SESSION["carrito"][$id_producto]);
        unset($productos_carrito[$id_producto]);
        $avisos[] = "\"{$p['nombre']}\" ya no está disponible y fue removido del carrito.";
        continue;
    }

    // Caso 3: se venció.
    if (!empty($p['fecha_caducidad']) && $p['fecha_caducidad'] < $fecha_hoy) {
        unset($_SESSION["carrito"][$id_producto]);
        unset($productos_carrito[$id_producto]);
        $avisos[] = "\"{$p['nombre']}\" está vencido y fue removido del carrito.";
        continue;
    }

    // Caso 4: ya no queda suficiente stock para la cantidad que se había puesto en el carrito.
    if ($cantidad > (int) $p['stock']) {
        if ((int) $p['stock'] <= 0) {
            unset($_SESSION["carrito"][$id_producto]);
            unset($productos_carrito[$id_producto]);
            $avisos[] = "\"{$p['nombre']}\" está agotado y fue removido del carrito.";
        } else {
            // Todavía queda algo de stock, solo ajustamos la cantidad a lo disponible.
            $_SESSION["carrito"][$id_producto] = (int) $p['stock'];
            $avisos[] = "Solo quedan {$p['stock']} unidad(es) de \"{$p['nombre']}\"; se ajustó la cantidad en tu carrito.";
        }
    }
}

/*
    Cálculos de la compra con IVA, usando siempre el precio real
    consultado en la base de datos.
*/
$subtotal = 0;
$descuento = 0;

// Sumamos precio × cantidad de cada producto que quedó en el carrito después de la reconciliación de arriba.
foreach ($_SESSION["carrito"] as $id_producto => $cantidad) {
    if (isset($productos_carrito[$id_producto])) {
        $subtotal += $productos_carrito[$id_producto]['precio_unitario'] * $cantidad;
    }
}

// Cálculo del IVA al 13% y Total Final
$impuesto = ($subtotal - $descuento) * 0.13;
$total = ($subtotal - $descuento) + $impuesto;

/*
    Vista del carrito con buscador y paginación (no afecta lo que se compra,
    solo cómo se muestra la lista cuando el carrito tiene muchos productos).
*/
$items_carrito_todos = [];
foreach ($_SESSION["carrito"] as $id_producto => $cantidad) {
    if (!isset($productos_carrito[$id_producto])) {
        continue;
    }
    $p = $productos_carrito[$id_producto];
    if ($busqueda_carrito !== '' && stripos($p['nombre'], $busqueda_carrito) === false) {
        continue;
    }
    // La tienda de productos no maneja descuentos por artículo hoy (las
    // promociones del sistema solo aplican a servicios/tratamientos, ver
    // facturar_servicios.php); se deja el campo listo para cuando exista.
    $descuento_item = 0.0;
    $bruto_item = (float) $p['precio_unitario'] * $cantidad;

    $items_carrito_todos[] = [
        'id_producto' => (int) $id_producto,
        'nombre'      => $p['nombre'],
        'imagen'      => $p['imagen'] ?? '',
        'precio'      => (float) $p['precio_unitario'],
        'stock'       => (int) $p['stock'],
        'cantidad'    => $cantidad,
        'descuento'   => $descuento_item,
        'subtotal'    => $bruto_item - $descuento_item,
    ];
}

$limite_carrito = 5;
$total_items_carrito = count($items_carrito_todos);
$total_paginas_carrito = max(1, (int) ceil($total_items_carrito / $limite_carrito));
if ($pagina_carrito > $total_paginas_carrito) {
    $pagina_carrito = $total_paginas_carrito;
}
$items_carrito_pagina = array_slice($items_carrito_todos, ($pagina_carrito - 1) * $limite_carrito, $limite_carrito);

/*
    Proceso de confirmación de compra
*/
// Si el paciente confirmó la compra con el botón "Confirmar compra"...
if (isset($_POST["confirmar_compra"])) {

    $metodo_pago = $_POST["metodo_pago"];
    $metodos_validos = ["efectivo", "tarjeta", "transferencia"];

    if (!in_array($metodo_pago, $metodos_validos)) {
    $mensaje = "Método de pago no válido.";
}
elseif (empty($_SESSION["carrito"])) {
    $mensaje = "No hay productos en el carrito.";
}
elseif ($metodo_pago === 'tarjeta') {
    // Pago con tarjeta: no se registra la venta todavía, primero se manda al paciente a pagar en Stripe.
    // La venta se registra recién en pago_exito.php, después de que Stripe confirme que sí se cobró.
    try {
        // Construimos los line_items para Stripe con precios REALES de BD.
        $line_items = [];
        $i = 0;
        foreach ($_SESSION["carrito"] as $id_producto => $cantidad) {
            if (!isset($productos_carrito[$id_producto])) continue;
            $p = $productos_carrito[$id_producto];

            $line_items["line_items[$i][price_data][currency]"] = STRIPE_CURRENCY;
            $line_items["line_items[$i][price_data][product_data][name]"] = $p['nombre'];
            $line_items["line_items[$i][price_data][unit_amount]"] = (int) round($p['precio_unitario'] * 100);
            $line_items["line_items[$i][quantity]"] = $cantidad;
            $i++;
        }

        // Línea aparte para el IVA (13%): Stripe solo cobra lo que va en
        // line_items, así que sin esto el cliente pagaría el subtotal sin impuesto.
        $line_items["line_items[$i][price_data][currency]"] = STRIPE_CURRENCY;
        $line_items["line_items[$i][price_data][product_data][name]"] = 'IVA (13%)';
        $line_items["line_items[$i][price_data][unit_amount]"] = (int) round($impuesto * 100);
        $line_items["line_items[$i][quantity]"] = 1;

        // Guardamos referencia para reconciliar el pago cuando Stripe confirme.
        $_SESSION['pago_pendiente'] = [
            'id_paciente' => $_SESSION['paciente_id'],
            'id_usuario'  => $_SESSION['usuario_id'],
        ];

        $params = array_merge($line_items, [
            'mode'        => 'payment',
            'success_url' => STRIPE_SUCCESS_URL,
            'cancel_url'  => STRIPE_CANCEL_URL,
        ]);

        $session = stripe_api_call('checkout/sessions', $params);

        header("Location: " . $session['url']);
        exit;

    } catch (\Throwable $e) {
        registrar_bitacora($pdo, 'ERROR', "Falló iniciar el pago con tarjeta (Stripe) en la tienda: " . $e->getMessage());
        $mensaje = "No se pudo iniciar el pago con tarjeta: " . $e->getMessage();
    }
}
    else {
        // Efectivo o transferencia: se registra la venta de una vez, sin pasar por Stripe.
        // La reconciliación de precio/stock/vigencia de cada producto, el
        // descuento atómico del stock, la creación de la venta y su detalle
        // ocurren dentro de sp_registrar_venta_productos (transacción propia
        // con rollback si cualquier artículo del carrito falla).
        $items_json = json_encode(array_map(
            fn($id, $cantidad) => ['id_producto' => (int) $id, 'cantidad' => (int) $cantidad],
            array_keys($_SESSION["carrito"]),
            array_values($_SESSION["carrito"])
        ));

        try {
            $stmt_call = $pdo->prepare('CALL sp_registrar_venta_productos(:pac, :usr, :metodo, :items, @id_venta, @total)');
            $stmt_call->execute([
                ':pac'    => $_SESSION["paciente_id"],
                ':usr'    => $_SESSION["usuario_id"],
                ':metodo' => $metodo_pago,
                ':items'  => $items_json,
            ]);
            $stmt_call->closeCursor();

            $salida = $pdo->query('SELECT @id_venta AS id_venta')->fetch();
            $id_venta = (int) $salida['id_venta'];

            // Vaciamos el carrito tras procesar la venta
            $_SESSION["carrito"] = [];

            // Redirección inmediata a ver la factura generada
            header("Location: ver_factura.php?id=" . $id_venta);
            exit;

        } catch (\PDOException $e) {
            // sp_registrar_venta_productos usa SIGNAL para los mensajes de
            // validación (carrito vacío, producto no disponible, stock
            // insuficiente); ese texto ya es apto para mostrarlo al usuario.
            $mensaje = $e->errorInfo[2] ?? "Ocurrió un error al registrar la venta.";
        }
    }
}

$year = date("Y");
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OdontoNet — Compra</title>

    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <!-- CSS general del proyecto -->
    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <link rel="stylesheet" href="assets/css/shared/tienda_compra.css">
    <link rel="stylesheet" href="assets/css/shared/compra_pago_cancelado.css">
    <link rel="stylesheet" href="assets/css/pages/compra.css">
</head>

<body>

    <!-- Fondos decorativos -->
    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>
    <div class="glow glow-c"></div>
    <div class="grid-overlay"></div>

    <!-- NAVBAR -->
    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <!-- ENCABEZADO: mismo estilo de título que el resto de los dashboards -->
    <section class="wrap dash-wide">
        <div class="dash-header">
            <div>
                <span class="eyebrow">Finalizar compra</span>
                <h1>Resumen de <span class="grad">compra</span></h1>
                <p>Revise los productos agregados al carrito y confirme el método de pago para registrar la venta en el sistema.</p>
            </div>
        </div>
    </section>

    <?php if (!empty($avisos) || $mensaje !== ''): ?>
        <section class="wrap dash-wide">
            <?php foreach ($avisos as $aviso): ?>
                <div class="mensaje-tienda"><?= htmlspecialchars($aviso) ?></div>
            <?php endforeach; ?>
            <?php if ($mensaje !== ''): ?>
                <div class="mensaje-tienda"><?= htmlspecialchars($mensaje) ?></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (empty($_SESSION["carrito"])): ?>

        <section class="wrap dash-wide">
            <div class="compra-box compra-vacio">
                <h2>Carrito vacío</h2>
                <p>No hay productos pendientes de compra.</p>
                <a href="tienda.php" class="btn-primary">Volver a la tienda</a>
            </div>
        </section>

    <?php else: ?>

        <section class="wrap dash-wide">
            <div class="compra-layout">

                <div class="compra-items-col">

                    <!-- Bloque separado: título + buscador (no forma parte de la lista de productos) -->
                    <div class="compra-box compra-items-head-box">
                        <h2>Productos seleccionados</h2>
                        <form action="compra.php" method="GET" class="compra-buscador">
                            <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda_carrito) ?>" placeholder="Buscar en el carrito..." class="form-control">
                            <button type="submit" class="btn-primary">Buscar</button>
                            <?php if ($busqueda_carrito !== ''): ?>
                                <a href="compra.php" class="btn-cancel">Limpiar</a>
                            <?php endif; ?>
                        </form>
                        <p class="dash-results-count"><?= $total_items_carrito ?> producto(s) distinto(s) en el carrito<?= $busqueda_carrito !== '' ? ' con ese filtro' : '' ?>.</p>
                    </div>

                    <!-- Bloque separado: los productos añadidos -->
                    <div class="compra-box compra-items-list-box">
                        <?php if ($total_items_carrito === 0): ?>
                            <p class="muted-text">No hay productos en el carrito que coincidan con "<?= htmlspecialchars($busqueda_carrito) ?>".</p>
                        <?php else: ?>
                            <div class="compra-lista">
                                <?php foreach ($items_carrito_pagina as $item): ?>
                                    <div class="compra-item">
                                        <img class="compra-item-img" src="<?= !empty($item['imagen']) ? htmlspecialchars($item['imagen']) : 'assets/images/logos/logo-navbar.svg' ?>" alt="<?= htmlspecialchars($item['nombre']) ?>" onerror="this.onerror=null;this.src='assets/images/logos/logo-navbar.svg';">

                                        <div class="compra-item-info">
                                            <strong><?= htmlspecialchars($item['nombre']) ?></strong>
                                            <div class="compra-item-meta">
                                                <span>Cantidad: <?= (int) $item['cantidad'] ?></span>
                                                <span>Precio unitario: ₡<?= number_format($item['precio'], 0, ",", ".") ?></span>
                                                <?php if ($item['descuento'] > 0): ?>
                                                    <span class="compra-item-descuento">Descuento: -₡<?= number_format($item['descuento'], 0, ",", ".") ?></span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="compra-item-acciones">
                                                <form method="post" action="compra.php?pagina=<?= $pagina_carrito ?><?= $query_carrito ?>" class="compra-item-cantidad">
                                                    <input type="hidden" name="id_producto" value="<?= $item['id_producto'] ?>">
                                                    <input type="number" name="cantidad" value="<?= $item['cantidad'] ?>" min="1" max="<?= $item['stock'] ?>" class="form-control" aria-label="Cantidad de <?= htmlspecialchars($item['nombre']) ?>">
                                                    <button type="submit" name="actualizar_cantidad" class="btn-ghost">Actualizar</button>
                                                </form>
                                                <a href="compra.php?quitar=<?= $item['id_producto'] ?><?= $pagina_carrito > 1 ? '&pagina=' . $pagina_carrito : '' ?><?= $query_carrito ?>" class="compra-item-quitar" title="Quitar del carrito" aria-label="Quitar <?= htmlspecialchars($item['nombre']) ?> del carrito">&times;</a>
                                            </div>
                                        </div>

                                        <strong class="compra-item-total">₡<?= number_format($item['subtotal'], 0, ",", ".") ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($total_paginas_carrito > 1): ?>
                                <div class="pagination-container">
                                    <span class="pagination-info">Página <strong><?= $pagina_carrito ?></strong> de <strong><?= $total_paginas_carrito ?></strong></span>
                                    <div class="pagination">
                                        <?php if ($pagina_carrito > 1): ?>
                                            <a href="compra.php?pagina=<?= $pagina_carrito - 1 ?><?= $query_carrito ?>" class="page-link">&laquo; Anterior</a>
                                        <?php endif; ?>
                                        <?php foreach (obtener_paginas_visibles($pagina_carrito, $total_paginas_carrito) as $item_pag): ?>
                                            <?php if ($item_pag === '...'): ?>
                                                <span class="page-ellipsis">&hellip;</span>
                                            <?php else: ?>
                                                <a href="compra.php?pagina=<?= $item_pag ?><?= $query_carrito ?>" class="page-link <?= $item_pag === $pagina_carrito ? 'active' : '' ?>"><?= $item_pag ?></a>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php if ($pagina_carrito < $total_paginas_carrito): ?>
                                            <a href="compra.php?pagina=<?= $pagina_carrito + 1 ?><?= $query_carrito ?>" class="page-link">Siguiente &raquo;</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <aside class="compra-summary-col">
                    <div class="compra-box compra-summary-card">
                        <h2>Resumen</h2>

                        <div class="compra-summary-row">
                            <span>Subtotal</span>
                            <strong>₡<?= number_format($subtotal, 0, ",", ".") ?></strong>
                        </div>
                        <div class="compra-summary-row">
                            <span>IVA (13%)</span>
                            <strong>₡<?= number_format($impuesto, 0, ",", ".") ?></strong>
                        </div>
                        <div class="compra-summary-row compra-summary-total">
                            <span>Total a pagar</span>
                            <strong>₡<?= number_format($total, 0, ",", ".") ?></strong>
                        </div>

                        <form method="post" class="compra-form">
                            <label for="metodo_pago"><span class="dash-req">*</span> Método de pago</label>
                            <select name="metodo_pago" id="metodo_pago" required>
                                <option value="efectivo">Efectivo</option>
                                <option value="tarjeta">Tarjeta</option>
                                <option value="transferencia">Transferencia</option>
                            </select>

                            <button class="btn-primary" type="submit" name="confirmar_compra">Confirmar compra</button>
                            <a href="tienda.php" class="btn-ghost">Volver a tienda</a>
                        </form>
                    </div>
                </aside>

            </div>
        </section>

    <?php endif; ?>

    <!-- Footer -->
    <?php require_once 'assets/includes/partials/footer.php'; ?>

</body>
</html>
