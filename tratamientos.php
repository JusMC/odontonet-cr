<?php
/*
    tratamientos.php — Control de tratamientos odontológicos.

    Registro del catálogo de tratamientos (limpiezas, calzas, extracciones,
    ortodoncia, blanqueamientos, endodoncias, etc.): descripción, precio,
    duración aproximada, qué doctores pueden realizarlo y qué
    productos/instrumentos requiere habitualmente.

    Este catálogo es el que usan control_citas.php (para agendar),
    promociones.php (para armar paquetes) y facturar_servicios.php
    (para generar la factura del tratamiento).
*/

require_once __DIR__ . '/assets/includes/session_boot.php';
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/bitacora_helper.php';
require_once './assets/includes/helpers/paginacion_helper.php';

// Guard: administrador (id_rol = 4) o recepcionista (id_rol = 3).
if (!isset($_SESSION['id_rol']) || !in_array((int) $_SESSION['id_rol'], [3, 4], true)) {
    header('Location: login.php');
    exit;
}

$mensaje = "";
$tipo_mensaje = "";

// INACTIVAR / ACTIVAR TRATAMIENTO (soft-delete: activo = 0, nunca DELETE físico)
// Nunca se borra un tratamiento de verdad (DELETE); solo se marca activo=0 para no perder
// el historial de facturas viejas que lo usaron.
if (isset($_GET['accion']) && $_GET['accion'] === 'inactivar' && isset($_GET['id'])) {
    $id_inactivar = intval($_GET['id']);
    $pdo->prepare("UPDATE servicios SET activo = 0 WHERE id_servicio = :id")->execute([':id' => $id_inactivar]);
    registrar_bitacora($pdo, 'DELETE', "Se inactivó el tratamiento #$id_inactivar (soft-delete).");
    header("Location: tratamientos.php?msg=inactivado");
    exit;
}
if (isset($_GET['accion']) && $_GET['accion'] === 'activar' && isset($_GET['id'])) {
    $id_activar = intval($_GET['id']);
    $pdo->prepare("UPDATE servicios SET activo = 1 WHERE id_servicio = :id")->execute([':id' => $id_activar]);
    registrar_bitacora($pdo, 'UPDATE', "Se reactivó el tratamiento #$id_activar.");
    header("Location: tratamientos.php?msg=activado");
    exit;
}

if (isset($_GET['msg'])) {
    $mensajes_ok = [
        'guardado'     => 'Tratamiento registrado con éxito.',
        'actualizado'  => 'Tratamiento actualizado correctamente.',
        'inactivado'   => 'Tratamiento inactivado. Sigue disponible en el historial de facturas anteriores.',
        'activado'     => 'Tratamiento reactivado con éxito.',
    ];
    if (isset($mensajes_ok[$_GET['msg']])) {
        $mensaje = $mensajes_ok[$_GET['msg']];
        $tipo_mensaje = "exito";
    }
}

// MODO EDICIÓN
$modo_edicion = false;
$trat_edit = [
    'id_servicio'      => '',
    'id_categoria'     => '',
    'nombre'           => '',
    'descripcion'      => '',
    'precio'           => '',
    'duracion_minutos' => '60',
];
$doctores_incluidos_ids = [];
$productos_incluidos = []; // [id_producto => cantidad_requerida]

// Si viene un ?accion=editar&id=X en la URL, precargamos el modal con los datos de ese tratamiento.
if (isset($_GET['accion']) && $_GET['accion'] === 'editar' && isset($_GET['id'])) {
    $id_editar = intval($_GET['id']);
    $stmt_edit = $pdo->prepare("SELECT * FROM servicios WHERE id_servicio = :id");
    $stmt_edit->execute([':id' => $id_editar]);
    $datos = $stmt_edit->fetch();

    if ($datos) {
        $modo_edicion = true;
        $trat_edit = $datos;

        // Qué doctores ya estaban asignados a este tratamiento (para marcar sus checkboxes).
        $stmt_doc = $pdo->prepare("SELECT id_doctor FROM doctor_servicios WHERE id_servicio = :id");
        $stmt_doc->execute([':id' => $id_editar]);
        $doctores_incluidos_ids = array_map('intval', array_column($stmt_doc->fetchAll(), 'id_doctor'));

        // Qué productos/instrumentos ya estaban asignados, y en qué cantidad.
        $stmt_prod = $pdo->prepare("SELECT id_producto, cantidad_requerida FROM servicio_productos WHERE id_servicio = :id");
        $stmt_prod->execute([':id' => $id_editar]);
        foreach ($stmt_prod->fetchAll() as $fila) {
            $productos_incluidos[(int) $fila['id_producto']] = (int) $fila['cantidad_requerida'];
        }
    }
}

// PROCESAR FORMULARIO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_tratamiento'])) {
    $id_servicio      = isset($_POST['id_servicio']) ? intval($_POST['id_servicio']) : 0;
    $id_categoria     = intval($_POST['id_categoria'] ?? 0);
    $nombre           = trim($_POST['nombre'] ?? '');
    $descripcion      = trim($_POST['descripcion'] ?? '');
    $precio           = trim($_POST['precio'] ?? '');
    $duracion_minutos = intval($_POST['duracion_minutos'] ?? 0);
    $doctores_ids     = isset($_POST['doctores']) ? array_map('intval', (array) $_POST['doctores']) : [];
    $cantidades_post  = isset($_POST['cantidad_producto']) ? (array) $_POST['cantidad_producto'] : [];

    // Solo se guardan los productos con cantidad > 0 (los que quedaron en 0 no aplican a este tratamiento).
    $productos_seleccionados = [];
    foreach ($cantidades_post as $id_prod => $cantidad) {
        $id_prod = intval($id_prod);
        $cantidad = intval($cantidad);
        if ($id_prod > 0 && $cantidad > 0) {
            $productos_seleccionados[$id_prod] = $cantidad;
        }
    }

    $trat_edit = [
        'id_servicio'      => $id_servicio,
        'id_categoria'     => $id_categoria,
        'nombre'           => $nombre,
        'descripcion'      => $descripcion,
        'precio'           => $precio,
        'duracion_minutos' => $duracion_minutos,
    ];
    $doctores_incluidos_ids = $doctores_ids;
    $productos_incluidos = $productos_seleccionados;
    $modo_edicion = $id_servicio > 0;

    if ($nombre === '' || mb_strlen($nombre) > 150) {
        $mensaje = "El nombre del tratamiento es obligatorio y no puede exceder 150 caracteres.";
        $tipo_mensaje = "error";
    } elseif ($id_categoria <= 0) {
        $mensaje = "Selecciona una categoría.";
        $tipo_mensaje = "error";
    } elseif (!is_numeric($precio) || (float) $precio <= 0) {
        $mensaje = "Indica un precio válido (mayor a 0).";
        $tipo_mensaje = "error";
    } elseif ($duracion_minutos <= 0 || $duracion_minutos > 480) {
        $mensaje = "Indica una duración válida (entre 1 y 480 minutos, es decir hasta 8 horas).";
        $tipo_mensaje = "error";
    } elseif (empty($doctores_ids)) {
        $mensaje = "Selecciona al menos un odontólogo responsable del tratamiento.";
        $tipo_mensaje = "error";
    } else {
        // Sin errores de validación: guardamos.
        try {
            $pdo->beginTransaction();

            if ($id_servicio > 0) {
                // Ya existe: actualizamos sus datos.
                $stmt = $pdo->prepare(
                    "UPDATE servicios
                     SET id_categoria = :id_categoria, nombre = :nombre, descripcion = :descripcion,
                         precio = :precio, duracion_minutos = :duracion_minutos
                     WHERE id_servicio = :id"
                );
                $stmt->execute([
                    ':id_categoria'     => $id_categoria,
                    ':nombre'           => $nombre,
                    ':descripcion'      => $descripcion !== '' ? $descripcion : null,
                    ':precio'           => $precio,
                    ':duracion_minutos' => $duracion_minutos,
                    ':id'               => $id_servicio,
                ]);

                // Borramos sus asignaciones anteriores (doctores/productos) para volver a insertarlas desde cero abajo.
                $pdo->prepare("DELETE FROM doctor_servicios WHERE id_servicio = :id")->execute([':id' => $id_servicio]);
                $pdo->prepare("DELETE FROM servicio_productos WHERE id_servicio = :id")->execute([':id' => $id_servicio]);

                $accion_bitacora = 'UPDATE';
                $texto_bitacora  = "Se actualizó el tratamiento #$id_servicio ($nombre).";
            } else {
                // Es nuevo: lo insertamos.
                $stmt = $pdo->prepare(
                    "INSERT INTO servicios (id_categoria, nombre, descripcion, precio, duracion_minutos, activo)
                     VALUES (:id_categoria, :nombre, :descripcion, :precio, :duracion_minutos, 1)"
                );
                $stmt->execute([
                    ':id_categoria'     => $id_categoria,
                    ':nombre'           => $nombre,
                    ':descripcion'      => $descripcion !== '' ? $descripcion : null,
                    ':precio'           => $precio,
                    ':duracion_minutos' => $duracion_minutos,
                ]);
                $id_servicio = (int) $pdo->lastInsertId();

                $accion_bitacora = 'INSERT';
                $texto_bitacora  = "Se registró el tratamiento #$id_servicio ($nombre).";
            }

            // Insertamos de nuevo cada doctor asignado a este tratamiento.
            $stmt_doc = $pdo->prepare("INSERT INTO doctor_servicios (id_doctor, id_servicio) VALUES (:id_doctor, :id_servicio)");
            foreach (array_unique($doctores_ids) as $id_doctor) {
                $stmt_doc->execute([':id_doctor' => $id_doctor, ':id_servicio' => $id_servicio]);
            }

            // Y cada producto/instrumento que requiere, con su cantidad.
            $stmt_prod = $pdo->prepare("INSERT INTO servicio_productos (id_servicio, id_producto, cantidad_requerida) VALUES (:id_servicio, :id_producto, :cantidad)");
            foreach ($productos_seleccionados as $id_producto => $cantidad) {
                $stmt_prod->execute([':id_servicio' => $id_servicio, ':id_producto' => $id_producto, ':cantidad' => $cantidad]);
            }

            $pdo->commit();
            registrar_bitacora($pdo, $accion_bitacora, $texto_bitacora);

            header("Location: tratamientos.php?msg=" . ($modo_edicion ? 'actualizado' : 'guardado'));
            exit;

        } catch (\PDOException $e) {
            $pdo->rollBack();
            registrar_bitacora($pdo, 'ERROR', "Falló guardar el tratamiento" . ($modo_edicion ? " #$id_servicio" : " nuevo") . ": " . $e->getMessage());
            $mensaje = "Error al guardar el tratamiento: " . $e->getMessage();
            $tipo_mensaje = "error";
        }
    }
}

// CONSULTAS PARA LOS CAMPOS DESPLEGABLES
// Categorías de servicio, para el <select> del formulario y el filtro lateral.
$categorias_servicio = $pdo->query("SELECT id_categoria, nombre FROM categorias WHERE tipo = 'servicio' AND activo = 1 ORDER BY nombre ASC")->fetchAll();

$doctores_lista = $pdo->query("SELECT d.id_doctor, u.nombre, u.apellido, d.especialidad
                               FROM doctores d
                               INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
                               WHERE u.id_rol = 2 AND u.estado = 1 ORDER BY u.nombre ASC")->fetchAll();

$productos_lista = $pdo->query("SELECT id_producto, nombre FROM productos WHERE activo = 1 ORDER BY nombre ASC")->fetchAll();

// LÓGICA DE BÚSQUEDA, FILTROS (barra lateral) Y PAGINACIÓN
$busqueda         = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$categoria_filtro = isset($_GET['categoria']) ? intval($_GET['categoria']) : 0;
$estado_filtro    = isset($_GET['estado']) && in_array($_GET['estado'], ['1', '0'], true) ? $_GET['estado'] : '';
$precio_min       = isset($_GET['precio_min']) && $_GET['precio_min'] !== '' ? (float) $_GET['precio_min'] : null;
$precio_max       = isset($_GET['precio_max']) && $_GET['precio_max'] !== '' ? (float) $_GET['precio_max'] : null;

// Armamos la consulta SQL en pedazos ("condiciones"), agregando solo los filtros que el usuario realmente usó.
$condiciones = [];
$params = [];

if ($busqueda !== '') {
    $condiciones[] = "(s.nombre LIKE :b1 OR c.nombre LIKE :b2 OR s.descripcion LIKE :b3)";
    $param_valor = '%' . $busqueda . '%';
    $params[':b1'] = $param_valor;
    $params[':b2'] = $param_valor;
    $params[':b3'] = $param_valor;
}

if ($categoria_filtro > 0) {
    $condiciones[] = "s.id_categoria = :categoria";
    $params[':categoria'] = $categoria_filtro;
}

if ($estado_filtro !== '') {
    $condiciones[] = "s.activo = :estado";
    $params[':estado'] = (int) $estado_filtro;
}

if ($precio_min !== null) {
    $condiciones[] = "s.precio >= :precio_min";
    $params[':precio_min'] = $precio_min;
}

if ($precio_max !== null) {
    $condiciones[] = "s.precio <= :precio_max";
    $params[':precio_max'] = $precio_max;
}

// Unimos todas las condiciones activas con "AND" para formar el WHERE final.
$where_clause = count($condiciones) > 0 ? ' WHERE ' . implode(' AND ', $condiciones) : '';

$hay_filtros_activos = $busqueda !== '' || $categoria_filtro > 0 || $estado_filtro !== '' || $precio_min !== null || $precio_max !== null;

// Reconstruimos los filtros como texto de URL, para que los links de paginación no los pierdan.
$query_filtros = '';
if ($busqueda !== '') $query_filtros .= '&buscar=' . urlencode($busqueda);
if ($categoria_filtro > 0) $query_filtros .= '&categoria=' . $categoria_filtro;
if ($estado_filtro !== '') $query_filtros .= '&estado=' . urlencode($estado_filtro);
if ($precio_min !== null) $query_filtros .= '&precio_min=' . $precio_min;
if ($precio_max !== null) $query_filtros .= '&precio_max=' . $precio_max;

$limite = 5; // Cuántos tratamientos se muestran por página.
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

// Conteo total (sin el join de doctores: no multiplica filas, cuenta directo).
$sql_total = "SELECT COUNT(*) FROM servicios s LEFT JOIN categorias c ON s.id_categoria = c.id_categoria" . $where_clause;
$stmt_total = $pdo->prepare($sql_total);
$stmt_total->execute($params);
$total_registros = (int) $stmt_total->fetchColumn();

$total_paginas = max(1, (int) ceil($total_registros / $limite));
if ($pagina > $total_paginas) {
    $pagina = $total_paginas;
}
$inicio_int = (int) (($pagina - 1) * $limite); // Desde qué fila empezar (para el LIMIT de SQL).
$limite_int = (int) $limite;

// LISTADO DE TRATAMIENTOS
// GROUP_CONCAT junta en un solo texto los nombres de todos los doctores asignados a cada tratamiento.
$sql_lista = "SELECT s.*, c.nombre AS categoria_nombre,
                     GROUP_CONCAT(DISTINCT CONCAT('Dr(a). ', ud.nombre, ' ', ud.apellido) ORDER BY ud.nombre SEPARATOR ', ') AS doctores_nombres
              FROM servicios s
              LEFT JOIN categorias c ON s.id_categoria = c.id_categoria
              LEFT JOIN doctor_servicios ds ON s.id_servicio = ds.id_servicio
              LEFT JOIN doctores d2 ON ds.id_doctor = d2.id_doctor
              LEFT JOIN usuarios ud ON d2.id_usuario = ud.id_usuario"
              . $where_clause .
              " GROUP BY s.id_servicio
              ORDER BY s.id_servicio DESC
              LIMIT $inicio_int, $limite_int";
$stmt_lista = $pdo->prepare($sql_lista);
$stmt_lista->execute($params);
$lista_tratamientos = $stmt_lista->fetchAll();

$abrir_modal_formulario = $modo_edicion || ($tipo_mensaje === 'error' && isset($_POST['guardar_tratamiento']));
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tratamientos Odontológicos - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/home.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <!-- Estilos del formulario (compartidos con otras páginas) -->
    <link rel="stylesheet" href="assets/css/shared/gestionCitas.css">
    <!-- Estilos exclusivos del dashboard de tratamientos -->
    <link rel="stylesheet" href="assets/css/pages/tratamientos.css">
    <link rel="stylesheet" href="assets/css/shared/toast.css">
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
                <h1>Gestión de <span class="grad">Tratamientos</span></h1>
            </div>
        </div>
    </section>

    <section class="wrap dash-wide">

        <!-- LAYOUT: SIDEBAR DE FILTROS + CONTENIDO -->
        <form action="tratamientos.php" method="GET" class="dash-layout" id="formFiltros">

            <aside class="dash-sidebar">
                <h3>Filtros</h3>

                <div class="dash-filter-group">
                    <label for="categoria">Categoría</label>
                    <select name="categoria" id="categoria" class="form-control">
                        <option value="">Todas las categorías</option>
                        <?php foreach ($categorias_servicio as $cat): ?>
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
                    <label for="estado">Estado</label>
                    <select name="estado" id="estado" class="form-control">
                        <option value="">Todos</option>
                        <option value="1" <?= $estado_filtro === '1' ? 'selected' : '' ?>>Activo</option>
                        <option value="0" <?= $estado_filtro === '0' ? 'selected' : '' ?>>Inactivo</option>
                    </select>
                </div>

                <div class="dash-sidebar-actions">
                    <button type="submit" class="btn-primary">Aplicar filtros</button>
                    <?php if ($hay_filtros_activos): ?>
                        <a href="tratamientos.php" class="btn-cancel">Limpiar filtros</a>
                    <?php endif; ?>
                </div>
            </aside>

            <main class="dash-main">
                <div class="dash-search">
                    <button type="button" class="dash-fab-text" onclick="abrirModalTratamiento(true)">
                        <span class="dash-fab-text-plus" aria-hidden="true">
                            <span class="dash-fab-text-plus-icon"></span>
                        </span>
                        Registrar Tratamiento
                    </button>
                    <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por nombre, categoría o descripción..." class="form-control">
                    <button type="submit" class="btn-primary">Buscar</button>
                </div>

                <p class="dash-results-count">
                    <?= $total_registros ?> tratamiento(s) encontrado(s)<?= $hay_filtros_activos ? ' con los filtros aplicados' : '' ?>.
                </p>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Tratamiento</th>
                                <th>Categoría</th>
                                <th>Precio</th>
                                <th>Duración</th>
                                <th>Doctores</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($lista_tratamientos) > 0): ?>
                                <?php foreach ($lista_tratamientos as $trat): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($trat['nombre']) ?></strong></td>
                                        <td><?= htmlspecialchars($trat['categoria_nombre'] ?? 'Sin categoría') ?></td>
                                        <td>₡<?= number_format($trat['precio'], 0, ',', '.') ?></td>
                                        <td><?= (int) $trat['duracion_minutos'] ?> min</td>
                                        <td><?= htmlspecialchars($trat['doctores_nombres'] ?: 'Sin asignar') ?></td>
                                        <td>
                                            <span class="badge badge-<?= (int) $trat['activo'] === 1 ? 'success' : 'cancelada' ?>">
                                                <?= (int) $trat['activo'] === 1 ? 'Activo' : 'Inactivo' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="dash-actions">
                                                <a href="tratamientos.php?accion=editar&id=<?= $trat['id_servicio'] ?>" class="btn-action btn-edit">Editar</a>
                                                <?php if ((int) $trat['activo'] === 1): ?>
                                                    <a href="#" class="btn-action btn-delete" onclick="confirmarInactivacion('tratamientos.php?accion=inactivar&id=<?= $trat['id_servicio'] ?>', '<?= htmlspecialchars(addslashes($trat['nombre'])) ?>'); return false;">Inactivar</a>
                                                <?php else: ?>
                                                    <a href="tratamientos.php?accion=activar&id=<?= $trat['id_servicio'] ?>" class="btn-action btn-edit" style="background:rgba(46,204,113,0.15); color:#2ecc71; border-color:#2ecc71;">Activar</a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="muted-text text-center">No se encontraron tratamientos con estos filtros.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_paginas > 1): ?>
                    <div class="pagination-container">
                        <span class="pagination-info">Página <strong><?= $pagina ?></strong> de <strong><?= $total_paginas ?></strong> (<?= $total_registros ?> tratamientos)</span>
                        <div class="pagination">
                            <?php if ($pagina > 1): ?>
                                <a href="tratamientos.php?pagina=<?= $pagina - 1 ?><?= $query_filtros ?>" class="page-link">&laquo; Anterior</a>
                            <?php endif; ?>
                            <?php foreach (obtener_paginas_visibles($pagina, $total_paginas) as $item): ?>
                                <?php if ($item === '...'): ?>
                                    <span class="page-ellipsis">&hellip;</span>
                                <?php else: ?>
                                    <a href="tratamientos.php?pagina=<?= $item ?><?= $query_filtros ?>" class="page-link <?= $item === $pagina ? 'active' : '' ?>"><?= $item ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($pagina < $total_paginas): ?>
                                <a href="tratamientos.php?pagina=<?= $pagina + 1 ?><?= $query_filtros ?>" class="page-link">Siguiente &raquo;</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </form>
    </section>

    <!-- MODAL: REGISTRAR / EDITAR TRATAMIENTO -->
    <div id="modalTratamiento" class="dash-modal-overlay" data-abrir-automatico="<?= $abrir_modal_formulario ? '1' : '0' ?>">
        <div class="dash-modal-card">
            <div class="dash-modal-head">
                <button type="button" class="dash-modal-close" onclick="cerrarModalTratamiento()" aria-label="Cerrar">&times;</button>
                <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                    <span class="eyebrow" id="modalTratamientoEyebrow"><?= $modo_edicion ? 'Modificando Registro' : 'Nuevo Tratamiento' ?></span>
                    <h2 id="modalTratamientoTitulo" style="margin:4px 0 0;"><?= $modo_edicion ? 'Editar tratamiento' : 'Registrar Tratamiento' ?></h2>
                </div>
            </div>

            <form action="tratamientos.php" method="POST" id="formTratamiento">
                <input type="hidden" name="guardar_tratamiento" value="1">
                <input type="hidden" id="id_servicio" name="id_servicio" value="<?= htmlspecialchars($trat_edit['id_servicio']) ?>">

                <div class="form-grid">
                    <div class="form-group col-full">
                        <label for="nombre"><span class="dash-req">*</span> Nombre del tratamiento</label>
                        <input type="text" id="nombre" name="nombre" maxlength="150" required placeholder="Ej: Limpieza dental" value="<?= htmlspecialchars($trat_edit['nombre']) ?>" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="id_categoria"><span class="dash-req">*</span> Categoría</label>
                        <select name="id_categoria" id="id_categoria" required class="form-control">
                            <option value="">-- Selecciona --</option>
                            <?php foreach ($categorias_servicio as $cat): ?>
                                <option value="<?= $cat['id_categoria'] ?>" <?= (int) $trat_edit['id_categoria'] === (int) $cat['id_categoria'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="precio"><span class="dash-req">*</span> Precio <span class="dash-hint">(₡)</span></label>
                        <input type="number" step="0.01" min="0" id="precio" name="precio" required value="<?= htmlspecialchars($trat_edit['precio']) ?>" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="duracion_minutos"><span class="dash-req">*</span> Duración <span class="dash-hint">(minutos)</span></label>
                        <input type="number" min="1" max="480" id="duracion_minutos" name="duracion_minutos" required value="<?= htmlspecialchars($trat_edit['duracion_minutos']) ?>" class="form-control" title="Entre 1 y 480 minutos (hasta 8 horas).">
                    </div>

                    <div class="form-group">
                        <label for="descripcion">Descripción <span class="dash-hint">(opcional)</span></label>
                        <textarea id="descripcion" name="descripcion" rows="1" placeholder="Detalle del procedimiento..." class="form-control"><?= htmlspecialchars($trat_edit['descripcion'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group col-full">
                        <label><span class="dash-req">*</span> Odontólogos responsables</label>
                        <?php if (count($doctores_lista) === 0): ?>
                            <p class="muted-text">No hay doctores activos registrados.</p>
                        <?php else: ?>
                            <div class="checklist-caja" id="checklistDoctores">
                                <?php foreach ($doctores_lista as $doc): ?>
                                    <label>
                                        <input type="checkbox" name="doctores[]" value="<?= $doc['id_doctor'] ?>" <?= in_array((int) $doc['id_doctor'], $doctores_incluidos_ids, true) ? 'checked' : '' ?>>
                                        Dr(a). <?= htmlspecialchars($doc['nombre'] . ' ' . $doc['apellido']) ?> — <?= htmlspecialchars($doc['especialidad']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group col-full">
                        <label>Productos/instrumentos necesarios <span class="dash-hint">(opcional — indica la cantidad habitual)</span></label>
                        <?php if (count($productos_lista) === 0): ?>
                            <p class="muted-text">No hay productos activos registrados en Inventario.</p>
                        <?php else: ?>
                            <div class="checklist-caja" id="checklistProductos">
                                <?php foreach ($productos_lista as $prod): ?>
                                    <div class="producto-cantidad-fila">
                                        <span><?= htmlspecialchars($prod['nombre']) ?></span>
                                        <input type="number" min="0" name="cantidad_producto[<?= $prod['id_producto'] ?>]" value="<?= $productos_incluidos[$prod['id_producto']] ?? 0 ?>" placeholder="0" class="form-control">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModalTratamiento()">Cancelar</button>
                    <button type="submit" class="btn-primary btn-guardar" id="btnGuardarTratamiento">
                        <?= $modo_edicion ? 'Actualizar Tratamiento' : 'Guardar Tratamiento' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: CONFIRMAR INACTIVACIÓN -->
    <div id="modalConfirmacion" class="custom-modal-overlay">
        <div class="custom-modal-card">
            <h3>¿Inactivar tratamiento?</h3>
            <p id="modalMensajeTexto">¿Estás seguro de que deseas inactivar este tratamiento? Podrás reactivarlo después.</p>
            <div class="custom-modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="cerrarModal()">Cancelar</button>
                <a id="btnConfirmarModal" href="#" class="btn-modal-confirm">Inactivar</a>
            </div>
        </div>
    </div>

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
    <script src="assets/js/pages/tratamientos.js"></script>

</body>
</html>
