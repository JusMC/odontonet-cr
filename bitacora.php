<?php
// bitacora.php — Consulta de la bitácora de auditoría del sistema (solo administradores).

session_start();
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/paginacion_helper.php';

/* Guard de rol: solo el administrador (id_rol = 4) puede acceder a esta página.
   Cualquier otro usuario (o alguien sin sesión) es devuelto al login. */
if (!isset($_SESSION['id_rol']) || (int) $_SESSION['id_rol'] !== 4) {
    header('Location: login.php');
    exit;
}

// ACCIONES VÁLIDAS (deben coincidir con el ENUM de la columna bitacora.accion)
$acciones_validas = ['INSERT', 'UPDATE', 'DELETE', 'ERROR', 'LOGIN', 'LOGOUT', 'ALERTA'];

/**
 * ocultar_ids() — Quita las referencias a IDs internos (ej: "#12") del texto
 * antes de mostrarlo. El dato crudo se conserva intacto en la base de datos;
 * esto es solo una limpieza a nivel de vista para no exponer IDs de usuario.
 */
function ocultar_ids(string $texto): string
{
    $texto = preg_replace('/\s*\(#\d+\)/', '', $texto); // Quita cosas como " (#12)".
    $texto = preg_replace('/#\d+\s*/', '', $texto);      // Quita cosas como "#12 " sueltas.
    return trim(preg_replace('/\s{2,}/', ' ', $texto));  // Limpia espacios dobles que hayan quedado.
}

// LÓGICA DE FILTROS, BÚSQUEDA Y PAGINACIÓN
$busqueda     = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$accion_filtro = isset($_GET['accion']) && in_array($_GET['accion'], $acciones_validas, true) ? $_GET['accion'] : '';
$fecha_desde  = isset($_GET['desde']) ? trim($_GET['desde']) : '';
$fecha_hasta  = isset($_GET['hasta']) ? trim($_GET['hasta']) : '';

$limite = 5;
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

// Armamos la consulta SQL en pedazos ("condiciones"), agregando solo los filtros que el usuario realmente usó.
$condiciones = [];
$params = [];

if ($busqueda !== '') {
    // Buscamos el texto tanto en la descripción del evento como en el nombre/apellido de quien lo hizo.
    $condiciones[] = "(b.descripcion LIKE :b1 OR u.nombre LIKE :b2 OR u.apellido LIKE :b3)";
    $param_valor = '%' . $busqueda . '%';
    $params[':b1'] = $param_valor;
    $params[':b2'] = $param_valor;
    $params[':b3'] = $param_valor;
}

if ($accion_filtro !== '') {
    $condiciones[] = "b.accion = :accion";
    $params[':accion'] = $accion_filtro;
}

if ($fecha_desde !== '') {
    $condiciones[] = "b.fecha_hora >= :desde";
    $params[':desde'] = $fecha_desde . ' 00:00:00';
}

if ($fecha_hasta !== '') {
    $condiciones[] = "b.fecha_hora <= :hasta";
    $params[':hasta'] = $fecha_hasta . ' 23:59:59';
}

// Unimos todas las condiciones activas con "AND" para formar el WHERE final (o ninguno, si no hay filtros).
$where_clause = count($condiciones) > 0 ? ' WHERE ' . implode(' AND ', $condiciones) : '';

// Conteo total para la paginación
$sql_total = "SELECT COUNT(*) FROM bitacora b LEFT JOIN usuarios u ON b.id_usuario = u.id_usuario" . $where_clause;
$stmt_total = $pdo->prepare($sql_total);
$stmt_total->execute($params);
$total_registros = (int) $stmt_total->fetchColumn();

$total_paginas = max(1, (int) ceil($total_registros / $limite));
if ($pagina > $total_paginas) {
    // Si pidieron una página que ya no existe, la ajustamos a la última disponible.
    $pagina = $total_paginas;
}

$inicio_int = (int) (($pagina - 1) * $limite); // Desde qué fila empezar (para el LIMIT de SQL).
$limite_int = (int) $limite;

// Consulta de bitácora paginada
$sql_bitacora = "SELECT b.*, u.nombre, u.apellido, u.correo
                  FROM bitacora b
                  LEFT JOIN usuarios u ON b.id_usuario = u.id_usuario"
                  . $where_clause .
                  " ORDER BY b.fecha_hora DESC
                  LIMIT $inicio_int, $limite_int";

$stmt_bitacora = $pdo->prepare($sql_bitacora);
$stmt_bitacora->execute($params);
$lista_bitacora = $stmt_bitacora->fetchAll();

// Parámetros de filtro para conservarlos en los enlaces de paginación
// Reconstruimos los filtros como texto de URL, para que los links de página siguiente/anterior no los pierdan.
$query_filtros = '';
if ($busqueda !== '') $query_filtros .= '&buscar=' . urlencode($busqueda);
if ($accion_filtro !== '') $query_filtros .= '&accion=' . urlencode($accion_filtro);
if ($fecha_desde !== '') $query_filtros .= '&desde=' . urlencode($fecha_desde);
if ($fecha_hasta !== '') $query_filtros .= '&hasta=' . urlencode($fecha_hasta);
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bitácora del Sistema - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/home.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <link rel="stylesheet" href="assets/css/pages/bitacora.css">
</head>

<body>

    <div class="grid-overlay"></div>
    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>

    <!-- NAVBAR -->
    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <section class="wrap dash-wide">
        <div class="dash-header">
            <div>
                <span class="eyebrow">Trazabilidad</span>
                <h1>Bitácora <span class="grad">del Sistema</span></h1>
            </div>
        </div>
    </section>

    <section class="wrap dash-wide">

        <!-- LAYOUT: SIDEBAR DE FILTROS + CONTENIDO -->
        <form action="bitacora.php" method="GET" class="dash-layout" id="formFiltros">

            <aside class="dash-sidebar">
                <h3>Filtros</h3>

                <div class="dash-filter-group">
                    <label for="accion">Acción</label>
                    <select name="accion" id="accion" class="form-control">
                        <option value="">Todas las acciones</option>
                        <?php foreach ($acciones_validas as $acc): ?>
                            <option value="<?= $acc ?>" <?= $accion_filtro === $acc ? 'selected' : '' ?>><?= $acc ?></option>
                        <?php endforeach; ?>
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
                    <?php if ($busqueda !== '' || $accion_filtro !== '' || $fecha_desde !== '' || $fecha_hasta !== ''): ?>
                        <a href="bitacora.php" class="btn-cancel">Limpiar filtros</a>
                    <?php endif; ?>
                </div>
            </aside>

            <main class="dash-main">
                <div class="dash-search">
                    <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por descripción o usuario..." class="form-control">
                    <button type="submit" class="btn-primary">Buscar</button>
                </div>

                <p class="dash-results-count">
                    <?= $total_registros ?> registro(s) encontrado(s)<?= ($busqueda !== '' || $accion_filtro !== '' || $fecha_desde !== '' || $fecha_hasta !== '') ? ' con los filtros aplicados' : '' ?>.
                </p>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha y Hora</th>
                                <th>Usuario</th>
                                <th>Acción</th>
                                <th>Descripción</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($lista_bitacora) > 0): ?>
                                <?php foreach ($lista_bitacora as $registro): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date('d/m/Y H:i:s', strtotime($registro['fecha_hora']))) ?></td>
                                        <td>
                                            <?php if (!empty($registro['nombre'])): ?>
                                                <?= htmlspecialchars($registro['nombre'] . ' ' . $registro['apellido']) ?>
                                            <?php else: ?>
                                                <span class="muted-text">Sistema</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-accion-<?= strtolower(htmlspecialchars($registro['accion'])) ?>">
                                                <?= htmlspecialchars($registro['accion']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars(ocultar_ids($registro['descripcion'] ?? '')) ?></td>
                                        <td><small class="muted-text"><?= htmlspecialchars($registro['ip_origen'] ?? 'N/A') ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="muted-text text-center">No se encontraron registros en la bitácora.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_paginas > 1): ?>
                    <div class="pagination-container">
                        <span class="pagination-info">Página <strong><?= $pagina ?></strong> de <strong><?= $total_paginas ?></strong> (<?= $total_registros ?> registros)</span>
                        <div class="pagination">
                            <?php if ($pagina > 1): ?>
                                <a href="bitacora.php?pagina=<?= $pagina - 1 ?><?= $query_filtros ?>" class="page-link">&laquo; Anterior</a>
                            <?php endif; ?>
                            <?php foreach (obtener_paginas_visibles($pagina, $total_paginas) as $item): ?>
                                <?php if ($item === '...'): ?>
                                    <span class="page-ellipsis">&hellip;</span>
                                <?php else: ?>
                                    <a href="bitacora.php?pagina=<?= $item ?><?= $query_filtros ?>" class="page-link <?= $item === $pagina ? 'active' : '' ?>"><?= $item ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($pagina < $total_paginas): ?>
                                <a href="bitacora.php?pagina=<?= $pagina + 1 ?><?= $query_filtros ?>" class="page-link">Siguiente &raquo;</a>
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
