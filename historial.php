<?php

session_start();
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/paginacion_helper.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php?redirect=historial.php');
    exit;
}
if (!isset($_SESSION['paciente_id'])) {
    $destino = match((int)($_SESSION['id_rol'] ?? 0)) {
        4 => 'admin.php',
        2, 3 => 'control_citas.php',
        default => 'login.php',
    };
    header('Location: ' . $destino);
    exit;
}

$usuario_id = (int) ($_SESSION['usuario_id'] ?? 0);
$paciente_id = (int) ($_SESSION['paciente_id'] ?? 0);
$nombre = $_SESSION['nombre'] ?? 'Paciente';

// Da formato legible (día/mes/año hora:minuto) a una fecha de la base de datos.
function format_date_time($value) {
    if (!$value) {
        return 'No registrado';
    }
    $date = new DateTime($value);
    return $date->format('d/m/Y H:i');
}

// Igual, pero solo con la fecha (sin hora).
function format_date($value) {
    if (!$value) {
        return 'No registrado';
    }
    $date = new DateTime($value);
    return $date->format('d/m/Y');
}

// VISTA ACTIVA: cada una tiene sus propios filtros/paginación.
// Esta página tiene 3 pestañas (citas, notas médicas, exámenes); cada una consulta una tabla distinta.
$vista = isset($_GET['vista']) && in_array($_GET['vista'], ['citas', 'notas', 'examenes'], true) ? $_GET['vista'] : 'citas';

$busqueda      = trim($_GET['buscar'] ?? '');
$estado_filtro = trim($_GET['estado'] ?? '');
$fecha_desde   = trim($_GET['desde'] ?? '');
$fecha_hasta   = trim($_GET['hasta'] ?? '');

$limite = 5;
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

$condiciones = [];
$params = [':id_paciente' => $paciente_id];

// PESTAÑA "CITAS": trae las citas del paciente, con filtros de estado y fecha.
if ($vista === 'citas') {
    $estados_validos = ['pendiente', 'en_espera', 'en_atencion', 'completada', 'cancelada', 'vencida'];
    if ($estado_filtro !== '' && !in_array($estado_filtro, $estados_validos, true)) {
        $estado_filtro = '';
    }

    $condiciones[] = "c.id_paciente = :id_paciente";
    if ($estado_filtro !== '') {
        $condiciones[] = "c.estado = :estado";
        $params[':estado'] = $estado_filtro;
    }
    if ($fecha_desde !== '') {
        $condiciones[] = "c.fecha_hora >= :desde";
        $params[':desde'] = $fecha_desde . ' 00:00:00';
    }
    if ($fecha_hasta !== '') {
        $condiciones[] = "c.fecha_hora <= :hasta";
        $params[':hasta'] = $fecha_hasta . ' 23:59:59';
    }
    if ($busqueda !== '') {
        $condiciones[] = "(u.nombre LIKE :b1 OR u.apellido LIKE :b2 OR c.motivo LIKE :b3)";
        $pv = '%' . $busqueda . '%';
        $params[':b1'] = $pv;
        $params[':b2'] = $pv;
        $params[':b3'] = $pv;
    }
    $where = ' WHERE ' . implode(' AND ', $condiciones);

    $sql_total = "SELECT COUNT(*) FROM citas c
                  INNER JOIN doctores d ON d.id_doctor = c.id_doctor
                  INNER JOIN usuarios u ON u.id_usuario = d.id_usuario" . $where;
    $stmt_total = $pdo->prepare($sql_total);
    $stmt_total->execute($params);
    $total_registros = (int) $stmt_total->fetchColumn();

    $total_paginas = max(1, (int) ceil($total_registros / $limite));
    if ($pagina > $total_paginas) $pagina = $total_paginas;
    $inicio_int = (int) (($pagina - 1) * $limite);
    $limite_int = (int) $limite;

    $sql = "SELECT c.fecha_hora, c.motivo, c.estado, u.nombre AS doctor_nombre, u.apellido AS doctor_apellido
            FROM citas c
            INNER JOIN doctores d ON d.id_doctor = c.id_doctor
            INNER JOIN usuarios u ON u.id_usuario = d.id_usuario"
            . $where .
            " ORDER BY c.fecha_hora DESC
            LIMIT $inicio_int, $limite_int";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

// PESTAÑA "NOTAS MÉDICAS": trae las entradas del historial clínico (diagnóstico, tratamiento, etc.).
} elseif ($vista === 'notas') {
    $estado_filtro = ''; // Las notas médicas no tienen concepto de "estado".

    $condiciones[] = "hm.id_paciente = :id_paciente";
    if ($fecha_desde !== '') {
        $condiciones[] = "hm.fecha_revision >= :desde";
        $params[':desde'] = $fecha_desde . ' 00:00:00';
    }
    if ($fecha_hasta !== '') {
        $condiciones[] = "hm.fecha_revision <= :hasta";
        $params[':hasta'] = $fecha_hasta . ' 23:59:59';
    }
    if ($busqueda !== '') {
        $condiciones[] = "(u.nombre LIKE :b1 OR u.apellido LIKE :b2 OR hm.diagnostico LIKE :b3 OR hm.tratamiento LIKE :b4)";
        $pv = '%' . $busqueda . '%';
        $params[':b1'] = $pv;
        $params[':b2'] = $pv;
        $params[':b3'] = $pv;
        $params[':b4'] = $pv;
    }
    $where = ' WHERE ' . implode(' AND ', $condiciones);

    $sql_total = "SELECT COUNT(*) FROM historial_medico hm
                  INNER JOIN doctores d ON d.id_doctor = hm.id_doctor
                  INNER JOIN usuarios u ON u.id_usuario = d.id_usuario" . $where;
    $stmt_total = $pdo->prepare($sql_total);
    $stmt_total->execute($params);
    $total_registros = (int) $stmt_total->fetchColumn();

    $total_paginas = max(1, (int) ceil($total_registros / $limite));
    if ($pagina > $total_paginas) $pagina = $total_paginas;
    $inicio_int = (int) (($pagina - 1) * $limite);
    $limite_int = (int) $limite;

    $sql = "SELECT hm.fecha_revision, hm.diagnostico, hm.tratamiento, hm.medicamentos, hm.observaciones, hm.proxima_revision,
                   u.nombre AS doctor_nombre, u.apellido AS doctor_apellido
            FROM historial_medico hm
            INNER JOIN doctores d ON d.id_doctor = hm.id_doctor
            INNER JOIN usuarios u ON u.id_usuario = d.id_usuario"
            . $where .
            " ORDER BY hm.fecha_revision DESC
            LIMIT $inicio_int, $limite_int";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

// PESTAÑA "EXÁMENES": trae los exámenes/radiografías solicitados a este paciente.
} else { // examenes
    $estados_validos = ['solicitado', 'realizado', 'cancelado'];
    if ($estado_filtro !== '' && !in_array($estado_filtro, $estados_validos, true)) {
        $estado_filtro = '';
    }

    $condiciones[] = "e.id_paciente = :id_paciente";
    if ($estado_filtro !== '') {
        $condiciones[] = "e.estado = :estado";
        $params[':estado'] = $estado_filtro;
    }
    if ($fecha_desde !== '') {
        $condiciones[] = "e.fecha_solicitud >= :desde";
        $params[':desde'] = $fecha_desde;
    }
    if ($fecha_hasta !== '') {
        $condiciones[] = "e.fecha_solicitud <= :hasta";
        $params[':hasta'] = $fecha_hasta;
    }
    if ($busqueda !== '') {
        $condiciones[] = "(e.tipo_examen LIKE :b1 OR u.nombre LIKE :b2 OR u.apellido LIKE :b3)";
        $pv = '%' . $busqueda . '%';
        $params[':b1'] = $pv;
        $params[':b2'] = $pv;
        $params[':b3'] = $pv;
    }
    $where = ' WHERE ' . implode(' AND ', $condiciones);

    $sql_total = "SELECT COUNT(*) FROM examenes e
                  INNER JOIN doctores d ON d.id_doctor = e.id_doctor
                  INNER JOIN usuarios u ON u.id_usuario = d.id_usuario" . $where;
    $stmt_total = $pdo->prepare($sql_total);
    $stmt_total->execute($params);
    $total_registros = (int) $stmt_total->fetchColumn();

    $total_paginas = max(1, (int) ceil($total_registros / $limite));
    if ($pagina > $total_paginas) $pagina = $total_paginas;
    $inicio_int = (int) (($pagina - 1) * $limite);
    $limite_int = (int) $limite;

    $sql = "SELECT e.id_examen, e.tipo_examen, e.fecha_solicitud, e.estado, e.resultado, e.archivo,
                   u.nombre AS doctor_nombre, u.apellido AS doctor_apellido
            FROM examenes e
            INNER JOIN doctores d ON d.id_doctor = e.id_doctor
            INNER JOIN usuarios u ON u.id_usuario = d.id_usuario"
            . $where .
            " ORDER BY e.fecha_solicitud DESC
            LIMIT $inicio_int, $limite_int";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
}

$hay_filtros_activos = $busqueda !== '' || $estado_filtro !== '' || $fecha_desde !== '' || $fecha_hasta !== '';

// Reconstruimos los filtros (incluida la pestaña activa) como texto de URL, para los links de paginación.
$query_filtros = '&vista=' . $vista;
if ($busqueda !== '') $query_filtros .= '&buscar=' . urlencode($busqueda);
if ($estado_filtro !== '') $query_filtros .= '&estado=' . urlencode($estado_filtro);
if ($fecha_desde !== '') $query_filtros .= '&desde=' . urlencode($fecha_desde);
if ($fecha_hasta !== '') $query_filtros .= '&hasta=' . urlencode($fecha_hasta);

// Textos que cambian según la pestaña activa (título, placeholder del buscador, unidad de conteo).
$config_vista = [
    'citas'    => ['titulo' => 'Últimas citas', 'placeholder' => 'Buscar por doctor o motivo...', 'unidad' => 'cita(s)'],
    'notas'    => ['titulo' => 'Notas médicas', 'placeholder' => 'Buscar por doctor, diagnóstico o tratamiento...', 'unidad' => 'nota(s) médica(s)'],
    'examenes' => ['titulo' => 'Radiografías y exámenes', 'placeholder' => 'Buscar por tipo de examen o doctor...', 'unidad' => 'examen(es)'],
];
// El estado del examen usa otras palabras que el de las citas; aquí lo traducimos a las mismas clases CSS de badge.
$mapa_badge_examen = ['solicitado' => 'pendiente', 'realizado' => 'completada', 'cancelado' => 'cancelada'];
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OdontoNet — Historial</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <!-- Estilos exclusivos del dashboard de historial -->
    <link rel="stylesheet" href="assets/css/pages/historial.css">
</head>

<body>

    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>
    <div class="glow glow-c"></div>
    <div class="grid-overlay"></div>

    <!-- NAVBAR -->
    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <section class="wrap dash-wide">
        <div class="dash-header">
            <span class="eyebrow">Historial médico</span>
            <h1>Todo tu historial y tus citas</h1>
            <p>Revisa las consultas que ya tuviste, las notas médicas recientes y el estado de tus últimas citas.</p>
        </div>
    </section>

    <section class="wrap dash-wide">

        <!-- SELECTOR DE VISTA -->
        <div class="historial-tabs">
            <a href="historial.php?vista=citas" class="historial-tab <?= $vista === 'citas' ? 'active' : '' ?>">Últimas citas</a>
            <a href="historial.php?vista=notas" class="historial-tab <?= $vista === 'notas' ? 'active' : '' ?>">Notas médicas</a>
            <a href="historial.php?vista=examenes" class="historial-tab <?= $vista === 'examenes' ? 'active' : '' ?>">Radiografías y exámenes</a>
        </div>

        <!-- LAYOUT: SIDEBAR DE FILTROS + CONTENIDO -->
        <form action="historial.php" method="GET" class="dash-layout" id="formFiltros">
            <input type="hidden" name="vista" value="<?= htmlspecialchars($vista) ?>">

            <aside class="dash-sidebar">
                <h3>Filtros</h3>

                <?php if ($vista === 'citas'): ?>
                    <div class="dash-filter-group">
                        <label for="estado">Estado</label>
                        <select name="estado" id="estado" class="form-control">
                            <option value="">Todos</option>
                            <option value="pendiente" <?= $estado_filtro === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                            <option value="en_espera" <?= $estado_filtro === 'en_espera' ? 'selected' : '' ?>>En espera</option>
                            <option value="en_atencion" <?= $estado_filtro === 'en_atencion' ? 'selected' : '' ?>>En atención</option>
                            <option value="completada" <?= $estado_filtro === 'completada' ? 'selected' : '' ?>>Completada</option>
                            <option value="cancelada" <?= $estado_filtro === 'cancelada' ? 'selected' : '' ?>>Cancelada</option>
                            <option value="vencida" <?= $estado_filtro === 'vencida' ? 'selected' : '' ?>>Vencida</option>
                        </select>
                    </div>
                <?php elseif ($vista === 'examenes'): ?>
                    <div class="dash-filter-group">
                        <label for="estado">Estado</label>
                        <select name="estado" id="estado" class="form-control">
                            <option value="">Todos</option>
                            <option value="solicitado" <?= $estado_filtro === 'solicitado' ? 'selected' : '' ?>>Solicitado</option>
                            <option value="realizado" <?= $estado_filtro === 'realizado' ? 'selected' : '' ?>>Realizado</option>
                            <option value="cancelado" <?= $estado_filtro === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                        </select>
                    </div>
                <?php endif; ?>

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
                        <a href="historial.php?vista=<?= htmlspecialchars($vista) ?>" class="btn-cancel">Limpiar filtros</a>
                    <?php endif; ?>
                </div>
            </aside>

            <main class="dash-main">
                <div class="dash-search">
                    <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="<?= htmlspecialchars($config_vista[$vista]['placeholder']) ?>" class="form-control">
                    <button type="submit" class="btn-primary">Buscar</button>
                </div>

                <p class="dash-results-count">
                    <?= $total_registros ?> <?= $config_vista[$vista]['unidad'] ?> encontrada(s)<?= $hay_filtros_activos ? ' con los filtros aplicados' : '' ?>.
                </p>

                <?php if (count($items) === 0): ?>
                    <div class="card" style="padding:32px; text-align:center; color:var(--muted);">
                        No se encontraron resultados con estos filtros.
                    </div>
                <?php elseif ($vista === 'citas'): ?>
                    <?php foreach ($items as $cita): ?>
                        <div class="historial-item">
                            <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px; align-items:flex-start;">
                                <div>
                                    <strong>Dr(a). <?= htmlspecialchars($cita['doctor_nombre'] . ' ' . $cita['doctor_apellido']) ?></strong>
                                    <p style="color:var(--muted); margin-top:6px;"><?= htmlspecialchars($cita['motivo'] ?: 'Consulta dental') ?></p>
                                </div>
                                <span style="color:var(--cyan);"><?= htmlspecialchars(format_date_time($cita['fecha_hora'])) ?></span>
                            </div>
                            <p style="margin-top:10px;">
                                <span class="badge badge-<?= $cita['estado'] ?>"><?= htmlspecialchars(str_replace('_', ' ', $cita['estado'])) ?></span>
                            </p>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ($vista === 'notas'): ?>
                    <?php foreach ($items as $nota): ?>
                        <div class="historial-item">
                            <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px; align-items:flex-start;">
                                <div>
                                    <strong>Dr(a). <?= htmlspecialchars($nota['doctor_nombre'] . ' ' . $nota['doctor_apellido']) ?></strong>
                                    <p style="color:var(--muted); margin-top:6px;">Revisión: <?= htmlspecialchars(format_date($nota['fecha_revision'])) ?></p>
                                </div>
                                <?php if ($nota['proxima_revision']): ?>
                                    <span style="color:var(--cyan);">Próxima: <?= htmlspecialchars(format_date($nota['proxima_revision'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top:12px; color:var(--muted);">
                                <p><strong>Diagnóstico:</strong> <?= htmlspecialchars($nota['diagnostico'] ?: 'Sin diagnóstico') ?></p>
                                <p style="margin-top:8px;"><strong>Tratamiento:</strong> <?= htmlspecialchars($nota['tratamiento'] ?: 'Sin tratamiento registrado') ?></p>
                                <?php if (!empty($nota['medicamentos'])): ?>
                                    <p style="margin-top:8px;"><strong>Medicamentos indicados:</strong> <?= htmlspecialchars($nota['medicamentos']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($nota['observaciones'])): ?>
                                    <p style="margin-top:8px;"><strong>Observaciones:</strong> <?= htmlspecialchars($nota['observaciones']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach ($items as $examen): ?>
                        <div class="historial-item">
                            <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px; align-items:flex-start;">
                                <div>
                                    <strong><?= htmlspecialchars($examen['tipo_examen']) ?></strong>
                                    <p style="color:var(--muted); margin-top:6px;">Solicitado por Dr(a). <?= htmlspecialchars($examen['doctor_nombre'] . ' ' . $examen['doctor_apellido']) ?> — <?= htmlspecialchars(format_date($examen['fecha_solicitud'])) ?></p>
                                </div>
                                <span class="badge badge-<?= $mapa_badge_examen[$examen['estado']] ?>">
                                    <?= htmlspecialchars(ucfirst($examen['estado'])) ?>
                                </span>
                            </div>
                            <?php if ($examen['estado'] === 'realizado'): ?>
                                <div style="margin-top:12px; color:var(--muted);">
                                    <p><strong>Resultado:</strong> <?= htmlspecialchars($examen['resultado'] ?: 'Sin detalle') ?></p>
                                    <?php if (!empty($examen['archivo'])): ?>
                                        <p style="margin-top:8px;"><a href="ver_archivo_examen.php?id=<?= (int) $examen['id_examen'] ?>" target="_blank" rel="noopener" style="color:var(--cyan);">Ver archivo adjunto</a></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($total_paginas > 1): ?>
                    <div class="pagination-container">
                        <span class="pagination-info">Página <strong><?= $pagina ?></strong> de <strong><?= $total_paginas ?></strong> (<?= $total_registros ?> resultados)</span>
                        <div class="pagination">
                            <?php if ($pagina > 1): ?>
                                <a href="historial.php?pagina=<?= $pagina - 1 ?><?= $query_filtros ?>" class="page-link">&laquo; Anterior</a>
                            <?php endif; ?>
                            <?php foreach (obtener_paginas_visibles($pagina, $total_paginas) as $item_pagina): ?>
                                <?php if ($item_pagina === '...'): ?>
                                    <span class="page-ellipsis">&hellip;</span>
                                <?php else: ?>
                                    <a href="historial.php?pagina=<?= $item_pagina ?><?= $query_filtros ?>" class="page-link <?= $item_pagina === $pagina ? 'active' : '' ?>"><?= $item_pagina ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($pagina < $total_paginas): ?>
                                <a href="historial.php?pagina=<?= $pagina + 1 ?><?= $query_filtros ?>" class="page-link">Siguiente &raquo;</a>
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
