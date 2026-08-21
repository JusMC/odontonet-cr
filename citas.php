<?php

require_once __DIR__ . '/assets/includes/helpers/agendar_cita.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/paginacion_helper.php';

$fecha_min = (new DateTime())->format('Y-m-d'); // No se puede agendar antes de hoy.
$fecha_max = (new DateTime())->modify('+100 years')->format('Y-m-d'); // Límite de seguridad, no un caso real.
$hora_min_actual = (new DateTime())->format('H:i');

// ¿El envío del formulario de agendar cita falló? Si es así, el modal se
// vuelve a abrir automáticamente para que la persona vea el error/sugerencias
// sin perder lo que ya había llenado.
$abrir_modal_cita = $_SERVER['REQUEST_METHOD'] === 'POST' && $message_type === 'error';

// FILTROS Y PAGINACIÓN DE "MIS CITAS"
$busqueda      = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$estado_filtro = isset($_GET['estado']) && in_array($_GET['estado'], ['pendiente', 'en_espera', 'en_atencion', 'completada', 'cancelada', 'vencida'], true) ? $_GET['estado'] : '';
$fecha_desde   = isset($_GET['desde']) ? trim($_GET['desde']) : '';
$fecha_hasta   = isset($_GET['hasta']) ? trim($_GET['hasta']) : '';

// Armamos la consulta SQL en pedazos ("condiciones"). Siempre filtramos por el paciente en sesión primero.
$condiciones = ["c.id_paciente = :id_paciente"];
$params = [':id_paciente' => $paciente_id];

if ($busqueda !== '') {
    $condiciones[] = "(u.nombre LIKE :b1 OR u.apellido LIKE :b2 OR c.motivo LIKE :b3)";
    $param_valor = '%' . $busqueda . '%';
    $params[':b1'] = $param_valor;
    $params[':b2'] = $param_valor;
    $params[':b3'] = $param_valor;
}
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

// Unimos todas las condiciones con "AND" para formar el WHERE final.
$where = ' WHERE ' . implode(' AND ', $condiciones);

$hay_filtros_activos = $busqueda !== '' || $estado_filtro !== '' || $fecha_desde !== '' || $fecha_hasta !== '';

// Reconstruimos los filtros como texto de URL, para que los links de paginación no los pierdan.
$query_filtros = '';
if ($busqueda !== '') $query_filtros .= '&buscar=' . urlencode($busqueda);
if ($estado_filtro !== '') $query_filtros .= '&estado=' . urlencode($estado_filtro);
if ($fecha_desde !== '') $query_filtros .= '&desde=' . urlencode($fecha_desde);
if ($fecha_hasta !== '') $query_filtros .= '&hasta=' . urlencode($fecha_hasta);

$limite = 5; // Cuántas citas se muestran por página.
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

// Contamos cuántas citas hay en total con estos filtros (para saber cuántas páginas hacen falta).
$sql_total = "SELECT COUNT(*)
              FROM citas c
              INNER JOIN doctores d ON d.id_doctor = c.id_doctor
              INNER JOIN usuarios u ON u.id_usuario = d.id_usuario"
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

// Traemos las citas de esta página, con los datos del doctor asignado.
$sql_mis_citas = "SELECT c.id_cita, c.fecha_hora, c.motivo, c.estado,
                          u.nombre AS doctor_nombre, u.apellido AS doctor_apellido,
                          d.especialidad
                   FROM citas c
                   INNER JOIN doctores d ON d.id_doctor = c.id_doctor
                   INNER JOIN usuarios u ON u.id_usuario = d.id_usuario"
                   . $where .
                   " ORDER BY c.fecha_hora DESC
                   LIMIT $inicio_int, $limite_int";
$stmt_mis_citas = $pdo->prepare($sql_mis_citas);
$stmt_mis_citas->execute($params);
$mis_citas = $stmt_mis_citas->fetchAll();

// Notificación temporal (ver toast al final de la página): mensajes de
// resultado de cancelación (por GET) o del formulario de agendar cita.
if (isset($_GET['msg']) && $_GET['msg'] === 'cancelada') {
    // Viene de cancelar_cita.php, redirigido con éxito.
    $mensaje_toast = 'Cita cancelada correctamente.';
    $tipo_toast = 'exito';
} elseif (isset($_GET['error'])) {
    // También puede venir de cancelar_cita.php, pero con un error específico.
    $errores_cita = [
        'invalida'      => 'El identificador de la cita no es válido.',
        'no_encontrada' => 'No se encontró la cita o no te pertenece.',
        'no_cancelable' => 'Esta cita no puede cancelarse porque ya fue atendida o está en atención.',
        'db'            => 'Ocurrió un error al procesar la solicitud. Intenta de nuevo.',
    ];
    $mensaje_toast = $errores_cita[$_GET['error']] ?? 'Error desconocido.';
    $tipo_toast = 'error';
} else {
    // Si no, usamos el mensaje que dejó agendar_cita.php (éxito o error al agendar).
    $mensaje_toast = $message;
    $tipo_toast = $message_type === 'success' ? 'exito' : ($message !== '' ? 'error' : '');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OdontoNet — Agendar cita</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <link rel="stylesheet" href="assets/css/pages/citas.css">
    <link rel="stylesheet" href="assets/css/shared/toast.css">
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
            <div>
                <span class="eyebrow">Dashboard</span>
                <h1>Mis <span class="grad">Citas</span></h1>
            </div>
        </div>
    </section>

    <section class="wrap dash-wide">

        <!-- LAYOUT: SIDEBAR DE FILTROS + CONTENIDO -->
        <form action="citas.php" method="GET" class="dash-layout" id="formFiltros">

            <aside class="dash-sidebar">
                <h3>Filtros</h3>

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
                        <a href="citas.php" class="btn-cancel">Limpiar filtros</a>
                    <?php endif; ?>
                </div>
            </aside>

            <main class="dash-main">
                <div class="dash-search">
                    <button type="button" class="dash-fab-text" onclick="abrirModalCita()">
                        <span class="dash-fab-text-plus" aria-hidden="true">
                            <span class="dash-fab-text-plus-icon"></span>
                        </span>
                        Agendar Cita
                    </button>
                    <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por doctor o motivo..." class="form-control">
                    <button type="submit" class="btn-primary">Buscar</button>
                </div>

                <p class="dash-results-count">
                    <?= $total_registros ?> cita(s) encontrada(s)<?= $hay_filtros_activos ? ' con los filtros aplicados' : '' ?>.
                </p>

                <?php if (count($mis_citas) === 0): ?>
                    <div class="card" style="padding:32px; text-align:center; color:var(--muted);">
                        No tienes citas registradas con estos filtros.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Fecha y hora</th>
                                    <th>Doctor</th>
                                    <th>Servicio / Motivo</th>
                                    <th>Estado</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mis_citas as $c): ?>
                                    <?php
                                        // Aunque el estado real siga "pendiente"/"en_espera" en la base de datos hasta el
                                        // próximo cron, si la fecha ya pasó la mostramos como "vencida" y no dejamos cancelarla.
                                        $fecha_cita = new DateTime($c['fecha_hora']);
                                        $ahora      = new DateTime();
                                        $mostrar_vencida = in_array($c['estado'], ['pendiente', 'en_espera']) && $fecha_cita <= $ahora;
                                        $puede_cancelar  = in_array($c['estado'], ['pendiente', 'en_espera']) && $fecha_cita > $ahora;
                                    ?>
                                    <tr>
                                        <td><?= date('d/m/Y h:i A', strtotime($c['fecha_hora'])) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($c['doctor_nombre'] . ' ' . $c['doctor_apellido']) ?></strong><br>
                                            <small style="color:var(--muted);"><?= htmlspecialchars($c['especialidad']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($c['motivo'] ?: 'Sin motivo registrado') ?></td>
                                        <td>
                                            <?php if ($mostrar_vencida): ?>
                                                <span class="badge" style="background:rgba(180,180,180,0.15); color:var(--muted);">vencida</span>
                                            <?php else: ?>
                                                <span class="badge badge-<?= $c['estado'] ?>"><?= str_replace('_', ' ', $c['estado']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($puede_cancelar): ?>
                                                <a href="#" class="btn-action btn-delete" onclick="confirmarCancelacion('cancelar_cita.php?id=<?= $c['id_cita'] ?>'); return false;">Cancelar</a>
                                            <?php else: ?>
                                                <span style="color:var(--muted); font-size:0.85rem;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_paginas > 1): ?>
                        <div class="pagination-container">
                            <span class="pagination-info">Página <strong><?= $pagina ?></strong> de <strong><?= $total_paginas ?></strong> (<?= $total_registros ?> citas)</span>
                            <div class="pagination">
                                <?php if ($pagina > 1): ?>
                                    <a href="citas.php?pagina=<?= $pagina - 1 ?><?= $query_filtros ?>" class="page-link">&laquo; Anterior</a>
                                <?php endif; ?>
                                <?php foreach (obtener_paginas_visibles($pagina, $total_paginas) as $item): ?>
                                    <?php if ($item === '...'): ?>
                                        <span class="page-ellipsis">&hellip;</span>
                                    <?php else: ?>
                                        <a href="citas.php?pagina=<?= $item ?><?= $query_filtros ?>" class="page-link <?= $item === $pagina ? 'active' : '' ?>"><?= $item ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if ($pagina < $total_paginas): ?>
                                    <a href="citas.php?pagina=<?= $pagina + 1 ?><?= $query_filtros ?>" class="page-link">Siguiente &raquo;</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </main>
        </form>
    </section>

    <!-- MODAL: AGENDAR CITA -->
    <div id="modalCita" class="dash-modal-overlay" data-abrir-automatico="<?= $abrir_modal_cita ? '1' : '0' ?>">
        <div class="dash-modal-card">
            <div class="dash-modal-head">
                <button type="button" class="dash-modal-close" onclick="cerrarModalCita()" aria-label="Cerrar">&times;</button>
                <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                    <span class="eyebrow">Nueva cita</span>
                    <h2 style="margin:4px 0 0;">Agendar Cita</h2>
                </div>
            </div>

            <?php if ($message_type === 'error' && !empty($sugerencias_horarios)): ?>
                <div style="margin-bottom: 20px; padding: 16px; background: rgba(255, 193, 7, 0.1); border-radius: 12px; border-left: 4px solid var(--warning, #ffc107);">
                    <strong style="display: block; margin-bottom: 12px; color: var(--text);">El doctor no está disponible en ese horario. Horarios sugeridos:</strong>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                        <?php foreach ($sugerencias_horarios as $sugerencia):
                            $fecha_sugerencia = new DateTime($sugerencia);
                            $fecha_formato = $fecha_sugerencia->format('d/m/Y');
                            $hora_valor = $fecha_sugerencia->format('H:i');
                            $hora_formato = $fecha_sugerencia->format('h:i A');
                        ?>
                            <button type="button" class="btn-suggestion" onclick="seleccionarSugerencia('<?= htmlspecialchars($fecha_sugerencia->format('Y-m-d')) ?>', '<?= htmlspecialchars($hora_valor) ?>')" style="padding: 8px 12px; background: var(--bg-panel-2); border: 1px solid var(--line); border-radius: 8px; color: var(--text); cursor: pointer; font-size: 0.9rem;">
                                <?= htmlspecialchars($fecha_formato) ?> a las <?= htmlspecialchars($hora_formato) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="citas.php">
                <div class="form-row">
                    <div class="field">
                        <label for="servicio"><span class="dash-req">*</span> Servicio</label>
                        <select id="servicio" name="servicio" required class="form-control">
                            <option value="">Selecciona un servicio</option>
                            <?php foreach ($servicios as $servicio): ?>
                                <option value="<?= (int)$servicio['id_servicio'] ?>" <?= ((string)$selected_servicio === (string)$servicio['id_servicio']) ? 'selected' : '' ?>><?= htmlspecialchars($servicio['nombre']) ?> — ₡<?= number_format((float) $servicio['precio'], 2) ?> (<?= (int) $servicio['duracion_minutos'] ?> min)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="doctor"><span class="dash-req">*</span> Doctor</label>
                        <select id="doctor" name="doctor" required class="form-control">
                            <option value="">Selecciona un doctor</option>
                            <?php foreach ($doctores as $doctor): ?>
                                <option value="<?= (int)$doctor['id_doctor'] ?>" <?= ((string)$selected_doctor === (string)$doctor['id_doctor']) ? 'selected' : '' ?>><?= htmlspecialchars($doctor['nombre'] . ' ' . $doctor['apellido']) ?> · <?= htmlspecialchars($doctor['especialidad']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <p class="muted-text" style="margin: -6px 0 16px; font-size: 0.85rem;">
                    El precio se cobra el día de la cita en clínica. Métodos de pago aceptados: efectivo, tarjeta y transferencia.
                </p>

                <div class="form-row">
                    <div class="field">
                        <label for="fecha"><span class="dash-req">*</span> Fecha</label>
                        <input type="date" id="fecha" name="fecha" value="<?= htmlspecialchars($selected_fecha) ?>" required class="form-control">
                    </div>

                    <div class="field">
                        <label for="hora"><span class="dash-req">*</span> Hora</label>
                        <input type="time" id="hora" name="hora" value="<?= htmlspecialchars($selected_hora) ?>" min="<?= htmlspecialchars($hora_min_actual) ?>" required class="form-control">
                    </div>
                </div>

                <div class="field">
                    <label for="motivo">Motivo o comentario <span class="dash-hint">(opcional)</span></label>
                    <textarea id="motivo" name="motivo" placeholder="Cuéntanos brevemente qué te trae hoy." maxlength="255" class="form-control"><?= htmlspecialchars($selected_motivo) ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModalCita()">Cancelar</button>
                    <button type="submit" class="btn-primary">Agendar cita</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: CONFIRMAR CANCELACIÓN -->
    <div id="modalConfirmacion" class="custom-modal-overlay">
        <div class="custom-modal-card">
            <h3>¿Cancelar cita?</h3>
            <p>¿Estás seguro de que deseas cancelar esta cita? Esta acción no se puede deshacer.</p>
            <div class="custom-modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="cerrarModal()">Volver</button>
                <a id="btnConfirmarModal" href="#" class="btn-modal-confirm">Cancelar cita</a>
            </div>
        </div>
    </div>

    <?php require_once 'assets/includes/partials/footer.php'; ?>

    <!-- NOTIFICACIÓN TEMPORAL (ver assets/js/shared/toast.js) -->
    <div id="toastNotificacion" class="toast-notification" data-mensaje="<?= htmlspecialchars($mensaje_toast) ?>" data-tipo="<?= htmlspecialchars($tipo_toast) ?>" role="alert" aria-live="assertive">
        <span class="toast-icon" aria-hidden="true"></span>
        <div class="toast-body">
            <strong id="toastTitulo"></strong>
            <p id="toastMensaje"></p>
        </div>
        <button type="button" class="toast-close" onclick="cerrarToast()" aria-label="Cerrar notificación">&times;</button>
    </div>

    <script src="assets/js/shared/toast.js"></script>
    <script src="assets/js/pages/citas.js"></script>

</body>
</html>
