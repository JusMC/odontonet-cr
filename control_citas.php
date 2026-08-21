<?php

session_start();
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/bitacora_helper.php';
require_once './assets/includes/helpers/horarios_helper.php';
require_once './assets/includes/helpers/paginacion_helper.php';

if (!in_array((int)$_SESSION['id_rol'], [2, 3, 4])) {
    header('Location: inicio.php');
    exit;
}

$id_rol_sesion = (int) $_SESSION['id_rol'];

$mensaje = "";
$tipo_mensaje = "";

if (isset($_GET['msg'])) {
    $mensajes_get = [
        'expediente_guardado' => ['Expediente guardado y cita marcada como completada.', 'exito'],
        'cita_no_disponible'  => ['Esa cita está cancelada o vencida; no se puede documentar.', 'error'],
        'llegada_ok'          => ['Llegada registrada. El paciente quedó en espera.', 'exito'],
        'retraso'             => ['Llegada registrada. Aviso: la cita anterior de ese doctor se está extendiendo más de lo previsto; el tiempo de espera podría superar 1 hora.', 'error'],
        'atencion_iniciada'   => ['Se inició la atención del paciente.', 'exito'],
        'accion_invalida'     => ['No se pudo procesar la acción solicitada.', 'error'],
    ];
    if (isset($mensajes_get[$_GET['msg']])) {
        [$mensaje, $tipo_mensaje] = $mensajes_get[$_GET['msg']];
    }
}

$mensaje_control = '';
if (isset($_GET['control'])) {
    $mensajes_control = [
        'agendado'      => 'La cita de control quedó agendada en la fecha y hora indicadas.',
        'ajustado'      => 'El doctor ya tenía otra cita a esa hora; la cita de control se reubicó automáticamente al espacio disponible más cercano.',
        'no_disponible' => 'No se encontró un espacio disponible cercano para la cita de control; agéndela manualmente desde este módulo.',
    ];
    $mensaje_control = $mensajes_control[$_GET['control']] ?? '';
}

// Notificación temporal combinada (ver toast al final de la página): junta
// $mensaje y $mensaje_control en un solo aviso, ya que pueden coincidir.
$mensaje_toast = trim($mensaje . ($mensaje_control !== '' ? ' ' . $mensaje_control : ''));
$tipo_toast = $tipo_mensaje !== '' ? $tipo_mensaje : ($mensaje_control !== '' ? 'exito' : '');

// ACCIONES SOBRE UNA CITA EXISTENTE: registrar llegada / iniciar atención
// Estos links vienen con ?accion=llegada o ?accion=iniciar_atencion en la URL.
if (isset($_GET['accion'], $_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $accion_cita   = $_GET['accion'];
    $id_cita_accion = intval($_GET['id']);

    if ($accion_cita === 'llegada' && in_array($id_rol_sesion, [3, 4], true)) {
        // Solo se puede registrar la llegada de una cita que sigue "pendiente".
        $stmt_cita_ll = $pdo->prepare("SELECT id_doctor FROM citas WHERE id_cita = :id AND estado = 'pendiente' LIMIT 1");
        $stmt_cita_ll->execute([':id' => $id_cita_accion]);
        $cita_ll = $stmt_cita_ll->fetch();

        if ($cita_ll) {
            $pdo->prepare("UPDATE citas SET hora_llegada = NOW(), estado = 'en_espera' WHERE id_cita = :id")
                ->execute([':id' => $id_cita_accion]);
            registrar_bitacora($pdo, 'UPDATE', "Se registró la llegada del paciente para la cita #$id_cita_accion.");

            // ¿El doctor tiene otra cita en curso que ya se está extendiendo más de lo previsto?
            // Si es así, avisamos que el tiempo de espera de este paciente podría superar 1 hora.
            $stmt_retraso = $pdo->prepare("
                SELECT id_cita FROM citas
                WHERE id_doctor = :id_doctor AND estado = 'en_atencion'
                  AND DATE_ADD(fecha_hora, INTERVAL duracion_minutos MINUTE) < NOW()
                LIMIT 1
            ");
            $stmt_retraso->execute([':id_doctor' => $cita_ll['id_doctor']]);

            header('Location: control_citas.php?msg=' . ($stmt_retraso->fetch() ? 'retraso' : 'llegada_ok'));
        } else {
            header('Location: control_citas.php?msg=accion_invalida');
        }
        exit;
    }

    if ($accion_cita === 'iniciar_atencion') {
        // Solo se puede iniciar la atención de una cita que está "en_espera".
        $stmt_cita_ia = $pdo->prepare("
            SELECT c.id_cita, d.id_usuario AS doctor_id_usuario
            FROM citas c
            INNER JOIN doctores d ON c.id_doctor = d.id_doctor
            WHERE c.id_cita = :id AND c.estado = 'en_espera'
            LIMIT 1
        ");
        $stmt_cita_ia->execute([':id' => $id_cita_accion]);
        $cita_ia = $stmt_cita_ia->fetch();

        // Solo el administrador o el doctor asignado a esa cita pueden iniciarla.
        if ($cita_ia && ($id_rol_sesion === 4 || (int) $cita_ia['doctor_id_usuario'] === (int) $_SESSION['usuario_id'])) {
            $pdo->prepare("UPDATE citas SET hora_inicio_atencion = NOW(), estado = 'en_atencion' WHERE id_cita = :id")
                ->execute([':id' => $id_cita_accion]);
            registrar_bitacora($pdo, 'UPDATE', "Se inició la atención de la cita #$id_cita_accion.");
            header('Location: control_citas.php?msg=atencion_iniciada');
        } else {
            header('Location: control_citas.php?msg=accion_invalida');
        }
        exit;
    }
}

// PROCESAR FORMULARIO DE AGENDAR CITA
$sugerencias_horarios = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agendar_cita'])) {
    $id_paciente     = intval($_POST['id_paciente']);
    $id_doctor       = intval($_POST['id_doctor']);
    $fecha           = $_POST['fecha'];
    $hora            = $_POST['hora'];
    $duracion        = intval($_POST['duracion_minutos']);
    $motivo          = trim($_POST['motivo']);
    $id_producto     = isset($_POST['id_producto']) ? intval($_POST['id_producto']) : 0;
    $cantidad_usada  = isset($_POST['cantidad_usada']) ? intval($_POST['cantidad_usada']) : 0;

    $id_usuario_registro = (int)$_SESSION['usuario_id'];

    // Validaciones básicas: nada puede quedar vacío.
    if (!empty($id_paciente) && !empty($id_doctor) && !empty($fecha) && !empty($hora) && !empty($motivo)) {
      if (mb_strlen($motivo) > 255) {
        $mensaje = "El motivo no puede exceder 255 caracteres.";
        $tipo_mensaje = "error";
      } else {
        $fecha_inicio = date('Y-m-d H:i:s', strtotime("$fecha $hora"));

        // sp_registrar_cita valida traslape y horario laboral del doctor,
        // inserta la cita y descuenta el insumo (si se indicó) de forma
        // atómica — todo en una sola transacción con rollback.
        try {
            $stmt_call = $pdo->prepare('CALL sp_registrar_cita(:pac, :doc, :usr, :fecha, :dur, :motivo, :prod, :cant, @id_cita)');
            $stmt_call->execute([
                ':pac'    => $id_paciente,
                ':doc'    => $id_doctor,
                ':usr'    => $id_usuario_registro,
                ':fecha'  => $fecha_inicio,
                ':dur'    => $duracion,
                ':motivo' => $motivo,
                ':prod'   => $id_producto > 0 ? $id_producto : null,
                ':cant'   => $cantidad_usada > 0 ? $cantidad_usada : null,
            ]);
            $stmt_call->closeCursor();

            $mensaje = "¡Cita agendada e inventario actualizado exitosamente!";
            $tipo_mensaje = "exito";

        } catch (\PDOException $e) {
            // El procedimiento usa el código '45000' cuando rechaza la cita a propósito (por ejemplo, traslape de horario).
            $es_error_del_procedimiento = ($e->errorInfo[0] ?? '') === '45000';

            if ($es_error_del_procedimiento) {
                $mensaje = $e->errorInfo[2] . " Aquí tiene algunas alternativas disponibles:";
                $sugerencias_horarios = obtener_horarios_disponibles($pdo, $id_doctor, $duracion, new DateTime($fecha_inicio));
            } else {
                registrar_bitacora($pdo, 'ERROR', "Falló el agendamiento de cita desde Control de Citas (paciente #$id_paciente, doctor #$id_doctor): " . $e->getMessage());
                $mensaje = "Error al procesar el registro: " . $e->getMessage();
            }
            $tipo_mensaje = "error";
        }
      }
    } else {
        $mensaje = "Por favor complete todos los campos obligatorios.";
        $tipo_mensaje = "error";
    }
}

// ¿El modal de agendar cita debe abrirse solo al cargar la página? (solo si falló el envío)
$abrir_modal_formulario = $tipo_mensaje === 'error' && isset($_POST['agendar_cita']);

// Valores del formulario para reabrir el modal con lo ya escrito si algo falla.
$form_agendar = [
    'id_paciente'      => $_POST['id_paciente'] ?? '',
    'id_doctor'        => $_POST['id_doctor'] ?? '',
    'fecha'            => $_POST['fecha'] ?? '',
    'hora'             => $_POST['hora'] ?? '',
    'duracion_minutos' => $_POST['duracion_minutos'] ?? '60',
    'motivo'           => $_POST['motivo'] ?? '',
    'id_producto'      => $_POST['id_producto'] ?? '0',
    'cantidad_usada'   => $_POST['cantidad_usada'] ?? '1',
];

// CONSULTAS PARA LOS CAMPOS DESPLEGABLES (para llenar los <select> del modal de agendar cita)
// Obtener Pacientes activos
$stmt_pacientes = $pdo->query("SELECT p.id_paciente, u.nombre, u.apellido, u.cedula
                               FROM pacientes p
                               INNER JOIN usuarios u ON p.id_usuario = u.id_usuario
                               WHERE u.estado = 1 ORDER BY u.nombre ASC");
$pacientes = $stmt_pacientes->fetchAll();

// Obtener Doctores activos
$stmt_doctores = $pdo->query("SELECT d.id_doctor, u.nombre, u.apellido, d.especialidad
                              FROM doctores d
                              INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
                              WHERE u.id_rol = 2 AND u.estado = 1 ORDER BY u.nombre ASC");
$doctores = $stmt_doctores->fetchAll();

// Obtener Servicios / Tratamientos activos
$stmt_servicios = $pdo->query("SELECT id_servicio, nombre, precio, duracion_minutos FROM servicios WHERE activo = 1 ORDER BY nombre ASC");
$servicios_lista = $stmt_servicios->fetchAll();

// Obtener Productos activos con inventario disponible
$stmt_productos = $pdo->query("SELECT id_producto, nombre, stock FROM productos WHERE activo = 1 AND stock > 0 ORDER BY nombre ASC");
$productos_lista = $stmt_productos->fetchAll();

// LÓGICA DE BÚSQUEDA, FILTROS (barra lateral) Y PAGINACIÓN
$busqueda      = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$estado_filtro = isset($_GET['estado']) && in_array($_GET['estado'], ['pendiente', 'en_espera', 'en_atencion', 'completada', 'cancelada', 'vencida'], true) ? $_GET['estado'] : '';
$doctor_filtro = isset($_GET['doctor']) ? intval($_GET['doctor']) : 0;
$fecha_desde   = isset($_GET['desde']) ? trim($_GET['desde']) : '';
$fecha_hasta   = isset($_GET['hasta']) ? trim($_GET['hasta']) : '';

// Armamos la consulta SQL en pedazos ("condiciones"), agregando solo los filtros que el usuario realmente usó.
$condiciones = [];
$params = [];

if ($busqueda !== '') {
    // Buscamos el texto en varias columnas a la vez (paciente, doctor, motivo o cédula).
    $condiciones[] = "(up.nombre LIKE :b1 OR up.apellido LIKE :b2 OR ud.nombre LIKE :b3 OR ud.apellido LIKE :b4 OR c.motivo LIKE :b5 OR up.cedula LIKE :b6)";
    $pv = '%' . $busqueda . '%';
    $params[':b1'] = $pv;
    $params[':b2'] = $pv;
    $params[':b3'] = $pv;
    $params[':b4'] = $pv;
    $params[':b5'] = $pv;
    $params[':b6'] = $pv;
}

if ($estado_filtro !== '') {
    $condiciones[] = "c.estado = :estado";
    $params[':estado'] = $estado_filtro;
}

if ($doctor_filtro > 0) {
    $condiciones[] = "c.id_doctor = :doctor";
    $params[':doctor'] = $doctor_filtro;
}

if ($fecha_desde !== '') {
    $condiciones[] = "DATE(c.fecha_hora) >= :desde";
    $params[':desde'] = $fecha_desde;
}

if ($fecha_hasta !== '') {
    $condiciones[] = "DATE(c.fecha_hora) <= :hasta";
    $params[':hasta'] = $fecha_hasta;
}

// Unimos todas las condiciones activas con "AND" para formar el WHERE final.
$where_clause = count($condiciones) > 0 ? ' WHERE ' . implode(' AND ', $condiciones) : '';
$hay_filtros_activos = $busqueda !== '' || $estado_filtro !== '' || $doctor_filtro > 0 || $fecha_desde !== '' || $fecha_hasta !== '';

// Reconstruimos los filtros como texto de URL, para que los links de paginación no los pierdan.
$query_filtros = '';
if ($busqueda !== '') $query_filtros .= '&buscar=' . urlencode($busqueda);
if ($estado_filtro !== '') $query_filtros .= '&estado=' . urlencode($estado_filtro);
if ($doctor_filtro > 0) $query_filtros .= '&doctor=' . $doctor_filtro;
if ($fecha_desde !== '') $query_filtros .= '&desde=' . urlencode($fecha_desde);
if ($fecha_hasta !== '') $query_filtros .= '&hasta=' . urlencode($fecha_hasta);

$limite = 5; // Cuántas citas se muestran por página.
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

// Contamos cuántas citas hay en total con estos filtros (para saber cuántas páginas hacen falta).
$sql_total = "SELECT COUNT(*)
              FROM citas c
              INNER JOIN pacientes p ON c.id_paciente = p.id_paciente
              INNER JOIN usuarios up ON p.id_usuario = up.id_usuario
              INNER JOIN doctores d ON c.id_doctor = d.id_doctor
              INNER JOIN usuarios ud ON d.id_usuario = ud.id_usuario"
              . $where_clause;
$stmt_total = $pdo->prepare($sql_total);
$stmt_total->execute($params);
$total_registros = (int) $stmt_total->fetchColumn();

$total_paginas = max(1, (int) ceil($total_registros / $limite));
if ($pagina > $total_paginas) {
    $pagina = $total_paginas;
}

$inicio_int = (int) (($pagina - 1) * $limite); // Desde qué fila empezar (para el LIMIT de SQL).
$limite_int = (int) $limite;

// Obtener Lista de Citas Programadas (filtrada y paginada)
$sql_citas = "SELECT c.id_cita, c.fecha_hora, c.duracion_minutos, c.motivo, c.estado,
                     c.hora_llegada, c.hora_inicio_atencion, c.es_control,
                     up.nombre AS paciente_nombre, up.apellido AS paciente_apellido, up.cedula,
                     ud.nombre AS doctor_nombre, ud.apellido AS doctor_apellido, d.especialidad,
                     d.id_usuario AS doctor_id_usuario,
                     (SELECT COUNT(*) FROM historial_medico hm WHERE hm.id_cita = c.id_cita) AS tiene_expediente
              FROM citas c
              INNER JOIN pacientes p ON c.id_paciente = p.id_paciente
              INNER JOIN usuarios up ON p.id_usuario = up.id_usuario
              INNER JOIN doctores d ON c.id_doctor = d.id_doctor
              INNER JOIN usuarios ud ON d.id_usuario = ud.id_usuario"
              . $where_clause .
              " ORDER BY c.fecha_hora DESC
              LIMIT $inicio_int, $limite_int";
$stmt_citas = $pdo->prepare($sql_citas);
$stmt_citas->execute($params);
$lista_citas = $stmt_citas->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Citas - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <!-- Hojas de estilo -->
    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/home.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <!-- Estilos del formulario de agendamiento (compartidos con otras páginas) -->
    <link rel="stylesheet" href="assets/css/shared/gestionCitas.css">
    <!-- Estilos exclusivos del dashboard de citas -->
    <link rel="stylesheet" href="assets/css/pages/control_citas.css">
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
                <h1>Gestión de <span class="grad">Citas</span></h1>
            </div>
        </div>
    </section>

    <section class="wrap dash-wide">

        <!-- LAYOUT: SIDEBAR DE FILTROS + CONTENIDO -->
        <form action="control_citas.php" method="GET" class="dash-layout" id="formFiltros">

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
                    <label for="doctor">Doctor</label>
                    <select name="doctor" id="doctor" class="form-control">
                        <option value="">Todos los doctores</option>
                        <?php foreach ($doctores as $doc): ?>
                            <option value="<?= (int) $doc['id_doctor'] ?>" <?= $doctor_filtro === (int) $doc['id_doctor'] ? 'selected' : '' ?>>
                                Dr(a). <?= htmlspecialchars($doc['nombre'] . ' ' . $doc['apellido']) ?>
                            </option>
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
                    <?php if ($hay_filtros_activos): ?>
                        <a href="control_citas.php" class="btn-cancel">Limpiar filtros</a>
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
                    <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por paciente, doctor, cédula o motivo..." class="form-control">
                    <button type="submit" class="btn-primary">Buscar</button>
                </div>

                <p class="dash-results-count">
                    <?= $total_registros ?> cita(s) encontrada(s)<?= $hay_filtros_activos ? ' con los filtros aplicados' : '' ?>.
                </p>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha y Hora</th>
                                <th>Paciente</th>
                                <th>Doctor</th>
                                <th>Motivo</th>
                                <th>Duración</th>
                                <th>Estado</th>
                                <th>Espera</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($lista_citas) > 0): ?>
                                <?php foreach ($lista_citas as $c): ?>
                                    <?php
                                        // Calculamos, para cada cita, qué acciones puede hacer el usuario actual con ella.
                                        $es_hoy = date('Y-m-d', strtotime($c['fecha_hora'])) === date('Y-m-d');
                                        $puede_marcar_llegada = $c['estado'] === 'pendiente' && $es_hoy && in_array($id_rol_sesion, [3, 4], true);
                                        $puede_iniciar_atencion = $c['estado'] === 'en_espera'
                                            && ($id_rol_sesion === 4 || (int) $c['doctor_id_usuario'] === (int) $_SESSION['usuario_id']);
                                        $puede_documentar = !in_array($c['estado'], ['cancelada', 'vencida'], true)
                                            && ($id_rol_sesion === 4 || (int) $c['doctor_id_usuario'] === (int) $_SESSION['usuario_id']);
                                        // Recepción no documenta, pero sí necesita poder consultar el
                                        // historial del paciente al recibirlo (modo solo lectura).
                                        $puede_ver_historial = !$puede_documentar && $id_rol_sesion === 3
                                            && !in_array($c['estado'], ['cancelada', 'vencida'], true);

                                        // Cuántos minutos lleva (o llevó) esperando el paciente, según en qué estado esté la cita.
                                        $minutos_espera = null;
                                        if ($c['estado'] === 'en_espera' && $c['hora_llegada']) {
                                            $minutos_espera = (int) floor((time() - strtotime($c['hora_llegada'])) / 60);
                                        } elseif (in_array($c['estado'], ['en_atencion', 'completada'], true) && $c['hora_llegada'] && $c['hora_inicio_atencion']) {
                                            $minutos_espera = (int) floor((strtotime($c['hora_inicio_atencion']) - strtotime($c['hora_llegada'])) / 60);
                                        }
                                    ?>
                                    <tr class="fila-cita">
                                        <td><?= date('d/m/Y - h:i A', strtotime($c['fecha_hora'])) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($c['paciente_nombre'] . ' ' . $c['paciente_apellido']) ?></strong><br>
                                            <small style="color: var(--muted);">Ced: <?= htmlspecialchars($c['cedula'] ?? 'N/A') ?></small>
                                        </td>
                                        <td>
                                            Dr(a). <?= htmlspecialchars($c['doctor_nombre'] . ' ' . $c['doctor_apellido']) ?><br>
                                            <small style="color: var(--muted);"><?= htmlspecialchars($c['especialidad']) ?></small>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($c['motivo'] ?? '—') ?>
                                            <?php if ((int) $c['es_control'] === 1): ?>
                                                <br><small style="color: var(--cyan);">↻ Cita de control</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $c['duracion_minutos'] ?> min</td>
                                        <td>
                                            <span class="badge badge-<?= $c['estado'] ?>">
                                                <?= str_replace('_', ' ', $c['estado']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($minutos_espera === null): ?>
                                                <span class="muted-text">—</span>
                                            <?php else: ?>
                                                <span class="badge <?= $minutos_espera >= 60 ? 'badge-cancelada' : 'badge-en_espera' ?>">
                                                    <?= $c['estado'] === 'en_espera' ? 'Esperando' : 'Esperó' ?> <?= $minutos_espera ?> min
                                                </span>
                                                <?php if ($minutos_espera >= 60): ?>
                                                    <br><small style="color: var(--muted);">La cita anterior tomó más tiempo del previsto.</small>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="dash-actions">
                                                <?php if ($puede_marcar_llegada): ?>
                                                    <a href="control_citas.php?accion=llegada&id=<?= $c['id_cita'] ?>" class="btn-action btn-edit">Registrar llegada</a>
                                                <?php endif; ?>
                                                <?php if ($puede_iniciar_atencion): ?>
                                                    <a href="control_citas.php?accion=iniciar_atencion&id=<?= $c['id_cita'] ?>" class="btn-action btn-edit">Iniciar atención</a>
                                                <?php endif; ?>
                                                <?php if ($puede_documentar): ?>
                                                    <a href="expediente.php?id_cita=<?= $c['id_cita'] ?>" class="btn-action btn-edit">
                                                        <?= $c['tiene_expediente'] > 0 ? 'Editar expediente' : 'Completar' ?>
                                                    </a>
                                                <?php elseif ($puede_ver_historial): ?>
                                                    <a href="expediente.php?id_cita=<?= $c['id_cita'] ?>" class="btn-action btn-edit">Ver historial</a>
                                                <?php elseif ($c['tiene_expediente'] > 0): ?>
                                                    <span class="badge badge-success">✓ Documentada</span>
                                                <?php endif; ?>
                                                <?php if (!$puede_marcar_llegada && !$puede_iniciar_atencion && !$puede_documentar && $c['tiene_expediente'] == 0): ?>
                                                    <span class="muted-text">—</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="muted-text text-center">No se encontraron citas con estos filtros.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_paginas > 1): ?>
                    <div class="pagination-container">
                        <span class="pagination-info">Página <strong><?= $pagina ?></strong> de <strong><?= $total_paginas ?></strong> (<?= $total_registros ?> citas)</span>
                        <div class="pagination">
                            <?php if ($pagina > 1): ?>
                                <a href="control_citas.php?pagina=<?= $pagina - 1 ?><?= $query_filtros ?>" class="page-link">&laquo; Anterior</a>
                            <?php endif; ?>
                            <?php foreach (obtener_paginas_visibles($pagina, $total_paginas) as $item): ?>
                                <?php if ($item === '...'): ?>
                                    <span class="page-ellipsis">&hellip;</span>
                                <?php else: ?>
                                    <a href="control_citas.php?pagina=<?= $item ?><?= $query_filtros ?>" class="page-link <?= $item === $pagina ? 'active' : '' ?>"><?= $item ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($pagina < $total_paginas): ?>
                                <a href="control_citas.php?pagina=<?= $pagina + 1 ?><?= $query_filtros ?>" class="page-link">Siguiente &raquo;</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </form>
    </section>

    <!-- MODAL: AGENDAR CITA -->
    <div id="modalCita" class="dash-modal-overlay">
        <div class="dash-modal-card" style="max-width: 640px;">
            <div class="dash-modal-head">
                <button type="button" class="dash-modal-close" onclick="cerrarModalCita()" aria-label="Cerrar">&times;</button>
                <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                    <span class="eyebrow">Nueva cita</span>
                    <h2 style="margin:4px 0 0;">Agendar cita</h2>
                </div>
            </div>

            <?php if (!empty($mensaje) && $abrir_modal_formulario): ?>
                <?php if (!empty($sugerencias_horarios)): ?>
                    <div style="margin-bottom: 24px; padding: 16px; background: rgba(255, 193, 7, 0.1); border-radius: 12px; border-left: 4px solid var(--warning, #ffc107);">
                        <strong style="display: block; margin-bottom: 12px; color: var(--text);">Horarios disponibles con ese doctor:</strong>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                            <?php foreach ($sugerencias_horarios as $sugerencia):
                                $fecha_sugerencia = new DateTime($sugerencia);
                            ?>
                                <button type="button" class="btn-suggestion" onclick="seleccionarSugerenciaCita('<?= htmlspecialchars($fecha_sugerencia->format('Y-m-d')) ?>', '<?= htmlspecialchars($fecha_sugerencia->format('H:i')) ?>')" style="padding: 8px 12px; background: var(--bg-panel-2); border: 1px solid var(--line); border-radius: 8px; color: var(--text); cursor: pointer; font-size: 0.9rem;">
                                    <?= htmlspecialchars($fecha_sugerencia->format('d/m/Y \a \l\a\s H:i')) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form action="control_citas.php" method="POST">
                <input type="hidden" name="agendar_cita" value="1">

                <div class="sec-head citas-section-head">
                    <span class="eyebrow">Paso 1</span>
                    <h2>Paciente y Especialista</h2>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="id_paciente"><span class="dash-req">*</span> Paciente Registrado</label>
                        <select name="id_paciente" id="id_paciente" required class="form-control">
                            <option value="">-- Selecciona un paciente --</option>
                            <?php foreach ($pacientes as $pac): ?>
                                <option value="<?= $pac['id_paciente'] ?>" <?= (string) $form_agendar['id_paciente'] === (string) $pac['id_paciente'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pac['nombre'] . ' ' . $pac['apellido'] . ' (Cédula: ' . $pac['cedula'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="id_doctor"><span class="dash-req">*</span> Doctor / Especialista</label>
                        <select name="id_doctor" id="id_doctor" required class="form-control">
                            <option value="">-- Selecciona un especialista --</option>
                            <?php foreach ($doctores as $doc): ?>
                                <option value="<?= $doc['id_doctor'] ?>" <?= (string) $form_agendar['id_doctor'] === (string) $doc['id_doctor'] ? 'selected' : '' ?>>
                                    Dr(a). <?= htmlspecialchars($doc['nombre'] . ' ' . $doc['apellido'] . ' - ' . $doc['especialidad']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="sec-head citas-section-head">
                    <span class="eyebrow">Paso 2</span>
                    <h2>Tratamiento / Servicio</h2>
                </div>

                <div class="form-group">
                    <label for="select_servicio">Servicio de Catálogo</label>
                    <select id="select_servicio" onchange="actualizarServicio(this)" class="form-control">
                        <option value="" data-precio="0" data-duracion="60" data-nombre="">-- Selecciona un tratamiento --</option>
                        <?php foreach ($servicios_lista as $srv): ?>
                            <option value="<?= $srv['id_servicio'] ?>"
                                    data-precio="<?= $srv['precio'] ?>"
                                    data-duracion="<?= $srv['duracion_minutos'] ?>"
                                    data-nombre="<?= htmlspecialchars($srv['nombre']) ?>">
                                <?= htmlspecialchars($srv['nombre']) ?> — ₡<?= number_format($srv['precio'], 2) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="caja-precio-servicio" style="margin: 12px 0 20px;">
                    <span class="label">Precio del Servicio</span>
                    <strong id="precio_servicio_display" class="precio">₡0.00</strong>
                </div>

                <div class="sec-head citas-section-head">
                    <span class="eyebrow">Paso 3</span>
                    <h2>Horario y Detalles</h2>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="fecha"><span class="dash-req">*</span> Fecha</label>
                        <input type="date" id="fecha" name="fecha" required min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($form_agendar['fecha']) ?>" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="hora"><span class="dash-req">*</span> Hora</label>
                        <input type="time" id="hora" name="hora" required value="<?= htmlspecialchars($form_agendar['hora']) ?>" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="duracion_minutos">Duración estimada</label>
                        <select name="duracion_minutos" id="duracion_minutos" class="form-control">
                            <?php foreach ([30 => '30 minutos', 45 => '45 minutos', 60 => '1 hora', 90 => '1 hora 30 min', 120 => '2 horas'] as $valor => $etiqueta): ?>
                                <option value="<?= $valor ?>" <?= (string) $form_agendar['duracion_minutos'] === (string) $valor ? 'selected' : '' ?>><?= $etiqueta ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-full">
                        <label for="motivo"><span class="dash-req">*</span> Motivo / Descripción de la Consulta</label>
                        <input type="text" id="motivo" name="motivo" placeholder="Ej: Limpieza dental, Calza, Dolor agudo..." required maxlength="255" value="<?= htmlspecialchars($form_agendar['motivo']) ?>" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="id_producto">Insumo Utilizado (Opcional)</label>
                        <select name="id_producto" id="id_producto" class="form-control">
                            <option value="0">-- Ningún insumo a descontar --</option>
                            <?php foreach ($productos_lista as $prod): ?>
                                <option value="<?= $prod['id_producto'] ?>" <?= (string) $form_agendar['id_producto'] === (string) $prod['id_producto'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($prod['nombre']) ?> (Stock actual: <?= $prod['stock'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="cantidad_usada">Cantidad a descontar</label>
                        <input type="number" min="1" value="<?= htmlspecialchars($form_agendar['cantidad_usada']) ?>" id="cantidad_usada" name="cantidad_usada" class="form-control">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModalCita()">Cancelar</button>
                    <button type="submit" class="btn-primary btn-guardar">Guardar Cita</button>
                </div>
            </form>
        </div>
    </div>

    <?php require_once 'assets/includes/partials/footer.php'; ?>

    <script>
        const modalCita = document.getElementById('modalCita');

        function abrirModalCita() {
            modalCita.classList.add('active');
        }

        function cerrarModalCita() {
            modalCita.classList.remove('active');
        }

        // Cierra el modal si se hace clic fuera de la tarjeta (en el fondo oscuro).

        modalCita.addEventListener('click', function (e) {
            if (e.target === this) cerrarModalCita();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') cerrarModalCita();
        });

        <?php if ($abrir_modal_formulario): ?>
            abrirModalCita();
        <?php endif; ?>

        // Al hacer clic en un horario sugerido (cuando hubo traslape), rellena fecha y hora automáticamente.
        function seleccionarSugerenciaCita(fecha, hora) {
            document.getElementById('fecha').value = fecha;
            document.getElementById('hora').value = hora;
        }

        // Al elegir un servicio del catálogo, autocompleta precio mostrado, motivo y duración estimada.
        function actualizarServicio(select) {
            const option = select.options[select.selectedIndex];
            const precio = parseFloat(option.getAttribute('data-precio') || 0);
            const duracion = option.getAttribute('data-duracion');
            const nombre = option.getAttribute('data-nombre');

            document.getElementById('precio_servicio_display').textContent = '₡' + precio.toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            if (nombre) {
                document.getElementById('motivo').value = nombre;
            }

            if (duracion) {
                const selectDuracion = document.getElementById('duracion_minutos');
                for (let i = 0; i < selectDuracion.options.length; i++) {
                    if (selectDuracion.options[i].value == duracion) {
                        selectDuracion.selectedIndex = i;
                        break;
                    }
                }
            }
        }
    </script>

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
    <?php if (!empty($mensaje_toast)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                mostrarToast(<?= json_encode($mensaje_toast) ?>, <?= json_encode($tipo_toast) ?>);
            });
        </script>
    <?php endif; ?>

</body>
</html>
