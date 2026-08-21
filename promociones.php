<?php
/*
    promociones.php — Control de promociones y paquetes odontológicos.

    Permite a recepción o administración crear paquetes de servicios (ej. limpieza +
    revisión a un precio combinado) o descuentos (porcentuales o fijos)
    sobre tratamientos específicos, con vigencia por fecha. Estas
    promociones luego se aplican desde facturar_servicios.php al generar
    la factura de un tratamiento.
*/

session_start();
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/bitacora_helper.php';
require_once './assets/includes/helpers/paginacion_helper.php';

// Guard: administrador (id_rol = 4) o recepcionista (id_rol = 3) administran promociones.
if (!isset($_SESSION['id_rol']) || !in_array((int) $_SESSION['id_rol'], [3, 4], true)) {
    header('Location: login.php');
    exit;
}

$mensaje = "";
$tipo_mensaje = "";

// INACTIVAR / ACTIVAR PROMOCIÓN
// Nunca se borra de verdad (DELETE); solo se marca activo=0/1, para conservar el historial de facturas que la usaron.
if (isset($_GET['accion']) && $_GET['accion'] === 'inactivar' && isset($_GET['id'])) {
    $id_inactivar = intval($_GET['id']);
    $stmt = $pdo->prepare("UPDATE promociones SET activo = 0 WHERE id_promocion = :id");
    $stmt->execute([':id' => $id_inactivar]);
    registrar_bitacora($pdo, 'DELETE', "Se inactivó la promoción #$id_inactivar (soft-delete).");
    header("Location: promociones.php?msg=inactivada");
    exit;
}
if (isset($_GET['accion']) && $_GET['accion'] === 'activar' && isset($_GET['id'])) {
    $id_activar = intval($_GET['id']);
    $stmt = $pdo->prepare("UPDATE promociones SET activo = 1 WHERE id_promocion = :id");
    $stmt->execute([':id' => $id_activar]);
    registrar_bitacora($pdo, 'UPDATE', "Se reactivó la promoción #$id_activar.");
    header("Location: promociones.php?msg=activada");
    exit;
}

if (isset($_GET['msg'])) {
    $mensajes_ok = [
        'guardada'    => 'Promoción guardada con éxito.',
        'actualizada' => 'Promoción actualizada correctamente.',
        'inactivada'  => 'Promoción inactivada.',
        'activada'    => 'Promoción reactivada con éxito.',
    ];
    if (isset($mensajes_ok[$_GET['msg']])) {
        $mensaje = $mensajes_ok[$_GET['msg']];
        $tipo_mensaje = "exito";
    }
}

// MODO EDICIÓN
$modo_edicion = false;
$promo_edit = [
    'id_promocion'    => '',
    'nombre'          => '',
    'descripcion'     => '',
    'tipo'            => 'paquete',
    'valor_descuento' => '',
    'precio_paquete'  => '',
    'fecha_inicio'    => date('Y-m-d'),
    'fecha_fin'       => '',
];
$servicios_incluidos_ids = [];

// Si viene un ?accion=editar&id=X en la URL, precargamos el modal con los datos de esa promoción.
if (isset($_GET['accion']) && $_GET['accion'] === 'editar' && isset($_GET['id'])) {
    $id_editar = intval($_GET['id']);
    $stmt_edit = $pdo->prepare("SELECT * FROM promociones WHERE id_promocion = :id");
    $stmt_edit->execute([':id' => $id_editar]);
    $datos = $stmt_edit->fetch();

    if ($datos) {
        $modo_edicion = true;
        $promo_edit = $datos;

        // Qué servicios ya estaban incluidos en esta promoción (para marcar sus checkboxes).
        $stmt_serv = $pdo->prepare("SELECT id_servicio FROM promocion_servicios WHERE id_promocion = :id");
        $stmt_serv->execute([':id' => $id_editar]);
        $servicios_incluidos_ids = array_map('intval', array_column($stmt_serv->fetchAll(), 'id_servicio'));
    }
}

// PROCESAR FORMULARIO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_promocion'])) {
    $id_promocion     = isset($_POST['id_promocion']) ? intval($_POST['id_promocion']) : 0;
    $nombre           = trim($_POST['nombre']);
    $descripcion      = trim($_POST['descripcion'] ?? '');
    $tipo             = $_POST['tipo'] ?? '';
    $valor_descuento  = trim($_POST['valor_descuento'] ?? '');
    $precio_paquete   = trim($_POST['precio_paquete'] ?? '');
    $fecha_inicio     = $_POST['fecha_inicio'] ?? '';
    $fecha_fin        = trim($_POST['fecha_fin'] ?? '');
    $servicios_ids    = isset($_POST['servicios']) ? array_map('intval', (array) $_POST['servicios']) : [];

    $promo_edit = [
        'id_promocion'    => $id_promocion,
        'nombre'          => $nombre,
        'descripcion'     => $descripcion,
        'tipo'            => $tipo,
        'valor_descuento' => $valor_descuento,
        'precio_paquete'  => $precio_paquete,
        'fecha_inicio'    => $fecha_inicio,
        'fecha_fin'       => $fecha_fin,
    ];
    $servicios_incluidos_ids = $servicios_ids;
    $modo_edicion = $id_promocion > 0;

    $tipos_validos = ['paquete', 'descuento_porcentual', 'descuento_fijo'];

    // Validaciones en cadena: la primera que falle define el mensaje de error.
    if ($nombre === '' || mb_strlen($nombre) > 150) {
        $mensaje = "El nombre es obligatorio y no puede exceder 150 caracteres.";
        $tipo_mensaje = "error";
    } elseif (!in_array($tipo, $tipos_validos, true)) {
        $mensaje = "Selecciona un tipo de promoción válido.";
        $tipo_mensaje = "error";
    } elseif (count($servicios_ids) === 0) {
        $mensaje = "Selecciona al menos un servicio/tratamiento para la promoción.";
        $tipo_mensaje = "error";
    } elseif (empty($fecha_inicio)) {
        $mensaje = "La fecha de inicio es obligatoria.";
        $tipo_mensaje = "error";
    } elseif ($fecha_fin !== '' && $fecha_fin < $fecha_inicio) {
        $mensaje = "La fecha de fin no puede ser anterior a la fecha de inicio.";
        $tipo_mensaje = "error";
    } elseif ($tipo === 'paquete' && (!is_numeric($precio_paquete) || (float) $precio_paquete <= 0)) {
        $mensaje = "Indica el precio combinado del paquete (mayor a 0).";
        $tipo_mensaje = "error";
    } elseif ($tipo === 'descuento_porcentual' && (!is_numeric($valor_descuento) || (float) $valor_descuento <= 0 || (float) $valor_descuento > 100)) {
        $mensaje = "El descuento porcentual debe ser un número entre 1 y 100.";
        $tipo_mensaje = "error";
    } elseif ($tipo === 'descuento_fijo' && (!is_numeric($valor_descuento) || (float) $valor_descuento <= 0)) {
        $mensaje = "Indica el monto fijo de descuento (mayor a 0).";
        $tipo_mensaje = "error";
    } else {
        // Sin errores de validación: guardamos.
        try {
            $pdo->beginTransaction();

            // Un paquete usa "precio_paquete" y no tiene "valor_descuento" (y viceversa para los descuentos).
            $valor_final  = $tipo === 'paquete' ? null : (float) $valor_descuento;
            $precio_final = $tipo === 'paquete' ? (float) $precio_paquete : null;
            $fecha_fin_final = $fecha_fin !== '' ? $fecha_fin : null;

            if ($id_promocion > 0) {
                // Ya existe: actualizamos sus datos.
                $stmt = $pdo->prepare(
                    "UPDATE promociones
                     SET nombre = :nombre, descripcion = :descripcion, tipo = :tipo,
                         valor_descuento = :valor_descuento, precio_paquete = :precio_paquete,
                         fecha_inicio = :fecha_inicio, fecha_fin = :fecha_fin
                     WHERE id_promocion = :id"
                );
                $stmt->execute([
                    ':nombre'          => $nombre,
                    ':descripcion'     => $descripcion !== '' ? $descripcion : null,
                    ':tipo'            => $tipo,
                    ':valor_descuento' => $valor_final,
                    ':precio_paquete'  => $precio_final,
                    ':fecha_inicio'    => $fecha_inicio,
                    ':fecha_fin'       => $fecha_fin_final,
                    ':id'              => $id_promocion,
                ]);

                // Borramos los servicios incluidos anteriores, para volver a insertarlos desde cero abajo.
                $pdo->prepare("DELETE FROM promocion_servicios WHERE id_promocion = :id")->execute([':id' => $id_promocion]);

                $accion_bitacora = 'UPDATE';
                $texto_bitacora  = "Se actualizó la promoción #$id_promocion ($nombre).";
            } else {
                // Es nueva: la insertamos.
                $stmt = $pdo->prepare(
                    "INSERT INTO promociones (nombre, descripcion, tipo, valor_descuento, precio_paquete, fecha_inicio, fecha_fin, activo)
                     VALUES (:nombre, :descripcion, :tipo, :valor_descuento, :precio_paquete, :fecha_inicio, :fecha_fin, 1)"
                );
                $stmt->execute([
                    ':nombre'          => $nombre,
                    ':descripcion'     => $descripcion !== '' ? $descripcion : null,
                    ':tipo'            => $tipo,
                    ':valor_descuento' => $valor_final,
                    ':precio_paquete'  => $precio_final,
                    ':fecha_inicio'    => $fecha_inicio,
                    ':fecha_fin'       => $fecha_fin_final,
                ]);
                $id_promocion = (int) $pdo->lastInsertId();

                $accion_bitacora = 'INSERT';
                $texto_bitacora  = "Se registró la promoción #$id_promocion ($nombre).";
            }

            // Insertamos de nuevo cada servicio incluido en esta promoción.
            $stmt_link = $pdo->prepare("INSERT INTO promocion_servicios (id_promocion, id_servicio) VALUES (:id_promocion, :id_servicio)");
            foreach (array_unique($servicios_ids) as $id_servicio) {
                $stmt_link->execute([':id_promocion' => $id_promocion, ':id_servicio' => $id_servicio]);
            }

            $pdo->commit();
            registrar_bitacora($pdo, $accion_bitacora, $texto_bitacora);

            header("Location: promociones.php?msg=" . ($modo_edicion ? 'actualizada' : 'guardada'));
            exit;

        } catch (\PDOException $e) {
            $pdo->rollBack();
            registrar_bitacora($pdo, 'ERROR', "Falló guardar la promoción" . ($modo_edicion ? " #$id_promocion" : " nueva") . ": " . $e->getMessage());
            $mensaje = "Error al guardar la promoción: " . $e->getMessage();
            $tipo_mensaje = "error";
        }
    }
}

// CONSULTAR SERVICIOS ACTIVOS (para el selector de la promoción)
$servicios_lista = $pdo->query("SELECT id_servicio, nombre, precio FROM servicios WHERE activo = 1 ORDER BY nombre ASC")->fetchAll();

// Nombre legible de cada tipo de promoción, para mostrar en la tabla y el filtro.
$etiquetas_tipo = [
    'paquete'               => 'Paquete',
    'descuento_porcentual'  => 'Descuento %',
    'descuento_fijo'        => 'Descuento fijo',
];

// LÓGICA DE BÚSQUEDA, FILTROS (barra lateral) Y PAGINACIÓN
$busqueda      = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$tipo_filtro   = isset($_GET['tipo']) && in_array($_GET['tipo'], ['paquete', 'descuento_porcentual', 'descuento_fijo'], true) ? $_GET['tipo'] : '';
$estado_filtro = isset($_GET['estado']) && in_array($_GET['estado'], ['activa', 'programada', 'vencida', 'inactiva'], true) ? $_GET['estado'] : '';

// Armamos la consulta SQL en pedazos ("condiciones"), agregando solo los filtros que el usuario realmente usó.
$condiciones = [];
$params = [];

if ($busqueda !== '') {
    $condiciones[] = "(p.nombre LIKE :b1 OR p.descripcion LIKE :b2)";
    $param_valor = '%' . $busqueda . '%';
    $params[':b1'] = $param_valor;
    $params[':b2'] = $param_valor;
}

if ($tipo_filtro !== '') {
    $condiciones[] = "p.tipo = :tipo";
    $params[':tipo'] = $tipo_filtro;
}

// "Estado" de una promoción no es una sola columna: se calcula combinando "activo" con las fechas de vigencia.
if ($estado_filtro === 'inactiva') {
    $condiciones[] = "p.activo = 0";
} elseif ($estado_filtro === 'vencida') {
    $condiciones[] = "p.activo = 1 AND p.fecha_fin IS NOT NULL AND p.fecha_fin < CURDATE()";
} elseif ($estado_filtro === 'activa') {
    $condiciones[] = "p.activo = 1 AND p.fecha_inicio <= CURDATE() AND (p.fecha_fin IS NULL OR p.fecha_fin >= CURDATE())";
} elseif ($estado_filtro === 'programada') {
    $condiciones[] = "p.activo = 1 AND p.fecha_inicio > CURDATE() AND (p.fecha_fin IS NULL OR p.fecha_fin >= CURDATE())";
}

// Unimos todas las condiciones activas con "AND" para formar el WHERE final.
$where_clause = count($condiciones) > 0 ? ' WHERE ' . implode(' AND ', $condiciones) : '';

$hay_filtros_activos = $busqueda !== '' || $tipo_filtro !== '' || $estado_filtro !== '';

// Reconstruimos los filtros como texto de URL, para que los links de paginación no los pierdan.
$query_filtros = '';
if ($busqueda !== '') $query_filtros .= '&buscar=' . urlencode($busqueda);
if ($tipo_filtro !== '') $query_filtros .= '&tipo=' . urlencode($tipo_filtro);
if ($estado_filtro !== '') $query_filtros .= '&estado=' . urlencode($estado_filtro);

$limite = 5; // Cuántas promociones se muestran por página.
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

// Conteo total (sin el join de servicios: no multiplica filas, cuenta directo).
$sql_total = "SELECT COUNT(*) FROM promociones p" . $where_clause;
$stmt_total = $pdo->prepare($sql_total);
$stmt_total->execute($params);
$total_registros = (int) $stmt_total->fetchColumn();

$total_paginas = max(1, (int) ceil($total_registros / $limite));
if ($pagina > $total_paginas) {
    $pagina = $total_paginas;
}
$inicio_int = (int) (($pagina - 1) * $limite); // Desde qué fila empezar (para el LIMIT de SQL).
$limite_int = (int) $limite;

// LISTADO DE PROMOCIONES
// GROUP_CONCAT junta en un solo texto los nombres de todos los servicios incluidos en cada promoción.
$sql_promos = "SELECT p.*,
                      GROUP_CONCAT(s.nombre ORDER BY s.nombre SEPARATOR ', ') AS servicios_nombres
               FROM promociones p
               LEFT JOIN promocion_servicios ps ON p.id_promocion = ps.id_promocion
               LEFT JOIN servicios s ON ps.id_servicio = s.id_servicio"
               . $where_clause .
               " GROUP BY p.id_promocion
               ORDER BY p.id_promocion DESC
               LIMIT $inicio_int, $limite_int";
$stmt_promos = $pdo->prepare($sql_promos);
$stmt_promos->execute($params);
$lista_promociones = $stmt_promos->fetchAll();

$fecha_hoy = date('Y-m-d'); // Usada abajo para calcular si cada promoción está vigente, vencida o programada.

$abrir_modal_formulario = $modo_edicion || ($tipo_mensaje === 'error' && isset($_POST['guardar_promocion']));
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Promociones y Paquetes - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/home.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <!-- Estilos del formulario (compartidos con otras páginas) -->
    <link rel="stylesheet" href="assets/css/shared/gestionCitas.css">
    <!-- Estilos exclusivos del dashboard de promociones -->
    <link rel="stylesheet" href="assets/css/pages/promociones.css">
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
                <h1>Promociones y <span class="grad">Paquetes</span></h1>
            </div>
        </div>
    </section>

    <section class="wrap dash-wide">

        <!-- LAYOUT: SIDEBAR DE FILTROS + CONTENIDO -->
        <form action="promociones.php" method="GET" class="dash-layout" id="formFiltros">

            <aside class="dash-sidebar">
                <h3>Filtros</h3>

                <div class="dash-filter-group">
                    <label for="tipo_filtro">Tipo</label>
                    <select name="tipo" id="tipo_filtro" class="form-control">
                        <option value="">Todos los tipos</option>
                        <?php foreach ($etiquetas_tipo as $valor => $etiqueta): ?>
                            <option value="<?= $valor ?>" <?= $tipo_filtro === $valor ? 'selected' : '' ?>><?= $etiqueta ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dash-filter-group">
                    <label for="estado">Estado</label>
                    <select name="estado" id="estado" class="form-control">
                        <option value="">Todos</option>
                        <option value="activa" <?= $estado_filtro === 'activa' ? 'selected' : '' ?>>Activa</option>
                        <option value="programada" <?= $estado_filtro === 'programada' ? 'selected' : '' ?>>Programada</option>
                        <option value="vencida" <?= $estado_filtro === 'vencida' ? 'selected' : '' ?>>Vencida</option>
                        <option value="inactiva" <?= $estado_filtro === 'inactiva' ? 'selected' : '' ?>>Inactiva</option>
                    </select>
                </div>

                <div class="dash-sidebar-actions">
                    <button type="submit" class="btn-primary">Aplicar filtros</button>
                    <?php if ($hay_filtros_activos): ?>
                        <a href="promociones.php" class="btn-cancel">Limpiar filtros</a>
                    <?php endif; ?>
                </div>
            </aside>

            <main class="dash-main">
                <div class="dash-search">
                    <button type="button" class="dash-fab-text" onclick="abrirModalPromocion(true)">
                        <span class="dash-fab-text-plus" aria-hidden="true">
                            <span class="dash-fab-text-plus-icon"></span>
                        </span>
                        Crear Promoción
                    </button>
                    <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por nombre o descripción..." class="form-control">
                    <button type="submit" class="btn-primary">Buscar</button>
                </div>

                <p class="dash-results-count">
                    <?= $total_registros ?> promoción(es) encontrada(s)<?= $hay_filtros_activos ? ' con los filtros aplicados' : '' ?>.
                </p>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Tipo</th>
                                <th>Servicios incluidos</th>
                                <th>Vigencia</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($lista_promociones) > 0): ?>
                                <?php foreach ($lista_promociones as $promo): ?>
                                    <?php
                                        // "Vigente" = activa y la fecha de hoy cae dentro de su rango de fechas.
                                        $vigente = (int) $promo['activo'] === 1
                                            && $promo['fecha_inicio'] <= $fecha_hoy
                                            && (empty($promo['fecha_fin']) || $promo['fecha_fin'] >= $fecha_hoy);
                                        // "Vencida" = ya pasó su fecha de fin (sin importar si sigue activa en la base de datos).
                                        $vencida = !empty($promo['fecha_fin']) && $promo['fecha_fin'] < $fecha_hoy;
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($promo['nombre']) ?></strong></td>
                                        <td>
                                            <span class="badge badge-paciente"><?= $etiquetas_tipo[$promo['tipo']] ?? $promo['tipo'] ?></span>
                                            <br>
                                            <small class="muted-text">
                                                <?php if ($promo['tipo'] === 'paquete'): ?>
                                                    ₡<?= number_format($promo['precio_paquete'], 0, ',', '.') ?> combinado
                                                <?php elseif ($promo['tipo'] === 'descuento_porcentual'): ?>
                                                    <?= (float) $promo['valor_descuento'] ?>% de descuento
                                                <?php else: ?>
                                                    ₡<?= number_format($promo['valor_descuento'], 0, ',', '.') ?> de descuento
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td><?= htmlspecialchars($promo['servicios_nombres'] ?: 'Sin servicios') ?></td>
                                        <td>
                                            <?= date('d/m/Y', strtotime($promo['fecha_inicio'])) ?> —
                                            <?= !empty($promo['fecha_fin']) ? date('d/m/Y', strtotime($promo['fecha_fin'])) : 'Sin fin' ?>
                                        </td>
                                        <td>
                                            <?php if (!$promo['activo']): ?>
                                                <span class="badge badge-cancelada">Inactiva</span>
                                            <?php elseif ($vencida): ?>
                                                <span class="badge badge-cancelada">Vencida</span>
                                            <?php elseif ($vigente): ?>
                                                <span class="badge badge-completada">Activa</span>
                                            <?php else: ?>
                                                <span class="badge badge-pendiente">Programada</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="dash-actions">
                                                <a href="promociones.php?accion=editar&id=<?= $promo['id_promocion'] ?>" class="btn-action btn-edit">Editar</a>
                                                <?php if ((int) $promo['activo'] === 1): ?>
                                                    <a href="#" class="btn-action btn-delete" onclick="confirmarInactivacion('promociones.php?accion=inactivar&id=<?= $promo['id_promocion'] ?>', '<?= htmlspecialchars(addslashes($promo['nombre'])) ?>'); return false;">Inactivar</a>
                                                <?php else: ?>
                                                    <a href="promociones.php?accion=activar&id=<?= $promo['id_promocion'] ?>" class="btn-action btn-edit" style="background:rgba(46,204,113,0.15); color:#2ecc71; border-color:#2ecc71;">Activar</a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="muted-text text-center">No se encontraron promociones con estos filtros.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_paginas > 1): ?>
                    <div class="pagination-container">
                        <span class="pagination-info">Página <strong><?= $pagina ?></strong> de <strong><?= $total_paginas ?></strong> (<?= $total_registros ?> promociones)</span>
                        <div class="pagination">
                            <?php if ($pagina > 1): ?>
                                <a href="promociones.php?pagina=<?= $pagina - 1 ?><?= $query_filtros ?>" class="page-link">&laquo; Anterior</a>
                            <?php endif; ?>
                            <?php foreach (obtener_paginas_visibles($pagina, $total_paginas) as $item): ?>
                                <?php if ($item === '...'): ?>
                                    <span class="page-ellipsis">&hellip;</span>
                                <?php else: ?>
                                    <a href="promociones.php?pagina=<?= $item ?><?= $query_filtros ?>" class="page-link <?= $item === $pagina ? 'active' : '' ?>"><?= $item ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($pagina < $total_paginas): ?>
                                <a href="promociones.php?pagina=<?= $pagina + 1 ?><?= $query_filtros ?>" class="page-link">Siguiente &raquo;</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </form>
    </section>

    <!-- MODAL: REGISTRAR / EDITAR PROMOCIÓN -->
    <div id="modalPromocion" class="dash-modal-overlay" data-abrir-automatico="<?= $abrir_modal_formulario ? '1' : '0' ?>" data-fecha-hoy="<?= htmlspecialchars($fecha_hoy) ?>">
        <div class="dash-modal-card">
            <div class="dash-modal-head">
                <button type="button" class="dash-modal-close" onclick="cerrarModalPromocion()" aria-label="Cerrar">&times;</button>
                <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                    <span class="eyebrow" id="modalPromocionEyebrow"><?= $modo_edicion ? 'Modificando Registro' : 'Nueva Promoción' ?></span>
                    <h2 id="modalPromocionTitulo" style="margin:4px 0 0;"><?= $modo_edicion ? 'Editar promoción' : 'Crear Promoción o Paquete' ?></h2>
                </div>
            </div>

            <form action="promociones.php" method="POST" id="formPromocion">
                <input type="hidden" name="guardar_promocion" value="1">
                <input type="hidden" id="id_promocion" name="id_promocion" value="<?= htmlspecialchars($promo_edit['id_promocion']) ?>">

                <div class="form-grid">
                    <div class="form-group col-full">
                        <label for="nombre"><span class="dash-req">*</span> Nombre</label>
                        <input type="text" id="nombre" name="nombre" maxlength="150" required placeholder="Ej: Paquete Limpieza + Revisión" value="<?= htmlspecialchars($promo_edit['nombre']) ?>" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="tipo"><span class="dash-req">*</span> Tipo de promoción</label>
                        <select name="tipo" id="tipo" required onchange="actualizarCamposTipo()" class="form-control">
                            <option value="paquete" <?= $promo_edit['tipo'] === 'paquete' ? 'selected' : '' ?>>Paquete (precio combinado)</option>
                            <option value="descuento_porcentual" <?= $promo_edit['tipo'] === 'descuento_porcentual' ? 'selected' : '' ?>>Descuento porcentual (%)</option>
                            <option value="descuento_fijo" <?= $promo_edit['tipo'] === 'descuento_fijo' ? 'selected' : '' ?>>Descuento fijo (₡)</option>
                        </select>
                    </div>

                    <div id="campo_precio_paquete" class="campo-condicional form-group">
                        <label for="precio_paquete"><span class="dash-req">*</span> Precio combinado <span class="dash-hint">(₡)</span></label>
                        <input type="number" step="0.01" min="0" id="precio_paquete" name="precio_paquete" value="<?= htmlspecialchars($promo_edit['precio_paquete']) ?>" class="form-control">
                    </div>
                    <div id="campo_valor_descuento" class="campo-condicional form-group">
                        <label for="valor_descuento" id="label_valor_descuento"><span class="dash-req">*</span> Valor del descuento</label>
                        <input type="number" step="0.01" min="0" id="valor_descuento" name="valor_descuento" value="<?= htmlspecialchars($promo_edit['valor_descuento']) ?>" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="fecha_inicio"><span class="dash-req">*</span> Fecha de inicio</label>
                        <input type="date" id="fecha_inicio" name="fecha_inicio" required value="<?= htmlspecialchars($promo_edit['fecha_inicio']) ?>" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="fecha_fin">Fecha de fin <span class="dash-hint">(opcional — sin límite si se deja vacío)</span></label>
                        <input type="date" id="fecha_fin" name="fecha_fin" value="<?= htmlspecialchars($promo_edit['fecha_fin']) ?>" class="form-control">
                    </div>

                    <div class="form-group col-full">
                        <label for="descripcion">Descripción <span class="dash-hint">(opcional)</span></label>
                        <textarea id="descripcion" name="descripcion" rows="1" placeholder="Ej: Válida para tratamientos familiares durante diciembre." class="form-control"><?= htmlspecialchars($promo_edit['descripcion']) ?></textarea>
                    </div>

                    <div class="form-group col-full">
                        <label><span class="dash-req">*</span> Servicios/tratamientos incluidos</label>
                        <?php if (count($servicios_lista) === 0): ?>
                            <p class="muted-text">No hay servicios activos registrados todavía.</p>
                        <?php else: ?>
                            <div class="checklist-caja" id="checklistServicios">
                                <?php foreach ($servicios_lista as $srv): ?>
                                    <label>
                                        <input type="checkbox" name="servicios[]" value="<?= $srv['id_servicio'] ?>" <?= in_array((int) $srv['id_servicio'], $servicios_incluidos_ids, true) ? 'checked' : '' ?>>
                                        <?= htmlspecialchars($srv['nombre']) ?> <span class="dash-hint">(₡<?= number_format($srv['precio'], 0, ',', '.') ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModalPromocion()">Cancelar</button>
                    <button type="submit" class="btn-primary btn-guardar" id="btnGuardarPromocion">
                        <?= $modo_edicion ? 'Actualizar Promoción' : 'Guardar Promoción' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: CONFIRMAR INACTIVACIÓN -->
    <div id="modalConfirmacion" class="custom-modal-overlay">
        <div class="custom-modal-card">
            <h3>¿Inactivar promoción?</h3>
            <p id="modalMensajeTexto">¿Estás seguro de que deseas inactivar esta promoción? Podrás reactivarla después.</p>
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
    <script src="assets/js/pages/promociones.js"></script>

</body>
</html>
