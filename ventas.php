<?php
/*
    ventas.php — Ventas realizadas (dashboard, solo lectura, solo administrador).

    Muestra todas las ventas registradas en el sistema (facturadas desde
    facturar_servicios.php o compra.php), con filtros por estado, método
    de pago y fecha, buscador y paginación. Cada fila permite volver a
    generar/ver el comprobante (ver_factura.php), que también refleja el
    estado de los abonos cuando la venta tuvo un plan de pago parcial.
*/

require_once __DIR__ . '/assets/includes/session_boot.php';
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/paginacion_helper.php';

// Guard: solo administrador.
if (!isset($_SESSION['id_rol']) || (int) $_SESSION['id_rol'] !== 4) {
    header('Location: login.php');
    exit;
}

// LÓGICA DE BÚSQUEDA, FILTROS (barra lateral) Y PAGINACIÓN
$busqueda      = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$estado_filtro = isset($_GET['estado']) && in_array($_GET['estado'], ['pendiente', 'pagada', 'anulada'], true) ? $_GET['estado'] : '';
$metodo_filtro = isset($_GET['metodo']) && in_array($_GET['metodo'], ['efectivo', 'tarjeta', 'transferencia'], true) ? $_GET['metodo'] : '';
$fecha_desde   = isset($_GET['desde']) ? trim($_GET['desde']) : '';
$fecha_hasta   = isset($_GET['hasta']) ? trim($_GET['hasta']) : '';

// Armamos la consulta SQL en pedazos ("condiciones"), agregando solo los filtros que el usuario realmente usó.
$condiciones = [];
$params = [];

if ($busqueda !== '') {
    // Se puede buscar por nombre, cédula del paciente, o directamente por número de factura (si escribió solo dígitos).
    $condiciones[] = "(p.nombre_completo LIKE :b1 OR p.cedula LIKE :b2 OR v.id_venta = :b3)";
    $params[':b1'] = '%' . $busqueda . '%';
    $params[':b2'] = '%' . $busqueda . '%';
    $params[':b3'] = ctype_digit($busqueda) ? (int) $busqueda : 0;
}
if ($estado_filtro !== '') {
    $condiciones[] = "v.estado = :estado";
    $params[':estado'] = $estado_filtro;
}
if ($metodo_filtro !== '') {
    $condiciones[] = "v.metodo_pago = :metodo";
    $params[':metodo'] = $metodo_filtro;
}
if ($fecha_desde !== '') {
    $condiciones[] = "v.fecha_venta >= :desde";
    $params[':desde'] = $fecha_desde . ' 00:00:00';
}
if ($fecha_hasta !== '') {
    $condiciones[] = "v.fecha_venta <= :hasta";
    $params[':hasta'] = $fecha_hasta . ' 23:59:59';
}

// Unimos todas las condiciones activas con "AND" para formar el WHERE final (o ninguno, si no hay filtros).
$where = count($condiciones) > 0 ? ' WHERE ' . implode(' AND ', $condiciones) : '';

$hay_filtros_activos = $busqueda !== '' || $estado_filtro !== '' || $metodo_filtro !== '' || $fecha_desde !== '' || $fecha_hasta !== '';

// Reconstruimos los filtros como texto de URL, para que los links de paginación no los pierdan.
$query_filtros = '';
if ($busqueda !== '') $query_filtros .= '&buscar=' . urlencode($busqueda);
if ($estado_filtro !== '') $query_filtros .= '&estado=' . urlencode($estado_filtro);
if ($metodo_filtro !== '') $query_filtros .= '&metodo=' . urlencode($metodo_filtro);
if ($fecha_desde !== '') $query_filtros .= '&desde=' . urlencode($fecha_desde);
if ($fecha_hasta !== '') $query_filtros .= '&hasta=' . urlencode($fecha_hasta);

$limite = 5; // Cuántas ventas se muestran por página.
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

// Contamos cuántas ventas hay en total con estos filtros (para saber cuántas páginas hacen falta).
$sql_total = "SELECT COUNT(*)
              FROM ventas v
              JOIN v_pacientes_admin p ON v.id_paciente = p.id_paciente"
              . $where;
$stmt_total = $pdo->prepare($sql_total);
$stmt_total->execute($params);
$total_registros = (int) $stmt_total->fetchColumn();

$total_paginas = max(1, (int) ceil($total_registros / $limite));
if ($pagina > $total_paginas) {
    $pagina = $total_paginas;
}
$inicio_int = (int) (($pagina - 1) * $limite); // Desde qué fila empezar (para el LIMIT de SQL).
$limite_int = (int) $limite;

// Traemos las ventas de esta página, junto con el nombre del paciente y si tienen un plan de pago parcial.
$sql_ventas = "SELECT v.id_venta, v.fecha_venta, v.total, v.metodo_pago, v.estado,
                      p.nombre_completo AS paciente_nombre, p.cedula AS paciente_cedula,
                      (pp.id_pago_pendiente IS NOT NULL) AS tiene_plan_pago,
                      pp.estado AS estado_plan_pago
               FROM ventas v
               JOIN v_pacientes_admin p ON v.id_paciente = p.id_paciente
               LEFT JOIN pagos_pendientes pp ON v.id_venta = pp.id_venta"
               . $where .
               " ORDER BY v.fecha_venta DESC
               LIMIT $inicio_int, $limite_int";
$stmt_ventas = $pdo->prepare($sql_ventas);
$stmt_ventas->execute($params);
$lista_ventas = $stmt_ventas->fetchAll();

// Total facturado con los filtros aplicados (referencia rápida, no es un KPI aparte).
$sql_suma = "SELECT COALESCE(SUM(v.total), 0)
             FROM ventas v
             JOIN v_pacientes_admin p ON v.id_paciente = p.id_paciente"
             . $where;
$stmt_suma = $pdo->prepare($sql_suma);
$stmt_suma->execute($params);
$total_facturado = (float) $stmt_suma->fetchColumn();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventas Realizadas - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/home.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <!-- Estilos exclusivos del dashboard de ventas -->
    <link rel="stylesheet" href="assets/css/pages/ventas.css">
</head>

<body>

    <div class="grid-overlay"></div>
    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>

    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <section class="wrap dash-wide">
        <div class="dash-header">
            <div>
                <span class="eyebrow">Dashboard</span>
                <h1>Ventas <span class="grad">Realizadas</span></h1>
            </div>
        </div>
    </section>

    <section class="wrap dash-wide">

        <!-- LAYOUT: SIDEBAR DE FILTROS + CONTENIDO -->
        <form action="ventas.php" method="GET" class="dash-layout" id="formFiltros">

            <aside class="dash-sidebar">
                <h3>Filtros</h3>

                <div class="dash-filter-group">
                    <label for="estado">Estado</label>
                    <select name="estado" id="estado" class="form-control">
                        <option value="">Todos</option>
                        <option value="pendiente" <?= $estado_filtro === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                        <option value="pagada" <?= $estado_filtro === 'pagada' ? 'selected' : '' ?>>Pagada</option>
                        <option value="anulada" <?= $estado_filtro === 'anulada' ? 'selected' : '' ?>>Anulada</option>
                    </select>
                </div>

                <div class="dash-filter-group">
                    <label for="metodo">Método de pago</label>
                    <select name="metodo" id="metodo" class="form-control">
                        <option value="">Todos</option>
                        <option value="efectivo" <?= $metodo_filtro === 'efectivo' ? 'selected' : '' ?>>Efectivo</option>
                        <option value="tarjeta" <?= $metodo_filtro === 'tarjeta' ? 'selected' : '' ?>>Tarjeta</option>
                        <option value="transferencia" <?= $metodo_filtro === 'transferencia' ? 'selected' : '' ?>>Transferencia</option>
                    </select>
                </div>

                <div class="dash-filter-group">
                    <label for="desde">Fecha desde</label>
                    <input type="date" name="desde" id="desde" class="form-control" value="<?= htmlspecialchars($fecha_desde) ?>">
                </div>

                <div class="dash-filter-group">
                    <label for="hasta">Fecha hasta</label>
                    <input type="date" name="hasta" id="hasta" class="form-control" value="<?= htmlspecialchars($fecha_hasta) ?>">
                </div>

                <div class="dash-sidebar-actions">
                    <button type="submit" class="btn-primary">Aplicar filtros</button>
                    <?php if ($hay_filtros_activos): ?>
                        <a href="ventas.php" class="btn-cancel">Limpiar filtros</a>
                    <?php endif; ?>
                </div>
            </aside>

            <main class="dash-main">
                <div class="dash-search">
                    <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por paciente, cédula o N° de factura..." class="form-control">
                    <button type="submit" class="btn-primary">Buscar</button>
                </div>

                <p class="dash-results-count">
                    <?= $total_registros ?> venta(s) encontrada(s)<?= $hay_filtros_activos ? ' con los filtros aplicados' : '' ?> — total facturado: ₡<?= number_format($total_facturado, 0, ',', '.') ?>.
                </p>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Factura</th>
                                <th>Paciente</th>
                                <th>Fecha</th>
                                <th>Método de pago</th>
                                <th>Total</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($lista_ventas) > 0): ?>
                                <?php foreach ($lista_ventas as $v): ?>
                                    <tr>
                                        <td>#<?= str_pad($v['id_venta'], 6, '0', STR_PAD_LEFT) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($v['paciente_nombre']) ?></strong><br>
                                            <small style="color: var(--muted);">Ced: <?= htmlspecialchars($v['paciente_cedula'] ?? 'N/A') ?></small>
                                        </td>
                                        <td><?= date('d/m/Y H:i', strtotime($v['fecha_venta'])) ?></td>
                                        <td><?= ucfirst($v['metodo_pago']) ?></td>
                                        <td>₡<?= number_format($v['total'], 0, ',', '.') ?></td>
                                        <td>
                                            <span class="badge badge-<?= $v['estado'] === 'pagada' ? 'completada' : ($v['estado'] === 'anulada' ? 'cancelada' : 'pendiente') ?>">
                                                <?= ucfirst($v['estado']) ?>
                                            </span>
                                            <?php if ($v['tiene_plan_pago']): ?>
                                                <br><small style="color: var(--muted);">Con abonos (<?= htmlspecialchars(ucfirst($v['estado_plan_pago'])) ?>)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="ver_factura.php?id=<?= (int) $v['id_venta'] ?>" target="_blank" rel="noopener" class="btn-action btn-edit">Ver comprobante</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="muted-text text-center">No se encontraron ventas con estos filtros.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_paginas > 1): ?>
                    <div class="pagination-container">
                        <span class="pagination-info">Página <strong><?= $pagina ?></strong> de <strong><?= $total_paginas ?></strong> (<?= $total_registros ?> ventas)</span>
                        <div class="pagination">
                            <?php if ($pagina > 1): ?>
                                <a href="ventas.php?pagina=<?= $pagina - 1 ?><?= $query_filtros ?>" class="page-link">&laquo; Anterior</a>
                            <?php endif; ?>
                            <?php foreach (obtener_paginas_visibles($pagina, $total_paginas) as $item): ?>
                                <?php if ($item === '...'): ?>
                                    <span class="page-ellipsis">&hellip;</span>
                                <?php else: ?>
                                    <a href="ventas.php?pagina=<?= $item ?><?= $query_filtros ?>" class="page-link <?= $item === $pagina ? 'active' : '' ?>"><?= $item ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($pagina < $total_paginas): ?>
                                <a href="ventas.php?pagina=<?= $pagina + 1 ?><?= $query_filtros ?>" class="page-link">Siguiente &raquo;</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </form>
    </section>

    <?php require_once 'assets/includes/partials/footer.php'; ?>

</body>
</html>
