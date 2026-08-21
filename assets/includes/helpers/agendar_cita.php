<?php

// Arranca (o retoma) la sesión del navegador, para poder leer $_SESSION.
session_start();

// Trae la conexión a la base de datos ($pdo).
require_once __DIR__ . '/../config/config.php';

// Verifica que haya una sesión válida y que el usuario siga activo.
require_once __DIR__ . '/../auth_check.php';
// Trae la función que calcula horarios libres cuando hay un traslape.
require_once __DIR__ . '/horarios_helper.php';
// Trae la función para dejar constancia en la bitácora.
require_once __DIR__ . '/bitacora_helper.php';

// Si no hay ningún usuario en sesión, lo mandamos al login (con redirect de vuelta a citas.php).
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login.php?redirect=citas.php');
    exit;
}
if (!isset($_SESSION['paciente_id'])) {
    // Sesión activa pero no es paciente (admin/doctor/recepcionista):
    // redirigir a su panel correspondiente.
    $destino = match((int)($_SESSION['id_rol'] ?? 0)) {
        4 => '/admin.php',
        2, 3 => '/control_citas.php',
        default => '/login.php',
    };
    header('Location: ' . $destino);
    exit;
}

// Datos básicos del paciente que está agendando, sacados de la sesión.
$usuario_id = (int)($_SESSION['usuario_id'] ?? 0);
$paciente_id = (int)($_SESSION['paciente_id'] ?? 0);
$nombre = $_SESSION['nombre'] ?? 'paciente';

// Variables para mostrar un mensaje de éxito/error en la página y, si aplica, horarios alternativos.
$message = '';
$message_type = '';
$sugerencias_horarios = [];

// Marcar automáticamente como 'vencida' las citas pasadas que siguen pendientes.
// Se ejecuta en cada carga de citas.php sin impacto notable en rendimiento.
try {
    $pdo->exec("
        UPDATE citas
        SET estado = 'vencida'
        WHERE estado IN ('pendiente', 'en_espera')
          AND DATE_ADD(fecha_hora, INTERVAL duracion_minutos MINUTE) < NOW()
          AND id_paciente = " . (int)$paciente_id . "
    ");
} catch (PDOException $e) { /* silencioso */ }

// Si el paciente envió el formulario para agendar una nueva cita...
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Leemos y limpiamos cada dato del formulario.
    $servicio_id = filter_input(INPUT_POST, 'servicio', FILTER_VALIDATE_INT);
    $doctor_id = filter_input(INPUT_POST, 'doctor', FILTER_VALIDATE_INT);
    $fecha = trim($_POST['fecha'] ?? '');
    $hora = trim($_POST['hora'] ?? '');
    $motivo = trim($_POST['motivo'] ?? '');

    // Primero, validaciones básicas de que no falte nada.
    if (!$servicio_id || !$doctor_id || $fecha === '' || $hora === '') {
        $message = 'Completa todos los campos para agendar tu cita.';
        $message_type = 'error';
    } elseif (mb_strlen($motivo) > 255) {
        $message = 'El motivo no puede exceder 255 caracteres.';
        $message_type = 'error';
    } else {
        // Unimos fecha y hora en un solo texto y lo convertimos a un objeto de fecha real.
        $fecha_hora = $fecha . ' ' . $hora;
        $fecha_objeto = DateTime::createFromFormat('Y-m-d H:i', $fecha_hora);

        if (!$fecha_objeto || $fecha_objeto->format('Y-m-d H:i') !== $fecha_hora) {
            // Si no se pudo interpretar como una fecha válida, avisamos.
            $message = 'La fecha y la hora no tienen un formato válido.';
            $message_type = 'error';
        } else {
            // Validar que la fecha y hora estén dentro de los límites permitidos
            $ahora = new DateTime();
            $fecha_min = clone $ahora;
            $fecha_max = clone $ahora;
            $fecha_max->modify('+100 years');

            $fecha_valida = true;

            if ($fecha_objeto < $fecha_min) {
                // No se puede agendar una cita en el pasado.
                $message = 'No se puede agendar citas antes de la hora actual.';
                $message_type = 'error';
                $fecha_valida = false;
            } elseif ($fecha_objeto > $fecha_max) {
                // Tampoco demasiado lejos en el futuro (límite de seguridad, no un caso real).
                $message = 'No se puede agendar citas más allá de 100 años.';
                $message_type = 'error';
                $fecha_valida = false;
            } elseif ($fecha_objeto->format('Y-m-d') === $ahora->format('Y-m-d')) {
                // Si es hoy, validar que la hora sea >= a la actual (redondeada a 15 minutos)
                $minutos_actual = (int)$ahora->format('i');
                $minutos_redondeados = ceil($minutos_actual / 15) * 15;
                $hora_minima = clone $ahora;
                $hora_minima->setTime((int)$ahora->format('H'), $minutos_redondeados);

                if ($fecha_objeto < $hora_minima) {
                    $message = 'No se puede agendar citas antes de la hora actual.';
                    $message_type = 'error';
                    $fecha_valida = false;
                }
            }

            // Si la fecha pasó todas las validaciones anteriores, seguimos con el agendamiento.
            if ($fecha_valida) {
                // Confirmamos que el servicio elegido sigue existiendo y está activo.
                $stmt_servicio = $pdo->prepare('SELECT id_servicio, nombre, duracion_minutos FROM servicios WHERE id_servicio = :id AND activo = 1 LIMIT 1');
            $stmt_servicio->execute(['id' => $servicio_id]);
            $servicio = $stmt_servicio->fetch();

            // Confirmamos que el doctor elegido sigue existiendo y está activo.
            $stmt_doctor = $pdo->prepare('SELECT d.id_doctor, u.nombre, u.apellido FROM doctores d INNER JOIN usuarios u ON u.id_usuario = d.id_usuario WHERE d.id_doctor = :id AND u.id_rol = 2 AND u.estado = 1 LIMIT 1');
            $stmt_doctor->execute(['id' => $doctor_id]);
            $doctor = $stmt_doctor->fetch();

            if (!$servicio || !$doctor) {
                // Si alguno ya no existe (lo desactivaron mientras el paciente llenaba el formulario), avisamos.
                $message = 'El servicio o el doctor seleccionado ya no están disponibles.';
                $message_type = 'error';
            } else {
                // La duración de la cita depende del servicio elegido (60 minutos si no se definió otra cosa).
                $duracion = (int)($servicio['duracion_minutos'] ?? 60);

                // sp_registrar_cita valida traslape y horario laboral del doctor,
                // e inserta la cita — todo en una sola transacción con rollback.
                try {
                    // Llamamos al procedimiento almacenado que hace toda la validación y el guardado en la base de datos.
                    $stmt_call = $pdo->prepare('CALL sp_registrar_cita(:pac, :doc, :usr, :fecha, :dur, :motivo, NULL, NULL, @id_cita)');
                    $stmt_call->execute([
                        ':pac'    => $paciente_id,
                        ':doc'    => $doctor['id_doctor'],
                        ':usr'    => $usuario_id,
                        ':fecha'  => $fecha_hora,
                        ':dur'    => $duracion,
                        ':motivo' => $motivo !== '' ? $motivo : null,
                    ]);
                    $stmt_call->closeCursor();

                    // Si no lanzó ninguna excepción, la cita quedó agendada.
                    $message = 'Tu cita fue agendada correctamente. Pronto recibirás la confirmación.';
                    $message_type = 'success';
                } catch (PDOException $e) {
                    // El procedimiento almacenado usa el código '45000' cuando rechaza la cita a propósito
                    // (por ejemplo, por traslape de horario), a diferencia de un error inesperado de la base de datos.
                    $es_error_del_procedimiento = ($e->errorInfo[0] ?? '') === '45000';
                    $message = $es_error_del_procedimiento
                        ? $e->errorInfo[2] . ' Por favor, elige otra fecha u hora.'
                        : 'No se pudo guardar la cita en este momento. Inténtalo de nuevo.';
                    $message_type = 'error';

                    // Solo registramos en bitácora los errores inesperados, no los rechazos normales del procedimiento.
                    if (!$es_error_del_procedimiento) {
                        registrar_bitacora($pdo, 'ERROR', "Falló el agendamiento de una cita (paciente #$paciente_id, doctor #{$doctor['id_doctor']}): " . $e->getMessage());
                    }

                    // El procedimiento ya rechazó el horario (traslape o fuera de
                    // horario laboral); sugerimos alternativas igual que antes.
                    $sugerencias_horarios = obtener_horarios_disponibles($pdo, $doctor['id_doctor'], $duracion, $fecha_objeto);
                }
            }
            }
        }
    }
}

// Lista de servicios activos, para llenar el <select> del formulario.
$stmt_servicios = $pdo->query('SELECT id_servicio, nombre, descripcion, precio, duracion_minutos FROM servicios WHERE activo = 1 ORDER BY nombre');
$servicios = $stmt_servicios->fetchAll();

// Lista de doctores activos, para llenar el otro <select> del formulario.
$stmt_doctores = $pdo->query('SELECT d.id_doctor, u.nombre, u.apellido, d.especialidad FROM doctores d INNER JOIN usuarios u ON u.id_usuario = d.id_usuario WHERE u.id_rol = 2 AND u.estado = 1 ORDER BY u.nombre, u.apellido');
$doctores = $stmt_doctores->fetchAll();

// Guardamos lo que el paciente había escrito, para que el formulario no se vacíe si hubo un error de validación.
$selected_servicio = $_POST['servicio'] ?? '';
$selected_doctor = $_POST['doctor'] ?? '';
$selected_fecha = $_POST['fecha'] ?? '';
$selected_hora = $_POST['hora'] ?? '';
$selected_motivo = $_POST['motivo'] ?? '';
