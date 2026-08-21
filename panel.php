<?php

session_start();
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';

// Sin sesión, no hay panel que mostrar.
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php?redirect=panel.php');
    exit;
}
// Si no es paciente, lo mandamos a su panel correspondiente (esta pantalla es solo para pacientes).
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

// Cuenta cuántas citas tiene el paciente en cada estado.
$stmt_counts = $pdo->prepare("SELECT
        SUM(estado = 'pendiente') AS pendientes,
        SUM(estado = 'en_espera') AS en_espera,
        SUM(estado = 'en_atencion') AS en_atencion,
        SUM(estado = 'completada') AS completadas
    FROM citas
    WHERE id_paciente = :id_paciente");
$stmt_counts->execute(['id_paciente' => $paciente_id]);
$counts = $stmt_counts->fetch();

// Las próximas 5 citas futuras del paciente.
$stmt_upcoming = $pdo->prepare('SELECT c.fecha_hora, c.motivo, c.estado, u.nombre AS doctor_nombre, u.apellido AS doctor_apellido
    FROM citas c
    INNER JOIN doctores d ON d.id_doctor = c.id_doctor
    INNER JOIN usuarios u ON u.id_usuario = d.id_usuario
    WHERE c.id_paciente = :id_paciente
      AND c.fecha_hora >= NOW()
    ORDER BY c.fecha_hora ASC
    LIMIT 5');
$stmt_upcoming->execute(['id_paciente' => $paciente_id]);
$proximas_citas = $stmt_upcoming->fetchAll();

// La entrada más reciente de su historial médico.
$stmt_last_history = $pdo->prepare('SELECT hm.fecha_revision, hm.diagnostico, hm.tratamiento, hm.proxima_revision, u.nombre AS doctor_nombre, u.apellido AS doctor_apellido
    FROM historial_medico hm
    INNER JOIN doctores d ON d.id_doctor = hm.id_doctor
    INNER JOIN usuarios u ON u.id_usuario = d.id_usuario
    WHERE hm.id_paciente = :id_paciente
    ORDER BY hm.fecha_revision DESC
    LIMIT 1');
$stmt_last_history->execute(['id_paciente' => $paciente_id]);
$ultimo_historial = $stmt_last_history->fetch();

// Da formato legible (día/mes/año hora:minuto) a una fecha de la base de datos.
function format_date_time($value) {
    if (!$value) {
        return 'Sin fecha';
    }
    $date = new DateTime($value);
    return $date->format('d/m/Y H:i');
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OdontoNet — Tu panel</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <!-------------- CSS --------------> 
    <link rel="preconnect" href="https://fonts.googleapis.com"> <!-- Conexión temprana con los servidores de Google Fonts -->
    <link rel="stylesheet" href="assets/css/shared/variables.css"> <!-- CSS de variables -->
    <link rel="stylesheet" href="assets/css/shared/global.css"> <!-- CSS de estilos globales -->
    <link rel="stylesheet" href="assets/css/shared/layout.css"> <!-- CSS del layout (Navbar & Footer) -->
    <link rel="stylesheet" href="assets/css/shared/componentes.css"> <!-- CSS de los componentes (Botones y elementos) -->
    
</head>

<body>

    <!-- Mismos fondos decorativos que el resto del sitio -->
    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>
    <div class="glow glow-c"></div>
    <div class="grid-overlay"></div>

    <!-- Reutiliza .auth-page (global.css) para centrar la tarjeta en
         medio de la pantalla, igual que en login.php/registro.php. -->
    <main class="auth-page">
        <div class="card" style="max-width: 480px; padding: 48px 40px; text-align: center;">

            <div style="width:64px; height:64px; border-radius:16px; background:var(--grad-1); display:flex; align-items:center; justify-content:center; margin:0 auto 24px; box-shadow:0 0 26px rgba(0,240,255,0.35);">
                <!-- Ícono de "en construcción" simple, hecho con SVG en vez
                     de una imagen aparte, para no depender de otro archivo. -->
                <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#050608" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                </svg>
            </div>

            <h1 style="font-size:1.5rem; margin-bottom:10px;">¡Hola, <?= htmlspecialchars($nombre) ?>!</h1>

            <p style="color:var(--muted); line-height:1.6; margin-bottom:8px;">
                Tu cuenta ya está activa y tu sesión inició correctamente.
            </p>
            <p style="color:var(--muted); line-height:1.6; margin-bottom:32px;">
                Estamos construyendo tu panel de citas, historial y compras.
                Muy pronto vas a poder gestionar todo desde aquí.
            </p>

            <div style="display:flex; flex-wrap:wrap; justify-content:center; gap:12px;">
                <a href="citas.php" class="btn-primary" style="display:inline-block;">Agendar cita</a>
                <a href="index.php" class="btn-ghost" style="display:inline-block;">Volver al inicio</a>
            </div>

        </div>
    </main>

</body>
</html>