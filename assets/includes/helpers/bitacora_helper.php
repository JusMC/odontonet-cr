<?php
/**
 * bitacora_helper.php — Función auxiliar para registrar acciones en la tabla bitacora.
 *
 * Incluir con require_once en cualquier página que necesite dejar constancia
 * de una acción (creación, modificación o inactivación de registros).
 * Requiere que $pdo ya esté disponible (incluir config.php antes).
 *
 * Uso:
 *   require_once './assets/includes/helpers/bitacora_helper.php';
 *   registrar_bitacora($pdo, 'INSERT', 'Se registró el producto #12 (Anestesia Cartuca 2%).');
 */

function registrar_bitacora(PDO $pdo, string $accion, string $descripcion, ?int $id_usuario = null): void
{
    // Si no nos pasaron un id_usuario, usamos el de la sesión actual (o null si nadie ha iniciado sesión).
    $id_usuario = $id_usuario ?? (isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null);
    // Guardamos también desde qué computadora (IP) se hizo la acción.
    $ip_origen  = $_SERVER['REMOTE_ADDR'] ?? null;

    try {
        // Preparamos la consulta para insertar la nueva fila en la bitácora.
        $stmt = $pdo->prepare(
            'INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen) VALUES (:id_usuario, :accion, :descripcion, :ip_origen)'
        );
        // La ejecutamos con los datos recibidos.
        $stmt->execute([
            ':id_usuario'  => $id_usuario,
            ':accion'      => $accion,
            ':descripcion' => $descripcion,
            ':ip_origen'   => $ip_origen,
        ]);
    } catch (PDOException $e) {
        // No interrumpimos el flujo principal de la página si falla el registro de bitácora.
    }
}

/**
 * registrar_alerta_bitacora() — Registra una alerta del sistema (accion = 'ALERTA')
 * como máximo una vez por día por cada $marcador (ej: stock bajo, productos por vencer).
 *
 * El proyecto no cuenta con un cron/scheduler, así que esta función evita llenar
 * la bitácora con la misma alerta cada vez que un administrador o recepcionista
 * visita una página que recalcula la condición (ej: inventario.php).
 *
 * Uso:
 *   registrar_alerta_bitacora($pdo, '[POR_VENCER]', "Hay 3 producto(s) a punto de vencer.");
 */
function registrar_alerta_bitacora(PDO $pdo, string $marcador, string $descripcion): void
{
    try {
        // Contamos cuántas alertas con este mismo marcador ya se registraron hoy.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM bitacora WHERE accion = 'ALERTA' AND descripcion LIKE :marcador AND DATE(fecha_hora) = CURDATE()"
        );
        $stmt->execute([':marcador' => $marcador . '%']);

        // Si el resultado es 0 (todavía no se avisó hoy), recién ahí registramos la alerta.
        if ((int) $stmt->fetchColumn() === 0) {
            registrar_bitacora($pdo, 'ALERTA', $marcador . ' ' . $descripcion);
        }
    } catch (PDOException $e) {
        // No interrumpimos el flujo principal de la página si falla el registro de bitácora.
    }
}
