<?php
/*
    expediente.php — Expediente odontológico.

    Permite al doctor asignado a una cita (o al administrador) documentar
    la atención: diagnóstico, tratamiento, observaciones y próxima revisión
    (historial_medico), además del resultado breve de la cita y su cierre
    (citas.resultado / citas.estado = 'completada').

    También muestra el historial clínico previo del paciente, para que el
    doctor tenga contexto antes de escribir la nueva entrada.
*/

session_start();
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/bitacora_helper.php';
require_once './assets/includes/helpers/horarios_helper.php';

// Guard: doctor (2) y administrador (4) pueden documentar; recepcionista (3)
// puede ENTRAR en modo solo lectura, para consultar el historial del
// paciente al momento de recibirlo (ver $puede_editar más abajo).
$id_rol = (int) ($_SESSION['id_rol'] ?? 0);
if (!in_array($id_rol, [2, 3, 4], true)) {
    header('Location: inicio.php');
    exit;
}
$puede_editar = in_array($id_rol, [2, 4], true);

$id_cita = isset($_GET['id_cita']) ? intval($_GET['id_cita']) : 0; // El id de la cita viene en la URL.
if ($id_cita <= 0) {
    header('Location: control_citas.php');
    exit;
}

// Cargar la cita junto con paciente y doctor.
$stmt_cita = $pdo->prepare(
    "SELECT c.*,
            p.id_paciente,
            up.nombre AS paciente_nombre, up.apellido AS paciente_apellido, up.cedula AS paciente_cedula,
            d.id_doctor, d.id_usuario AS doctor_id_usuario,
            ud.nombre AS doctor_nombre, ud.apellido AS doctor_apellido
     FROM citas c
     INNER JOIN pacientes p ON c.id_paciente = p.id_paciente
     INNER JOIN usuarios up ON p.id_usuario = up.id_usuario
     INNER JOIN doctores d ON c.id_doctor = d.id_doctor
     INNER JOIN usuarios ud ON d.id_usuario = ud.id_usuario
     WHERE c.id_cita = :id"
);
$stmt_cita->execute([':id' => $id_cita]);
$cita = $stmt_cita->fetch();

if (!$cita) {
    header('Location: control_citas.php');
    exit;
}

// Un doctor solo puede documentar SUS PROPIAS citas.
if ($id_rol === 2 && (int) $cita['doctor_id_usuario'] !== (int) $_SESSION['usuario_id']) {
    header('Location: control_citas.php');
    exit;
}

// No tiene sentido documentar una cita cancelada o vencida.
if (in_array($cita['estado'], ['cancelada', 'vencida'], true)) {
    header('Location: control_citas.php?msg=cita_no_disponible');
    exit;
}

// Antecedentes médicos y alergias del paciente (información de seguridad
// que el doctor debe poder consultar en cada nueva cita).
$stmt_clinico = $pdo->prepare("SELECT antecedentes_medicos, alergias FROM pacientes WHERE id_paciente = :id_paciente LIMIT 1");
$stmt_clinico->execute([':id_paciente' => $cita['id_paciente']]);
$datos_clinicos_paciente = $stmt_clinico->fetch();

// ¿Ya existe una entrada de expediente para esta cita? (permite editarla)
$stmt_existente = $pdo->prepare("SELECT * FROM historial_medico WHERE id_cita = :id_cita LIMIT 1");
$stmt_existente->execute([':id_cita' => $id_cita]);
$entrada_existente = $stmt_existente->fetch();

// Precargamos el formulario con lo que ya existía (si el doctor está editando) o vacío (si es nuevo).
$form = [
    'resultado'        => $cita['resultado'] ?? '',
    'diagnostico'       => $entrada_existente['diagnostico'] ?? '',
    'tratamiento'       => $entrada_existente['tratamiento'] ?? '',
    'medicamentos'      => $entrada_existente['medicamentos'] ?? '',
    'observaciones'     => $entrada_existente['observaciones'] ?? '',
    'proxima_revision'  => $entrada_existente['proxima_revision'] ?? '',
    'hora_control'      => '09:00',
    'notas_control'     => '',
];

// ¿Ya existe una cita de control agendada a partir de esta? (evita duplicarla al editar el expediente)
$stmt_control_existente = $pdo->prepare(
    "SELECT id_cita FROM citas WHERE id_paciente = :id_paciente AND id_doctor = :id_doctor
     AND es_control = 1 AND estado NOT IN ('cancelada', 'vencida') AND fecha_hora > :fecha_cita_actual LIMIT 1"
);
$stmt_control_existente->execute([
    ':id_paciente'        => $cita['id_paciente'],
    ':id_doctor'          => $cita['id_doctor'],
    ':fecha_cita_actual'  => $cita['fecha_hora'],
]);
$control_ya_agendado = (bool) $stmt_control_existente->fetch();

$mensaje = '';
$tipo_mensaje = '';

// Si el doctor (o admin) envió el formulario con el diagnóstico/tratamiento de la cita...
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_expediente']) && $puede_editar) {
    $resultado         = trim($_POST['resultado'] ?? '');
    $diagnostico       = trim($_POST['diagnostico'] ?? '');
    $tratamiento       = trim($_POST['tratamiento'] ?? '');
    $medicamentos      = trim($_POST['medicamentos'] ?? '');
    $observaciones     = trim($_POST['observaciones'] ?? '');
    $proxima_revision  = !empty($_POST['proxima_revision']) ? $_POST['proxima_revision'] : null;
    $hora_control      = trim($_POST['hora_control'] ?? '') !== '' ? trim($_POST['hora_control']) : '09:00';
    $notas_control     = trim($_POST['notas_control'] ?? '');

    $form = [
        'resultado'        => $resultado,
        'diagnostico'      => $diagnostico,
        'tratamiento'      => $tratamiento,
        'medicamentos'     => $medicamentos,
        'observaciones'    => $observaciones,
        'proxima_revision' => $proxima_revision ?? '',
        'hora_control'     => $hora_control,
        'notas_control'    => $notas_control,
    ];

    $fecha_hoy = date('Y-m-d');
    // Solo se agenda la cita de control si el doctor indicó una fecha Y todavía no existe una ya agendada.
    $agendar_control = $proxima_revision !== null && !$control_ya_agendado;

    if ($diagnostico === '' || $tratamiento === '') {
        $mensaje = 'El diagnóstico y el tratamiento son obligatorios.';
        $tipo_mensaje = 'error';
    } elseif ($proxima_revision !== null && $proxima_revision < $fecha_hoy) {
        $mensaje = 'La fecha de control no puede ser anterior a hoy.';
        $tipo_mensaje = 'error';
    } elseif ($agendar_control && !DateTime::createFromFormat('H:i', $hora_control)) {
        $mensaje = 'La hora de control no tiene un formato válido.';
        $tipo_mensaje = 'error';
    } else {
        // Sin errores de validación: guardamos.
        try {
            $pdo->beginTransaction();

            if ($entrada_existente) {
                // Ya había una entrada para esta cita: la actualizamos en vez de duplicarla.
                $stmt_upd = $pdo->prepare(
                    "UPDATE historial_medico
                     SET diagnostico = :diagnostico, tratamiento = :tratamiento, medicamentos = :medicamentos,
                         observaciones = :observaciones, proxima_revision = :proxima_revision
                     WHERE id_historial = :id"
                );
                $stmt_upd->execute([
                    ':diagnostico'      => $diagnostico,
                    ':tratamiento'      => $tratamiento,
                    ':medicamentos'     => $medicamentos !== '' ? $medicamentos : null,
                    ':observaciones'    => $observaciones !== '' ? $observaciones : null,
                    ':proxima_revision' => $proxima_revision,
                    ':id'               => $entrada_existente['id_historial'],
                ]);
                $accion_bitacora = 'UPDATE';
                $texto_bitacora  = "Se actualizó el expediente clínico de la cita #$id_cita.";
            } else {
                // Es la primera vez que se documenta esta cita: insertamos una entrada nueva.
                $stmt_ins = $pdo->prepare(
                    "INSERT INTO historial_medico (id_paciente, id_doctor, id_cita, fecha_revision, diagnostico, tratamiento, medicamentos, observaciones, proxima_revision)
                     VALUES (:id_paciente, :id_doctor, :id_cita, NOW(), :diagnostico, :tratamiento, :medicamentos, :observaciones, :proxima_revision)"
                );
                $stmt_ins->execute([
                    ':id_paciente'      => $cita['id_paciente'],
                    ':id_doctor'        => $cita['id_doctor'],
                    ':id_cita'          => $id_cita,
                    ':diagnostico'      => $diagnostico,
                    ':tratamiento'      => $tratamiento,
                    ':medicamentos'     => $medicamentos !== '' ? $medicamentos : null,
                    ':observaciones'    => $observaciones !== '' ? $observaciones : null,
                    ':proxima_revision' => $proxima_revision,
                ]);
                $accion_bitacora = 'INSERT';
                $texto_bitacora  = "Se registró el expediente clínico de la cita #$id_cita.";
            }

            // Documentar el expediente cierra la cita como "completada".
            $stmt_cita_upd = $pdo->prepare("UPDATE citas SET estado = 'completada', resultado = :resultado WHERE id_cita = :id");
            $stmt_cita_upd->execute([
                ':resultado' => $resultado !== '' ? $resultado : null,
                ':id'        => $id_cita,
            ]);

            // Programación de control: se agenda automáticamente la cita de seguimiento
            // que el doctor indicó (fecha, hora y notas), reubicándola si el doctor ya
            // tiene otra cita en ese horario exacto.
            $control_info = null;
            if ($agendar_control) {
                $duracion_control  = 30; // La cita de control siempre dura 30 minutos.
                $fecha_hora_control = $proxima_revision . ' ' . $hora_control . ':00';

                // Revisamos si el doctor ya tiene otra cita que se cruce con este horario.
                $stmt_traslape_control = $pdo->prepare("
                    SELECT COUNT(*) FROM citas
                    WHERE id_doctor = :id_doctor
                      AND estado NOT IN ('cancelada', 'vencida')
                      AND fecha_hora < DATE_ADD(:fecha_hora_control, INTERVAL :duracion MINUTE)
                      AND DATE_ADD(fecha_hora, INTERVAL duracion_minutos MINUTE) > :fecha_hora_control
                ");
                $stmt_traslape_control->execute([
                    ':id_doctor'           => $cita['id_doctor'],
                    ':fecha_hora_control'  => $fecha_hora_control,
                    ':duracion'            => $duracion_control,
                ]);

                $fuera_de_horario = !validar_horario_doctor($pdo, (int) $cita['id_doctor'], new DateTime($fecha_hora_control), $duracion_control);

                // Si hay traslape o el horario está fuera de lo que trabaja el doctor, buscamos el espacio libre más cercano.
                if ((int) $stmt_traslape_control->fetchColumn() > 0 || $fuera_de_horario) {
                    $sugerencias_control = obtener_horarios_disponibles($pdo, (int) $cita['id_doctor'], $duracion_control, new DateTime($fecha_hora_control));

                    if (!empty($sugerencias_control)) {
                        // Usamos automáticamente la primera sugerencia libre, sin pedirle confirmación al doctor.
                        $fecha_hora_control = $sugerencias_control[0] . ':00';
                        $control_info = 'ajustado';
                    } else {
                        // No encontramos ningún espacio libre en las próximas dos semanas: no se agenda.
                        $control_info = 'no_disponible';
                    }
                }

                if ($control_info !== 'no_disponible') {
                    // Insertamos la cita de control (marcada con es_control=1, para distinguirla de una cita normal).
                    $motivo_control = $notas_control !== '' ? $notas_control : 'Cita de control / seguimiento';
                    $stmt_control_ins = $pdo->prepare(
                        "INSERT INTO citas (id_paciente, id_doctor, id_usuario_registro, fecha_hora, duracion_minutos, motivo, estado, es_control)
                         VALUES (:id_paciente, :id_doctor, :id_usuario_registro, :fecha_hora, :duracion_minutos, :motivo, 'pendiente', 1)"
                    );
                    $stmt_control_ins->execute([
                        ':id_paciente'         => $cita['id_paciente'],
                        ':id_doctor'           => $cita['id_doctor'],
                        ':id_usuario_registro' => (int) $_SESSION['usuario_id'],
                        ':fecha_hora'          => $fecha_hora_control,
                        ':duracion_minutos'    => $duracion_control,
                        ':motivo'              => $motivo_control,
                    ]);

                    $control_info = $control_info ?? 'agendado'; // Si no se ajustó, quedó agendada tal cual se pidió.
                }
            }

            $pdo->commit();

            registrar_bitacora($pdo, $accion_bitacora, $texto_bitacora);

            $redirect = 'control_citas.php?msg=expediente_guardado';
            if ($control_info !== null) {
                $redirect .= '&control=' . $control_info;
            }

            header('Location: ' . $redirect);
            exit;

        } catch (\PDOException $e) {
            $pdo->rollBack();
            registrar_bitacora($pdo, 'ERROR', "Falló guardar el expediente de la cita #$id_cita: " . $e->getMessage());
            $mensaje = 'Ocurrió un error al guardar el expediente.';
            $tipo_mensaje = 'error';
        }
    }
}

// Historial clínico previo del paciente (para dar contexto al doctor), excluyendo la cita actual.
$stmt_previas = $pdo->prepare(
    "SELECT hm.*, CONCAT(ud2.nombre, ' ', ud2.apellido) AS doctor_nombre_completo
     FROM historial_medico hm
     INNER JOIN doctores d2 ON hm.id_doctor = d2.id_doctor
     INNER JOIN usuarios ud2 ON d2.id_usuario = ud2.id_usuario
     WHERE hm.id_paciente = :id_paciente
       AND (hm.id_cita IS NULL OR hm.id_cita <> :id_cita)
     ORDER BY hm.fecha_revision DESC
     LIMIT 10"
);
$stmt_previas->execute([':id_paciente' => $cita['id_paciente'], ':id_cita' => $id_cita]);
$historial_previo = $stmt_previas->fetchAll();

// Exámenes/radiografías solicitados para este paciente (de cualquier cita, no solo esta).
$stmt_examenes = $pdo->prepare(
    "SELECT e.*, CONCAT(ud3.nombre, ' ', ud3.apellido) AS doctor_solicita
     FROM examenes e
     INNER JOIN doctores d3 ON e.id_doctor = d3.id_doctor
     INNER JOIN usuarios ud3 ON d3.id_usuario = ud3.id_usuario
     WHERE e.id_paciente = :id_paciente
     ORDER BY e.fecha_solicitud DESC
     LIMIT 10"
);
$stmt_examenes->execute([':id_paciente' => $cita['id_paciente']]);
$examenes_paciente = $stmt_examenes->fetchAll();
$mapa_badge_examen = ['solicitado' => 'pendiente', 'realizado' => 'completada', 'cancelado' => 'cancelada'];
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expediente Odontológico - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <link rel="stylesheet" href="assets/css/shared/gestionCitas.css">
    <link rel="stylesheet" href="assets/css/shared/toast.css">
</head>

<body>

    <div class="grid-overlay"></div>
    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>

    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <section class="wrap citas-hero">
        <div class="sec-head">
            <span class="eyebrow">Expediente Odontológico</span>
            <h1><?= $entrada_existente ? 'Editar' : 'Documentar' ?> Atención de <span class="grad"><?= htmlspecialchars($cita['paciente_nombre'] . ' ' . $cita['paciente_apellido']) ?></span></h1>
            <p class="lead">
                Cita del <?= date('d/m/Y \a \l\a\s h:i A', strtotime($cita['fecha_hora'])) ?> con Dr(a). <?= htmlspecialchars($cita['doctor_nombre'] . ' ' . $cita['doctor_apellido']) ?>.
                Motivo: <?= htmlspecialchars($cita['motivo'] ?: 'No especificado') ?>.
            </p>
        </div>
    </section>

    <section class="wrap citas-form-wrap">

        <?php $tiene_alergias = !empty($datos_clinicos_paciente['alergias']); ?>
        <div class="card" style="padding: 22px; margin-bottom: 24px; border-left: 4px solid <?= $tiene_alergias ? 'var(--pink, #e74c3c)' : 'var(--line)' ?>;">
            <div class="grid-dos-columnas" style="margin-bottom: 0;">
                <div>
                    <span class="eyebrow">Antecedentes médicos</span>
                    <p style="margin-top: 6px;"><?= nl2br(htmlspecialchars($datos_clinicos_paciente['antecedentes_medicos'] ?? '' ?: 'No registrados.')) ?></p>
                </div>
                <div>
                    <span class="eyebrow" style="<?= $tiene_alergias ? 'color: var(--pink, #e74c3c);' : '' ?>">⚠ Alergias</span>
                    <p style="margin-top: 6px; <?= $tiene_alergias ? 'font-weight: 600;' : '' ?>"><?= nl2br(htmlspecialchars($datos_clinicos_paciente['alergias'] ?? '' ?: 'No registradas.')) ?></p>
                </div>
            </div>
        </div>

        <div class="card citas-card">
            <form action="expediente.php?id_cita=<?= $id_cita ?>" method="POST">
                <input type="hidden" name="guardar_expediente" value="1">

                <div class="sec-head citas-section-head">
                    <span class="eyebrow">Cierre de la cita</span>
                    <h2>Resultado de la Atención</h2>
                </div>

                <?php if (!$puede_editar): ?>
                    <div class="alert alert-info" style="margin-bottom: 20px;">
                        Estás viendo este expediente en modo solo lectura. Únicamente el doctor asignado o un administrador pueden documentarlo.
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="resultado">Resumen breve de lo atendido</label>
                    <textarea id="resultado" name="resultado" rows="2" placeholder="Ej: Se realizó limpieza dental sin complicaciones." <?= $puede_editar ? '' : 'disabled' ?>><?= htmlspecialchars($form['resultado']) ?></textarea>
                </div>

                <div class="sec-head citas-section-head">
                    <span class="eyebrow">Expediente clínico</span>
                    <h2>Diagnóstico y Tratamiento</h2>
                </div>

                <div class="grid-dos-columnas">
                    <div class="form-group">
                        <label for="diagnostico"><span class="dash-req">*</span> Diagnóstico</label>
                        <textarea id="diagnostico" name="diagnostico" rows="4" <?= $puede_editar ? 'required' : 'disabled' ?> placeholder="Ej: Caries en pieza 26, gingivitis leve."><?= htmlspecialchars($form['diagnostico']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="tratamiento"><span class="dash-req">*</span> Tratamiento realizado</label>
                        <textarea id="tratamiento" name="tratamiento" rows="4" <?= $puede_editar ? 'required' : 'disabled' ?> placeholder="Ej: Resina compuesta en pieza 26, profilaxis."><?= htmlspecialchars($form['tratamiento']) ?></textarea>
                    </div>
                </div>

                <div class="form-group">
                    <label for="medicamentos">Medicamentos indicados <span class="dash-hint">(opcional)</span></label>
                    <textarea id="medicamentos" name="medicamentos" rows="2" placeholder="Ej: Ibuprofeno 400mg cada 8 horas por 3 días." <?= $puede_editar ? '' : 'disabled' ?>><?= htmlspecialchars($form['medicamentos']) ?></textarea>
                </div>

                <div class="form-group">
                    <label for="observaciones">Observaciones <span class="dash-hint">(opcional)</span></label>
                    <textarea id="observaciones" name="observaciones" rows="3" placeholder="Recomendaciones, cuidados posteriores..." <?= $puede_editar ? '' : 'disabled' ?>><?= htmlspecialchars($form['observaciones']) ?></textarea>
                </div>

                <div class="sec-head citas-section-head">
                    <span class="eyebrow">Seguimiento</span>
                    <h2>Programación de Control</h2>
                </div>

                <?php if ($control_ya_agendado): ?>
                    <div class="alert alert-exito" style="margin-bottom: 24px;">
                        Ya existe una cita de control pendiente para este paciente con este doctor. Si necesita cambiarla, hágalo desde el listado de citas.
                    </div>
                <?php else: ?>
                    <p class="muted-text" style="margin: -12px 0 16px;">Si indica una fecha, el sistema agenda automáticamente la cita de control (30 min); si ese horario ya está ocupado, se reubica al espacio disponible más cercano.</p>
                    <div class="grid-dos-columnas">
                        <div class="form-group">
                            <label for="proxima_revision">Fecha de control <span class="dash-hint">(opcional)</span></label>
                            <input type="date" id="proxima_revision" name="proxima_revision" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($form['proxima_revision']) ?>" <?= $puede_editar ? '' : 'disabled' ?>>
                        </div>
                        <div class="form-group">
                            <label for="hora_control">Hora sugerida</label>
                            <input type="time" id="hora_control" name="hora_control" value="<?= htmlspecialchars($form['hora_control']) ?>" <?= $puede_editar ? '' : 'disabled' ?>>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="notas_control">Detalles para el control <span class="dash-hint">(opcional)</span></label>
                        <textarea id="notas_control" name="notas_control" rows="2" placeholder="Ej: Revisar cicatrización, retirar puntos, control de ortodoncia..." <?= $puede_editar ? '' : 'disabled' ?>><?= htmlspecialchars($form['notas_control']) ?></textarea>
                    </div>
                <?php endif; ?>

                <div class="form-actions">
                    <a href="control_citas.php" class="btn-cancel">Volver</a>
                    <?php if ($puede_editar): ?>
                        <button type="submit" class="btn-primary btn-guardar">
                            <?= $entrada_existente ? 'Actualizar Expediente' : 'Guardar y Completar Cita' ?>
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <!-- HISTORIAL CLÍNICO PREVIO DEL PACIENTE -->
    <section class="wrap" style="padding-bottom: 80px;">
        <div class="sec-head">
            <span class="eyebrow">Contexto</span>
            <h2>Historial Clínico Previo</h2>
            <p>Entradas anteriores del expediente de <?= htmlspecialchars($cita['paciente_nombre'] . ' ' . $cita['paciente_apellido']) ?>.</p>
        </div>

        <?php if (count($historial_previo) === 0): ?>
            <div class="card" style="padding: 24px;">
                <p class="muted-text" style="margin: 0;">Este paciente no tiene entradas previas en su expediente.</p>
            </div>
        <?php else: ?>
            <div style="display:grid; gap:16px;">
                <?php foreach ($historial_previo as $entrada): ?>
                    <div class="card" style="padding: 22px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom: 10px;">
                            <strong>Dr(a). <?= htmlspecialchars($entrada['doctor_nombre_completo']) ?></strong>
                            <span style="color: var(--cyan);"><?= date('d/m/Y', strtotime($entrada['fecha_revision'])) ?></span>
                        </div>
                        <p style="margin: 0 0 6px;"><strong>Diagnóstico:</strong> <?= htmlspecialchars($entrada['diagnostico'] ?: 'No registrado') ?></p>
                        <p style="margin: 0 0 6px;"><strong>Tratamiento:</strong> <?= htmlspecialchars($entrada['tratamiento'] ?: 'No registrado') ?></p>
                        <?php if (!empty($entrada['medicamentos'])): ?>
                            <p style="margin: 0 0 6px; color: var(--muted);"><strong>Medicamentos:</strong> <?= htmlspecialchars($entrada['medicamentos']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($entrada['observaciones'])): ?>
                            <p style="margin: 0 0 6px; color: var(--muted);"><strong>Observaciones:</strong> <?= htmlspecialchars($entrada['observaciones']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($entrada['proxima_revision'])): ?>
                            <p style="margin: 0; color: var(--muted);"><strong>Control sugerido:</strong> <?= date('d/m/Y', strtotime($entrada['proxima_revision'])) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- EXÁMENES Y RADIOGRAFÍAS DEL PACIENTE -->
    <section class="wrap" style="padding-bottom: 80px;">
        <div class="sec-head" style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:16px;">
            <div>
                <span class="eyebrow">Exámenes</span>
                <h2>Radiografías y Exámenes</h2>
                <p>Solicitudes de examen para <?= htmlspecialchars($cita['paciente_nombre'] . ' ' . $cita['paciente_apellido']) ?>.</p>
            </div>
            <a href="examenes.php?id_paciente=<?= (int) $cita['id_paciente'] ?>&id_cita=<?= $id_cita ?>" class="btn-primary">
                Solicitar examen
            </a>
        </div>

        <?php if (count($examenes_paciente) === 0): ?>
            <div class="card" style="padding: 24px;">
                <p class="muted-text" style="margin: 0;">Este paciente no tiene exámenes solicitados todavía.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Tipo de Examen</th>
                            <th>Fecha Solicitud</th>
                            <th>Solicitado por</th>
                            <th>Estado</th>
                            <th>Resultado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($examenes_paciente as $ex): ?>
                            <tr>
                                <td><?= htmlspecialchars($ex['tipo_examen']) ?></td>
                                <td><?= date('d/m/Y', strtotime($ex['fecha_solicitud'])) ?></td>
                                <td>Dr(a). <?= htmlspecialchars($ex['doctor_solicita']) ?></td>
                                <td>
                                    <span class="badge badge-<?= $mapa_badge_examen[$ex['estado']] ?>">
                                        <?= ucfirst($ex['estado']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($ex['estado'] === 'realizado'): ?>
                                        <a href="examenes.php?accion=registrar&id=<?= $ex['id_examen'] ?>" class="btn-action btn-edit">Ver / Editar</a>
                                    <?php else: ?>
                                        <span class="muted-text">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

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
