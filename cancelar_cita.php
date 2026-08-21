<?php
// cancelar_cita.php — Cancela una cita del paciente autenticado.

session_start();
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/bitacora_helper.php';

// Solo pacientes pueden cancelar sus propias citas.
if (!isset($_SESSION['paciente_id'])) {
    header('Location: login.php');
    exit;
}

$paciente_id = (int)$_SESSION['paciente_id'];
$id_cita     = isset($_GET['id']) ? (int)$_GET['id'] : 0; // El id de la cita a cancelar viene en la URL.

// Si no vino un id válido en la URL, no seguimos.
if ($id_cita <= 0) {
    header('Location: citas.php?error=invalida');
    exit;
}

try {
    // Verificar que la cita pertenece al paciente y está en un estado cancelable.
    $stmt = $pdo->prepare('
        SELECT id_cita, estado, fecha_hora FROM citas
        WHERE id_cita = :id_cita
        AND id_paciente = :id_paciente
        LIMIT 1
    ');
    $stmt->execute([
        ':id_cita'     => $id_cita,
        ':id_paciente' => $paciente_id,
    ]);
    $cita = $stmt->fetch();

    if (!$cita) {
        // La cita no existe o no pertenece a este paciente.
        header('Location: citas.php?error=no_encontrada');
        exit;
    }

    // Solo se pueden cancelar citas que todavía no pasaron por atención (no si ya está en curso, completada, etc.).
    if (!in_array($cita['estado'], ['pendiente', 'en_espera'])) {
        header('Location: citas.php?error=no_cancelable');
        exit;
    }

    // Tampoco se puede cancelar una cita cuya fecha y hora ya pasaron.
    if (strtotime($cita['fecha_hora']) <= time()) {
        header('Location: citas.php?error=no_cancelable');
        exit;
    }

    // Soft-cancel: solo cambia el estado, nunca elimina la fila.
    $stmt_cancel = $pdo->prepare('
        UPDATE citas SET estado = :estado
        WHERE id_cita = :id_cita AND id_paciente = :id_paciente
    ');
    $stmt_cancel->execute([
        ':estado'      => 'cancelada',
        ':id_cita'     => $id_cita,
        ':id_paciente' => $paciente_id,
    ]);

    header('Location: citas.php?msg=cancelada');
    exit;

} catch (PDOException $e) {
    registrar_bitacora($pdo, 'ERROR', "Falló la cancelación de la cita #$id_cita (paciente #$paciente_id): " . $e->getMessage());
    header('Location: citas.php?error=db');
    exit;
}