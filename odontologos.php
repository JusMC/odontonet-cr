<?php
/*
    odontologos.php — Ficha de odontólogos (dashboard, solo lectura).

    Muestra, por cada usuario con rol de doctor (id_rol = 2): datos
    personales, especialidad, número de colegiado, horario de atención,
    estado activo/inactivo, los tratamientos que puede realizar (tabla
    doctor_servicios, asignada desde tratamientos.php) y sus próximas
    citas asignadas.

    Filtros en una barra lateral (estado, especialidad), buscador y
    paginación, para manejar bien un volumen grande de doctores.

    Para crear/editar doctores o cambiar su estado, usar Gestión de Usuarios.
*/

session_start();
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/paginacion_helper.php';

// Guard: administrador (id_rol = 4) o recepcionista (id_rol = 3).
$id_rol_sesion = (int) ($_SESSION['id_rol'] ?? 0);
if (!in_array($id_rol_sesion, [3, 4], true)) {
    header('Location: login.php');
    exit;
}

// ESPECIALIDADES REGISTRADAS (para el filtro lateral)
// Lista de especialidades distintas que existen, para llenar el <select> de filtro.
$especialidades_lista = $pdo->query(
    "SELECT DISTINCT d.especialidad
     FROM doctores d
     INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
     WHERE u.id_rol = 2 AND d.especialidad IS NOT NULL AND d.especialidad <> ''
     ORDER BY d.especialidad ASC"
)->fetchAll();

// LÓGICA DE BÚSQUEDA, FILTROS (barra lateral) Y PAGINACIÓN
$busqueda            = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$estado_filtro       = isset($_GET['estado']) && in_array($_GET['estado'], ['1', '0'], true) ? $_GET['estado'] : '';
$especialidad_filtro = isset($_GET['especialidad']) ? trim($_GET['especialidad']) : '';
$mostrar_antiguos    = isset($_GET['antiguos']) && $_GET['antiguos'] === '1';

// Por defecto, solo usuarios con rol de doctor ACTUAL (id_rol = 2). Si se
// activa "Mostrar antiguos odontólogos", se incluyen también quienes
// tienen una fila en `doctores` pero su rol actual ya cambió (ej. un
// doctor que pasó a administrador) — esa fila nunca se borra al cambiar
// de rol, precisamente para no perder el historial clínico ligado a ella.
// Armamos la consulta SQL en pedazos ("condiciones"), agregando solo los filtros que el usuario realmente usó.
$condiciones = [];
$params = [];

if (!$mostrar_antiguos) {
    $condiciones[] = "u.id_rol = 2";
}

if ($busqueda !== '') {
    // Buscamos el texto escrito en varias columnas a la vez (nombre, apellido, cédula, colegiado, especialidad).
    $condiciones[] = "(u.nombre LIKE :b1 OR u.apellido LIKE :b2 OR u.cedula LIKE :b3 OR d.num_colegiado LIKE :b4 OR d.especialidad LIKE :b5)";
    $param_valor = '%' . $busqueda . '%';
    $params[':b1'] = $param_valor;
    $params[':b2'] = $param_valor;
    $params[':b3'] = $param_valor;
    $params[':b4'] = $param_valor;
    $params[':b5'] = $param_valor;
}
if ($estado_filtro !== '') {
    $condiciones[] = "u.estado = :estado";
    $params[':estado'] = (int) $estado_filtro;
}
if ($especialidad_filtro !== '') {
    $condiciones[] = "d.especialidad = :especialidad";
    $params[':especialidad'] = $especialidad_filtro;
}

// Unimos todas las condiciones activas con "AND" para formar el WHERE final (o ninguno, si no hay filtros).
$where = count($condiciones) > 0 ? ' WHERE ' . implode(' AND ', $condiciones) : '';

$hay_filtros_activos = $busqueda !== '' || $estado_filtro !== '' || $especialidad_filtro !== '' || $mostrar_antiguos;

// Reconstruimos los filtros como texto de URL, para que los links de paginación no los pierdan.
$query_filtros = '';
if ($busqueda !== '') $query_filtros .= '&buscar=' . urlencode($busqueda);
if ($estado_filtro !== '') $query_filtros .= '&estado=' . urlencode($estado_filtro);
if ($especialidad_filtro !== '') $query_filtros .= '&especialidad=' . urlencode($especialidad_filtro);
if ($mostrar_antiguos) $query_filtros .= '&antiguos=1';

$limite = 5; // Cuántos doctores se muestran por página.
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

// Contamos cuántos doctores hay en total con estos filtros (para saber cuántas páginas hacen falta).
$sql_total = "SELECT COUNT(*) FROM doctores d INNER JOIN usuarios u ON d.id_usuario = u.id_usuario" . $where;
$stmt_total = $pdo->prepare($sql_total);
$stmt_total->execute($params);
$total_registros = (int) $stmt_total->fetchColumn();

$total_paginas = max(1, (int) ceil($total_registros / $limite));
if ($pagina > $total_paginas) {
    // Si pidieron una página que ya no existe (ej: se aplicó un filtro que redujo los resultados), la ajustamos a la última.
    $pagina = $total_paginas;
}
$inicio_int = (int) (($pagina - 1) * $limite); // Desde qué fila empezar (para el LIMIT de SQL).
$limite_int = (int) $limite;

$sql_doctores = "SELECT d.id_doctor, d.especialidad, d.num_colegiado, d.horario_atencion,
                         u.nombre, u.apellido, u.cedula, u.correo, u.telefono, u.estado,
                         u.id_rol, r.nombre AS rol_nombre
                  FROM doctores d
                  INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
                  LEFT JOIN roles r ON u.id_rol = r.id_rol"
                  . $where .
                  " ORDER BY (u.id_rol = 2) DESC, u.estado DESC, u.nombre ASC, u.apellido ASC
                  LIMIT $inicio_int, $limite_int";
// Traemos los doctores de esta página (ya con el filtro y el límite aplicados).
$stmt_doctores = $pdo->prepare($sql_doctores);
$stmt_doctores->execute($params);
$doctores = $stmt_doctores->fetchAll();

// Consulta para los tratamientos que puede realizar cada doctor (se ejecuta una vez por doctor, abajo).
$stmt_tratamientos = $pdo->prepare(
    "SELECT s.nombre FROM doctor_servicios ds
     INNER JOIN servicios s ON ds.id_servicio = s.id_servicio
     WHERE ds.id_doctor = :id_doctor AND s.activo = 1
     ORDER BY s.nombre ASC"
);

// Consulta para las próximas 5 citas pendientes de cada doctor.
$stmt_citas = $pdo->prepare(
    "SELECT c.fecha_hora, c.motivo, c.estado, up.nombre AS paciente_nombre, up.apellido AS paciente_apellido
     FROM citas c
     INNER JOIN pacientes p ON c.id_paciente = p.id_paciente
     INNER JOIN usuarios up ON p.id_usuario = up.id_usuario
     WHERE c.id_doctor = :id_doctor AND c.estado IN ('pendiente', 'en_espera', 'en_atencion')
     ORDER BY c.fecha_hora ASC
     LIMIT 5"
);

// Consulta para el total de citas pendientes de cada doctor (puede ser más de las 5 que se muestran).
$stmt_total_citas = $pdo->prepare(
    "SELECT COUNT(*) FROM citas WHERE id_doctor = :id_doctor AND estado IN ('pendiente', 'en_espera', 'en_atencion')"
);

// Por cada doctor de la lista, le agregamos sus tratamientos y sus citas (una consulta extra por doctor).
foreach ($doctores as &$doc) {
    $stmt_tratamientos->execute([':id_doctor' => $doc['id_doctor']]);
    $doc['tratamientos'] = array_column($stmt_tratamientos->fetchAll(), 'nombre');

    $stmt_citas->execute([':id_doctor' => $doc['id_doctor']]);
    $doc['proximas_citas'] = $stmt_citas->fetchAll();

    $stmt_total_citas->execute([':id_doctor' => $doc['id_doctor']]);
    $doc['total_citas_asignadas'] = (int) $stmt_total_citas->fetchColumn();
}
unset($doc); // Buena práctica: se rompe la referencia "&$doc" para que no queden datos pegados por accidente.
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Odontólogos - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/home.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <!-- Estilos del formulario/tarjetas (compartidos con otras páginas) -->
    <link rel="stylesheet" href="assets/css/shared/gestionCitas.css">
    <!-- Estilos exclusivos del dashboard de odontólogos -->
    <link rel="stylesheet" href="assets/css/pages/odontologos.css">
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
                <h1>Directorio de <span class="grad">Odontólogos</span></h1>
            </div>
        </div>
    </section>

    <section class="wrap dash-wide">

        <!-- LAYOUT: SIDEBAR DE FILTROS + CONTENIDO -->
        <form action="odontologos.php" method="GET" class="dash-layout" id="formFiltros">

            <aside class="dash-sidebar">
                <h3>Filtros</h3>

                <div class="dash-filter-group">
                    <label for="estado">Estado</label>
                    <select name="estado" id="estado" class="form-control">
                        <option value="">Todos</option>
                        <option value="1" <?= $estado_filtro === '1' ? 'selected' : '' ?>>Activo</option>
                        <option value="0" <?= $estado_filtro === '0' ? 'selected' : '' ?>>Inactivo</option>
                    </select>
                </div>

                <div class="dash-filter-group">
                    <label for="especialidad">Especialidad</label>
                    <select name="especialidad" id="especialidad" class="form-control">
                        <option value="">Todas</option>
                        <?php foreach ($especialidades_lista as $esp): ?>
                            <option value="<?= htmlspecialchars($esp['especialidad']) ?>" <?= $especialidad_filtro === $esp['especialidad'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($esp['especialidad']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dash-filter-group">
                    <label class="dash-checkbox-label">
                        <input type="checkbox" name="antiguos" value="1" <?= $mostrar_antiguos ? 'checked' : '' ?>>
                        Mostrar antiguos odontólogos
                    </label>
                </div>

                <div class="dash-sidebar-actions">
                    <button type="submit" class="btn-primary">Aplicar filtros</button>
                    <?php if ($hay_filtros_activos): ?>
                        <a href="odontologos.php" class="btn-cancel">Limpiar filtros</a>
                    <?php endif; ?>
                </div>
            </aside>

            <main class="dash-main">
                <div class="dash-search">
                    <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por nombre, cédula, colegiado o especialidad..." class="form-control">
                    <button type="submit" class="btn-primary">Buscar</button>
                </div>

                <?php if ($id_rol_sesion === 4): ?>
                    <p class="dash-hint-nota">Para crear o editar un doctor, hazlo desde <a href="usuarios.php" style="color: var(--cyan);">Gestión de Usuarios</a>.</p>
                <?php endif; ?>

                <p class="dash-results-count">
                    <?= $total_registros ?> odontólogo(s) encontrado(s)<?= $hay_filtros_activos ? ' con los filtros aplicados' : '' ?>.
                </p>

                <?php if (count($doctores) === 0): ?>
                    <div class="card" style="padding: 32px; text-align: center; color: var(--muted);">
                        No hay odontólogos registrados con estos filtros.
                    </div>
                <?php else: ?>
                    <div style="display: grid; gap: 20px;">
                        <?php foreach ($doctores as $doc): ?>
                            <?php $es_antiguo = (int) $doc['id_rol'] !== 2; ?>
                            <div class="card citas-card" style="padding: 26px;<?= $es_antiguo ? ' opacity: 0.85;' : '' ?>">
                                <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 16px; align-items: flex-start; margin-bottom: 18px;">
                                    <div>
                                        <h2 style="margin-bottom: 6px;"><?= $es_antiguo ? '' : 'Dr(a). ' ?><?= htmlspecialchars($doc['nombre'] . ' ' . $doc['apellido']) ?></h2>
                                        <?php if ($es_antiguo): ?>
                                            <span class="badge badge-antiguo">Antiguo odontólogo</span>
                                            <span class="badge badge-<?= htmlspecialchars(strtolower($doc['rol_nombre'] ?? '')) ?>"><?= htmlspecialchars(ucfirst($doc['rol_nombre'] ?? 'Sin rol')) ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-doctor"><?= htmlspecialchars($doc['especialidad']) ?></span>
                                        <?php endif; ?>
                                        <small style="color: var(--muted); margin-left: 8px;">Cédula: <?= htmlspecialchars($doc['cedula'] ?? 'N/A') ?> · Colegiado: <?= htmlspecialchars($doc['num_colegiado']) ?></small>
                                    </div>
                                    <span class="badge badge-<?= (int) $doc['estado'] === 1 ? 'success' : 'cancelada' ?>">
                                        <?= (int) $doc['estado'] === 1 ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </div>

                                <?php if ($es_antiguo): ?>
                                    <p class="dash-hint-nota" style="margin-top: -10px;">Especialidad histórica: <?= htmlspecialchars($doc['especialidad']) ?>. Los datos debajo corresponden a su antiguo perfil de doctor.</p>
                                <?php endif; ?>

                                <div class="grid-dos-columnas" style="margin-bottom: 18px;">
                                    <div>
                                        <span class="eyebrow">Contacto</span>
                                        <p style="margin-top: 6px;"><?= htmlspecialchars($doc['correo']) ?><?= !empty($doc['telefono']) ? ' · ' . htmlspecialchars($doc['telefono']) : '' ?></p>
                                    </div>
                                    <div>
                                        <span class="eyebrow">Horario de atención</span>
                                        <p style="margin-top: 6px;"><?= htmlspecialchars($doc['horario_atencion'] ?: 'No registrado') ?></p>
                                    </div>
                                </div>

                                <div style="margin-bottom: 18px;">
                                    <span class="eyebrow">Tratamientos que puede realizar</span>
                                    <p style="margin-top: 6px;">
                                        <?php if (count($doc['tratamientos']) === 0): ?>
                                            <span class="muted-text">Sin tratamientos asignados. Se asignan desde <a href="tratamientos.php" style="color: var(--cyan);">Tratamientos</a>.</span>
                                        <?php else: ?>
                                            <?= htmlspecialchars(implode(', ', $doc['tratamientos'])) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>

                                <div>
                                    <span class="eyebrow">Citas asignadas (<?= $doc['total_citas_asignadas'] ?> pendientes en total)</span>
                                    <?php if (count($doc['proximas_citas']) === 0): ?>
                                        <p style="margin-top: 6px;" class="muted-text">Sin próximas citas.</p>
                                    <?php else: ?>
                                        <div class="table-responsive" style="margin-top: 10px;">
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <th>Fecha y hora</th>
                                                        <th>Paciente</th>
                                                        <th>Motivo</th>
                                                        <th>Estado</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($doc['proximas_citas'] as $c): ?>
                                                        <tr>
                                                            <td><?= date('d/m/Y - h:i A', strtotime($c['fecha_hora'])) ?></td>
                                                            <td><?= htmlspecialchars($c['paciente_nombre'] . ' ' . $c['paciente_apellido']) ?></td>
                                                            <td><?= htmlspecialchars($c['motivo'] ?: '—') ?></td>
                                                            <td><span class="badge badge-<?= $c['estado'] ?>"><?= str_replace('_', ' ', $c['estado']) ?></span></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($total_paginas > 1): ?>
                        <div class="pagination-container">
                            <span class="pagination-info">Página <strong><?= $pagina ?></strong> de <strong><?= $total_paginas ?></strong> (<?= $total_registros ?> odontólogos)</span>
                            <div class="pagination">
                                <?php if ($pagina > 1): ?>
                                    <a href="odontologos.php?pagina=<?= $pagina - 1 ?><?= $query_filtros ?>" class="page-link">&laquo; Anterior</a>
                                <?php endif; ?>
                                <?php foreach (obtener_paginas_visibles($pagina, $total_paginas) as $item): ?>
                                    <?php if ($item === '...'): ?>
                                        <span class="page-ellipsis">&hellip;</span>
                                    <?php else: ?>
                                        <a href="odontologos.php?pagina=<?= $item ?><?= $query_filtros ?>" class="page-link <?= $item === $pagina ? 'active' : '' ?>"><?= $item ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if ($pagina < $total_paginas): ?>
                                    <a href="odontologos.php?pagina=<?= $pagina + 1 ?><?= $query_filtros ?>" class="page-link">Siguiente &raquo;</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </main>
        </form>
    </section>

    <?php require_once 'assets/includes/partials/footer.php'; ?>

</body>
</html>
