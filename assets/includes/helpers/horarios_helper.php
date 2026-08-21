<?php
/**
 * horarios_helper.php — Validación y sugerencia de horarios para un doctor.
 *
 * Usado por citas.php (agendamiento del paciente), control_citas.php
 * (agendamiento por recepción) y expediente.php (cita de control).
 *
 * Requiere que $pdo ya esté disponible.
 */

/**
 * ¿El doctor tiene algún bloque de horario configurado en doctor_horarios?
 * Un doctor sin filas ahí no tiene restricción de horario (compatibilidad
 * con doctores existentes que aún no tienen horario estructurado cargado,
 * solo el texto libre en doctores.horario_atencion).
 */
function doctor_tiene_horario_configurado(PDO $pdo, int $id_doctor): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM doctor_horarios WHERE id_doctor = :id_doctor LIMIT 1');
    $stmt->execute([':id_doctor' => $id_doctor]);
    return (bool) $stmt->fetchColumn(); // true si tiene al menos un bloque configurado.
}

/**
 * Bloques de horario del doctor para un día de la semana dado (0=domingo..6=sábado).
 * Devuelve filas con hora_inicio/hora_fin (strings 'HH:MM:SS').
 */
function obtener_bloques_horario_dia(PDO $pdo, int $id_doctor, int $dia_semana): array
{
    $stmt = $pdo->prepare('SELECT hora_inicio, hora_fin FROM doctor_horarios WHERE id_doctor = :id_doctor AND dia_semana = :dia ORDER BY hora_inicio ASC');
    $stmt->execute([':id_doctor' => $id_doctor, ':dia' => $dia_semana]);
    return $stmt->fetchAll();
}

/**
 * Valida que el intervalo [fecha_hora, fecha_hora + duracion) quede
 * completamente contenido dentro de algún bloque de horario laboral del
 * doctor ese día. Si el doctor no tiene horario configurado (ver
 * doctor_tiene_horario_configurado), no se restringe: devuelve true.
 */
function validar_horario_doctor(PDO $pdo, int $id_doctor, DateTime $fecha_hora, int $duracion): bool
{
    // Si el doctor no tiene horario configurado, no hay restricción: cualquier hora vale.
    if (!doctor_tiene_horario_configurado($pdo, $id_doctor)) {
        return true;
    }

    // Buscamos los bloques laborales del doctor para ese día de la semana.
    $dia_semana = (int) $fecha_hora->format('w');
    $bloques = obtener_bloques_horario_dia($pdo, $id_doctor, $dia_semana);
    if (empty($bloques)) {
        // No trabaja ese día: no se puede agendar.
        return false;
    }

    // Calculamos la hora de inicio y de fin de la cita solicitada.
    $inicio_cita = $fecha_hora->format('H:i:s');
    $fin_cita_obj = clone $fecha_hora;
    $fin_cita_obj->modify("+$duracion minutes");
    $fin_cita = $fin_cita_obj->format('H:i:s');

    // La cita no puede cruzar la medianoche dentro de un mismo bloque de horario.
    if ($fin_cita_obj->format('Y-m-d') !== $fecha_hora->format('Y-m-d')) {
        return false;
    }

    // La cita es válida si cabe completa dentro de alguno de los bloques laborales del día.
    foreach ($bloques as $bloque) {
        if ($inicio_cita >= $bloque['hora_inicio'] && $fin_cita <= $bloque['hora_fin']) {
            return true;
        }
    }

    return false; // No encajó en ningún bloque.
}

// Busca hasta 3 horarios libres para un doctor, empezando desde la fecha/hora
// que el usuario intentó y sin encontrar espacio (por eso se llama después de
// que sp_registrar_cita rechaza el horario pedido).
function obtener_horarios_disponibles(PDO $pdo, int $id_doctor, int $duracion, DateTime $fecha_inicio_obj): array
{
    $sugerencias = [];
    $horario_configurado = doctor_tiene_horario_configurado($pdo, $id_doctor);
    $hora_inicio_laboral = 8;  // 8 AM — usado solo si el doctor no tiene horario estructurado
    $hora_fin_laboral = 18;    // 6 PM

    // Buscar en los próximos 14 días
    $fecha_actual = clone $fecha_inicio_obj;
    $fecha_fin = clone $fecha_inicio_obj;
    $fecha_fin->modify('+14 days');

    $es_primer_dia = true;

    // Recorremos día por día hasta encontrar 3 sugerencias o agotar las dos semanas.
    while ($fecha_actual <= $fecha_fin && count($sugerencias) < 3) {
        $fecha_str = $fecha_actual->format('Y-m-d');

        // Si el doctor tiene horario estructurado, cada día se limita a la
        // unión de sus bloques laborales; un día sin bloques se salta entero.
        $bloques_dia = [];
        if ($horario_configurado) {
            $bloques_dia = obtener_bloques_horario_dia($pdo, $id_doctor, (int) $fecha_actual->format('w'));
            if (empty($bloques_dia)) {
                // No trabaja este día: pasamos directo al siguiente.
                $fecha_actual->modify('+1 day');
                $es_primer_dia = false;
                continue;
            }
        } else {
            // Sin horario estructurado, usamos el horario genérico de 8am a 6pm.
            $bloques_dia = [['hora_inicio' => sprintf('%02d:00:00', $hora_inicio_laboral), 'hora_fin' => sprintf('%02d:00:00', $hora_fin_laboral)]];
        }

        // Obtener citas del doctor en este día
        $stmt_citas_dia = $pdo->prepare('
            SELECT fecha_hora, duracion_minutos
            FROM citas
            WHERE id_doctor = :id_doctor
              AND DATE(fecha_hora) = :fecha
              AND estado NOT IN ("cancelada", "vencida")
            ORDER BY fecha_hora ASC
        ');
        $stmt_citas_dia->execute([':id_doctor' => $id_doctor, ':fecha' => $fecha_str]);
        $citas_dia = $stmt_citas_dia->fetchAll();

        // Determinar hora de inicio/fin de búsqueda a partir del primer y
        // último bloque laboral del día (si hay varios bloques —turnos
        // partidos—, cada uno se recorre por separado más abajo).
        $primer_bloque = $bloques_dia[0];
        $ultimo_bloque = $bloques_dia[count($bloques_dia) - 1];

        // En el primer día, comenzar desde la hora del intento fallido (si
        // cae dentro de algún bloque); en otros días, desde el inicio del
        // primer bloque laboral.
        if ($es_primer_dia && $fecha_str === $fecha_inicio_obj->format('Y-m-d')) {
            $inicio_dia = clone $fecha_inicio_obj;
            $es_primer_dia = false;
        } else {
            $inicio_dia = new DateTime($fecha_str . ' ' . $primer_bloque['hora_inicio']);
        }

        $fin_dia = new DateTime($fecha_str . ' ' . $ultimo_bloque['hora_fin']);

        // Si es hoy, asegurar que la búsqueda sea desde ahora o después
        $ahora = new DateTime();
        if ($fecha_str === $ahora->format('Y-m-d')) {
            $minutos_actual = (int)$ahora->format('i');
            $minutos_redondeados = ceil($minutos_actual / 15) * 15;
            $hora_minima = clone $ahora;
            $hora_minima->setTime((int)$ahora->format('H'), (int)$minutos_redondeados);

            if ($inicio_dia < $hora_minima) {
                $inicio_dia = clone $hora_minima;
            }
        }

        // Construir bloques ocupados
        // Con las citas que ya existen ese día armamos una lista de "horarios ocupados" (inicio y fin de cada una).
        $bloques_ocupados = [];
        foreach ($citas_dia as $cita) {
            $inicio_cita = new DateTime($cita['fecha_hora']);
            $fin_cita = clone $inicio_cita;
            $fin_cita->modify('+' . $cita['duracion_minutos'] . ' minutes');
            $bloques_ocupados[] = ['inicio' => $inicio_cita, 'fin' => $fin_cita];
        }

        // Buscar espacios libres
        // Vamos avanzando de 15 en 15 minutos dentro del horario laboral, probando cada hueco.
        $tiempo_actual = clone $inicio_dia;
        while ($tiempo_actual < $fin_dia) {
            $tiempo_fin = clone $tiempo_actual;
            $tiempo_fin->modify('+' . $duracion . ' minutes');

            if ($tiempo_fin > $fin_dia) break; // Ya no cabe una cita completa antes de que termine el horario.

            // Con horario estructurado y turnos partidos (más de un bloque
            // ese día), el candidato debe caer completo dentro de ALGÚN
            // bloque -evita sugerir horas dentro del hueco entre bloques-.
            if ($horario_configurado && count($bloques_dia) > 1) {
                $dentro_de_algun_bloque = false;
                foreach ($bloques_dia as $bloque_chk) {
                    $bloque_inicio = new DateTime($fecha_str . ' ' . $bloque_chk['hora_inicio']);
                    $bloque_fin    = new DateTime($fecha_str . ' ' . $bloque_chk['hora_fin']);
                    if ($tiempo_actual >= $bloque_inicio && $tiempo_fin <= $bloque_fin) {
                        $dentro_de_algun_bloque = true;
                        break;
                    }
                }
                if (!$dentro_de_algun_bloque) {
                    // Cae en el hueco entre dos turnos: no es un horario válido, seguimos probando.
                    $tiempo_actual->modify('+15 minutes');
                    continue;
                }
            }

            // Verificar si hay traslape con alguna cita
            $hay_conflicto = false;
            foreach ($bloques_ocupados as $bloque) {
                if ($tiempo_fin > $bloque['inicio'] && $tiempo_actual < $bloque['fin']) {
                    $hay_conflicto = true;
                    break;
                }
            }

            if (!$hay_conflicto) {
                // Encontramos un espacio libre: lo agregamos a las sugerencias.
                $sugerencias[] = $tiempo_actual->format('Y-m-d H:i');
                if (count($sugerencias) >= 3) break; // Con 3 sugerencias ya es suficiente.
            }

            // Avanzar 15 minutos
            $tiempo_actual->modify('+15 minutes');
        }

        // Pasamos al siguiente día a revisar.
        $fecha_actual->modify('+1 day');
    }

    return $sugerencias;
}
