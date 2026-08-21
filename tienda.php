<?php
/*
    tienda.php

    Esta página muestra la tienda de productos de cuidado dental como un
    dashboard: filtros en una barra lateral (categoría, precio,
    disponibilidad), buscador y paginación — mismas reglas que los demás
    dashboards del sistema.

    - Los productos se cargan desde la tabla `productos` (los mismos que
      administra el equipo de recepción/administración en inventario.php).
    - No se muestran productos inactivos (soft-delete); los vencidos o sin
      stock aparecen deshabilitados con una etiqueta indicando el motivo.
    - El carrito se guarda en sesión como [id_producto => cantidad], nunca
      confiando en nombres ni precios enviados por el navegador.
    - Al agregar al carrito se valida que el producto siga activo, vigente
      y con stock disponible (la validación definitiva vuelve a ocurrir en
      compra.php al confirmar el pago).
*/

require_once __DIR__ . '/assets/includes/session_boot.php';

require_once 'assets/includes/config/config.php';
require_once 'assets/includes/auth_check.php';
require_once 'assets/includes/helpers/paginacion_helper.php';

if (!isset($_SESSION["carrito"])) {
    $_SESSION["carrito"] = [];
}

// FILTROS Y PAGINACIÓN (se leen primero: "agregar al carrito" los necesita
// para volver a la misma página/filtro después de redirigir).
$busqueda               = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$categoria_filtro       = isset($_GET['categoria']) ? intval($_GET['categoria']) : 0;
$precio_min             = isset($_GET['precio_min']) && $_GET['precio_min'] !== '' ? (float) $_GET['precio_min'] : null;
$precio_max             = isset($_GET['precio_max']) && $_GET['precio_max'] !== '' ? (float) $_GET['precio_max'] : null;
$disponibilidad_filtro  = isset($_GET['disponibilidad']) && in_array($_GET['disponibilidad'], ['disponible', 'agotado'], true) ? $_GET['disponibilidad'] : '';
$pagina                 = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

$query_filtros = '';
if ($busqueda !== '') $query_filtros .= '&buscar=' . urlencode($busqueda);
if ($categoria_filtro > 0) $query_filtros .= '&categoria=' . $categoria_filtro;
if ($precio_min !== null) $query_filtros .= '&precio_min=' . $precio_min;
if ($precio_max !== null) $query_filtros .= '&precio_max=' . $precio_max;
if ($disponibilidad_filtro !== '') $query_filtros .= '&disponibilidad=' . urlencode($disponibilidad_filtro);

$hay_filtros_activos = $busqueda !== '' || $categoria_filtro > 0 || $precio_min !== null || $precio_max !== null || $disponibilidad_filtro !== '';

// AGREGAR AL CARRITO
// Si el link "Agregar al carrito" trae un ?agregar=X en la URL...
if (isset($_GET['agregar'])) {
    $id_producto = intval($_GET['agregar']);

    $stmt_producto = $pdo->prepare("SELECT id_producto, nombre, stock, activo, fecha_caducidad FROM productos WHERE id_producto = :id");
    $stmt_producto->execute([':id' => $id_producto]);
    $producto_seleccionado = $stmt_producto->fetch();

    $fecha_hoy_agregar = date('Y-m-d');
    $redir_msg = '';
    $redir_extra = '';

    if (!$producto_seleccionado || (int) $producto_seleccionado['activo'] !== 1) {
        // El producto ya no existe o se desactivó desde que se cargó la página.
        $redir_msg = 'no_disponible';
    } elseif (!empty($producto_seleccionado['fecha_caducidad']) && $producto_seleccionado['fecha_caducidad'] < $fecha_hoy_agregar) {
        $redir_msg = 'vencido';
    } else {
        // Revisamos que agregar una unidad más no supere el stock disponible.
        $cantidad_en_carrito = $_SESSION["carrito"][$id_producto] ?? 0;
        if ($cantidad_en_carrito + 1 > (int) $producto_seleccionado['stock']) {
            $redir_msg = 'sin_stock';
            $redir_extra = '&producto=' . urlencode($producto_seleccionado['nombre']);
        } else {
            $_SESSION["carrito"][$id_producto] = $cantidad_en_carrito + 1;
            $redir_msg = 'agregado';
        }
    }

    // Volvemos a la misma página y con los mismos filtros que tenía antes de agregar, con el mensaje de resultado.
    header('Location: tienda.php?msg=' . $redir_msg . $redir_extra . ($pagina > 1 ? '&pagina=' . $pagina : '') . $query_filtros);
    exit;
}

// MENSAJES DE FEEDBACK (viajan como notificación temporal, ver toast al final de la página)
$mensaje = '';
$tipo_mensaje = '';
if (isset($_GET['msg'])) {
    $mensajes_ok = [
        'agregado'      => ['Producto agregado al carrito.', 'exito'],
        'no_disponible' => ['Ese producto ya no está disponible.', 'error'],
        'vencido'       => ['Ese producto está vencido y no se puede agregar al carrito.', 'error'],
    ];
    if (isset($mensajes_ok[$_GET['msg']])) {
        [$mensaje, $tipo_mensaje] = $mensajes_ok[$_GET['msg']];
    } elseif ($_GET['msg'] === 'sin_stock') {
        $nombre_prod = $_GET['producto'] ?? 'este producto';
        $mensaje = 'No hay más stock disponible de "' . $nombre_prod . '".';
        $tipo_mensaje = 'error';
    }
}

// Cuántos productos hay en el carrito (para el badge flotante).
$total_productos_carrito = array_sum($_SESSION["carrito"]);

// CATEGORÍAS DE PRODUCTOS (para el filtro lateral)
$categorias_producto = $pdo->query("SELECT id_categoria, nombre FROM categorias WHERE tipo = 'producto' AND activo = 1 ORDER BY nombre ASC")->fetchAll();

// CONSULTA DE PRODUCTOS: filtros + paginación
// Armamos la consulta SQL en pedazos ("condiciones"). Siempre mostramos solo productos activos.
$condiciones = ["p.activo = 1"];
$params = [];

if ($busqueda !== '') {
    $condiciones[] = "(p.nombre LIKE :b1 OR p.descripcion LIKE :b2)";
    $param_valor = '%' . $busqueda . '%';
    $params[':b1'] = $param_valor;
    $params[':b2'] = $param_valor;
}
if ($categoria_filtro > 0) {
    $condiciones[] = "p.id_categoria = :categoria";
    $params[':categoria'] = $categoria_filtro;
}
if ($precio_min !== null) {
    $condiciones[] = "p.precio_unitario >= :precio_min";
    $params[':precio_min'] = $precio_min;
}
if ($precio_max !== null) {
    $condiciones[] = "p.precio_unitario <= :precio_max";
    $params[':precio_max'] = $precio_max;
}
if ($disponibilidad_filtro === 'disponible') {
    // Disponible = tiene stock Y (no tiene fecha de caducidad, o todavía no vence).
    $condiciones[] = "p.stock > 0 AND (p.fecha_caducidad IS NULL OR p.fecha_caducidad >= CURDATE())";
} elseif ($disponibilidad_filtro === 'agotado') {
    $condiciones[] = "(p.stock <= 0 OR (p.fecha_caducidad IS NOT NULL AND p.fecha_caducidad < CURDATE()))";
}

// Unimos todas las condiciones con "AND" para formar el WHERE final.
$where = ' WHERE ' . implode(' AND ', $condiciones);

$limite = 8; // Cuántos productos se muestran por página.

// Contamos cuántos productos hay en total con estos filtros (para saber cuántas páginas hacen falta).
$sql_total = "SELECT COUNT(*) FROM productos p" . $where;
$stmt_total = $pdo->prepare($sql_total);
$stmt_total->execute($params);
$total_registros = (int) $stmt_total->fetchColumn();

$total_paginas = max(1, (int) ceil($total_registros / $limite));
if ($pagina > $total_paginas) {
    $pagina = $total_paginas;
}
$inicio_int = (int) (($pagina - 1) * $limite); // Desde qué fila empezar (para el LIMIT de SQL).
$limite_int = (int) $limite;

// Los productos de esta página, con el nombre de su categoría.
$sql_productos = "SELECT p.*, c.nombre AS categoria_nombre
                   FROM productos p
                   LEFT JOIN categorias c ON p.id_categoria = c.id_categoria"
                   . $where .
                   " ORDER BY p.nombre ASC
                   LIMIT $inicio_int, $limite_int";
$stmt_productos = $pdo->prepare($sql_productos);
$stmt_productos->execute($params);
$productos_tienda = $stmt_productos->fetchAll();
$fecha_hoy = date('Y-m-d');

$year = date("Y");
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OdontoNet — Tienda</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <link rel="stylesheet" href="assets/css/shared/tienda_compra.css">
    <link rel="stylesheet" href="assets/css/pages/tienda.css">
    <link rel="stylesheet" href="assets/css/shared/toast.css">
</head>

<body>

    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>
    <div class="glow glow-c"></div>
    <div class="grid-overlay"></div>

    <!-- NAVBAR -->
    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <!-- CARRITO FLOTANTE -->
    <a href="compra.php" class="carrito-flotante" title="Ver carrito" aria-label="Ver carrito (<?= $total_productos_carrito ?> producto(s))">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2 5h13M9 21a1 1 0 100-2 1 1 0 000 2zm8 0a1 1 0 100-2 1 1 0 000 2z" />
        </svg>
        <?php if ($total_productos_carrito > 0): ?>
            <span class="carrito-badge"><?= $total_productos_carrito ?></span>
        <?php endif; ?>
    </a>

    <section class="wrap dash-wide">
        <div class="dash-header">
            <div>
                <span class="eyebrow">Tienda dental</span>
                <h1>Productos de <span class="grad">cuidado dental</span></h1>
                <p>Encuentra productos recomendados para apoyar tu higiene oral diaria. Los productos pueden variar según la valoración del odontólogo.</p>
            </div>
        </div>
    </section>

    <section class="wrap dash-wide">

        <!-- LAYOUT: SIDEBAR DE FILTROS + CONTENIDO -->
        <form action="tienda.php" method="GET" class="dash-layout" id="formFiltros">

            <aside class="dash-sidebar">
                <h3>Filtros</h3>

                <div class="dash-filter-group">
                    <label for="categoria">Categoría</label>
                    <select name="categoria" id="categoria" class="form-control">
                        <option value="">Todas las categorías</option>
                        <?php foreach ($categorias_producto as $cat): ?>
                            <option value="<?= (int) $cat['id_categoria'] ?>" <?= $categoria_filtro === (int) $cat['id_categoria'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dash-filter-group">
                    <label>Rango de precio (₡)</label>
                    <div class="dash-price-range">
                        <input type="number" min="0" step="0.01" name="precio_min" placeholder="Mín." class="form-control" value="<?= $precio_min !== null ? htmlspecialchars((string) $precio_min) : '' ?>">
                        <span class="muted-text">—</span>
                        <input type="number" min="0" step="0.01" name="precio_max" placeholder="Máx." class="form-control" value="<?= $precio_max !== null ? htmlspecialchars((string) $precio_max) : '' ?>">
                    </div>
                </div>

                <div class="dash-filter-group">
                    <label for="disponibilidad">Disponibilidad</label>
                    <select name="disponibilidad" id="disponibilidad" class="form-control">
                        <option value="">Todos</option>
                        <option value="disponible" <?= $disponibilidad_filtro === 'disponible' ? 'selected' : '' ?>>Disponible</option>
                        <option value="agotado" <?= $disponibilidad_filtro === 'agotado' ? 'selected' : '' ?>>Agotado / vencido</option>
                    </select>
                </div>

                <div class="dash-sidebar-actions">
                    <button type="submit" class="btn-primary">Aplicar filtros</button>
                    <?php if ($hay_filtros_activos): ?>
                        <a href="tienda.php" class="btn-cancel">Limpiar filtros</a>
                    <?php endif; ?>
                </div>
            </aside>

            <main class="dash-main">
                <div class="dash-search">
                    <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar producto..." class="form-control">
                    <button type="submit" class="btn-primary">Buscar</button>
                </div>

                <p class="dash-results-count">
                    <?= $total_registros ?> producto(s) encontrado(s)<?= $hay_filtros_activos ? ' con los filtros aplicados' : '' ?>.
                </p>

                <?php if (count($productos_tienda) === 0): ?>

                    <p class="muted-text">No hay productos disponibles con estos filtros.</p>

                <?php else: ?>

                    <div class="productos-tienda">
                        <?php foreach ($productos_tienda as $prod): ?>

                            <?php
                                // Calculamos si este producto se puede seguir agregando al carrito.
                                $vencido = !empty($prod['fecha_caducidad']) && $prod['fecha_caducidad'] < $fecha_hoy;
                                $agotado = (int) $prod['stock'] <= 0;
                                $cantidad_en_carrito = $_SESSION['carrito'][$prod['id_producto']] ?? 0;
                                // También se bloquea si ya se agregaron al carrito todas las unidades que hay en stock.
                                $sin_disponibilidad = $vencido || $agotado || $cantidad_en_carrito >= (int) $prod['stock'];
                            ?>

                            <article class="producto-card">

                                <!-- Imagen del producto: la que subió el administrador desde inventario.php,
                                     o el logo como respaldo si el producto todavía no tiene foto. -->
                                <div class="producto-img">
                                    <img src="<?= !empty($prod['imagen']) ? htmlspecialchars($prod['imagen']) : 'assets/images/logos/logo-navbar.svg' ?>" alt="<?= htmlspecialchars($prod['nombre']) ?>" onerror="this.onerror=null;this.src='assets/images/logos/logo-navbar.svg';">
                                </div>

                                <!-- Información del producto -->
                                <div class="producto-info">

                                    <span class="producto-categoria"><?= htmlspecialchars($prod['categoria_nombre'] ?? 'Producto') ?></span>

                                    <h2><?= htmlspecialchars($prod['nombre']) ?></h2>

                                    <p>
                                        <?= htmlspecialchars($prod['descripcion'] ?? '') ?>
                                    </p>

                                    <div class="producto-accion">
                                        <strong class="producto-precio">₡<?= number_format($prod['precio_unitario'], 0, ',', '.') ?></strong>

                                        <?php if ($vencido): ?>
                                            <span class="badge badge-cancelada">Producto vencido</span>
                                        <?php elseif ($agotado): ?>
                                            <span class="badge badge-pendiente">Agotado</span>
                                        <?php elseif ((int) $prod['stock'] <= (int) $prod['stock_minimo']): ?>
                                            <span class="producto-stock producto-stock-bajo">
                                                Solo <?= (int) $prod['stock'] === 1 ? 'queda' : 'quedan' ?> <?= (int) $prod['stock'] ?> producto<?= (int) $prod['stock'] === 1 ? '' : 's' ?> en stock
                                            </span>
                                        <?php else: ?>
                                            <span class="producto-stock"><?= (int) $prod['stock'] ?> unidades disponibles</span>
                                        <?php endif; ?>

                                        <?php if ($sin_disponibilidad): ?>
                                            <button class="btn-primary" type="button" disabled>
                                                <?= $vencido ? 'No disponible' : 'Agotado' ?>
                                            </button>
                                        <?php else: ?>
                                            <a href="tienda.php?agregar=<?= (int) $prod['id_producto'] ?><?= $pagina > 1 ? '&pagina=' . $pagina : '' ?><?= $query_filtros ?>" class="btn-primary">
                                                Agregar al carrito
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                </div>

                            </article>

                        <?php endforeach; ?>
                    </div>

                    <?php if ($total_paginas > 1): ?>
                        <div class="pagination-container">
                            <span class="pagination-info">Página <strong><?= $pagina ?></strong> de <strong><?= $total_paginas ?></strong> (<?= $total_registros ?> productos)</span>
                            <div class="pagination">
                                <?php if ($pagina > 1): ?>
                                    <a href="tienda.php?pagina=<?= $pagina - 1 ?><?= $query_filtros ?>" class="page-link">&laquo; Anterior</a>
                                <?php endif; ?>
                                <?php foreach (obtener_paginas_visibles($pagina, $total_paginas) as $item): ?>
                                    <?php if ($item === '...'): ?>
                                        <span class="page-ellipsis">&hellip;</span>
                                    <?php else: ?>
                                        <a href="tienda.php?pagina=<?= $item ?><?= $query_filtros ?>" class="page-link <?= $item === $pagina ? 'active' : '' ?>"><?= $item ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if ($pagina < $total_paginas): ?>
                                    <a href="tienda.php?pagina=<?= $pagina + 1 ?><?= $query_filtros ?>" class="page-link">Siguiente &raquo;</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>
            </main>
        </form>
    </section>

    <!-- Footer -->
    <?php require_once 'assets/includes/partials/footer.php'; ?>

    <!-- NOTIFICACIÓN TEMPORAL (ver assets/js/shared/toast.js) -->
    <div id="toastNotificacion" class="toast-notification" role="alert" aria-live="assertive">
        <span class="toast-icon" aria-hidden="true"></span>
        <div class="toast-body">
            <strong id="toastTitulo"></strong>
            <p id="toastMensaje"></p>
        </div>
        <button type="button" class="toast-close" onclick="cerrarToast()" aria-label="Cerrar notificación">&times;</button>
    </div>

    <script src="assets/js/shared/toast.js"></script>
    <?php if (!empty($mensaje)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                mostrarToast(<?= json_encode($mensaje) ?>, <?= json_encode($tipo_mensaje) ?>);
            });
        </script>
    <?php endif; ?>

</body>

</html>
