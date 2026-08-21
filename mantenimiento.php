<?php
// mantenimiento.php — Placeholder para módulos en construcción (doctor / recepcionista).
session_start();

// Guard básico: debe haber sesión activa para llegar aquí.
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

// Ya no hay módulos en construcción que necesiten este placeholder, así que
// se redirige antes de cargar cualquiera de sus elementos. Se deja el resto
// del archivo tal cual (sin usarse) por si se necesita reactivar más adelante.
header('Location: inicio.php');
exit;

$nombre = $_SESSION['nombre'] ?? 'Usuario';
$id_rol = (int)($_SESSION['id_rol'] ?? 0);

// Navbar según rol
$es_admin = $id_rol === 4;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OdontoNet — En construcción</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
</head>
<body>

    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>
    <div class="glow glow-c"></div>
    <div class="grid-overlay"></div>

    <!-- NAVBAR -->
    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <main class="auth-page">
        <div class="card" style="max-width:480px; padding:48px 40px; text-align:center;">

            <div style="width:64px; height:64px; border-radius:16px; background:var(--grad-1);
                        display:flex; align-items:center; justify-content:center;
                        margin:0 auto 24px; box-shadow:0 0 26px rgba(0,240,255,0.35);">
                <svg width="30" height="30" viewBox="0 0 24 24" fill="none"
                     stroke="#050608" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77
                             a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91
                             a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                </svg>
            </div>

            <h1 style="font-size:1.5rem; margin-bottom:10px;">¡Hola, <?= htmlspecialchars($nombre) ?>!</h1>

            <p style="color:var(--muted); line-height:1.6; margin-bottom:8px;">
                Este módulo está en construcción.
            </p>
            <p style="color:var(--muted); line-height:1.6; margin-bottom:32px;">
                Pronto podrás gestionar el inventario, insumos y materiales clínicos desde aquí.
            </p>

            <div style="display:flex; flex-wrap:wrap; justify-content:center; gap:12px;">
                <?php if ($es_admin): ?>
                    <a href="admin.php" class="btn-primary">Volver al panel</a>
                    <a href="control_citas.php" class="btn-ghost">Ver citas</a>
                <?php else: ?>
                    <a href="control_citas.php" class="btn-primary">Ver citas</a>
                    <a href="#" onclick="history.back(); return false;" class="btn-ghost">Volver</a>
                <?php endif; ?>
            </div>

        </div>
    </main>

    <?php require_once 'assets/includes/partials/footer.php'; ?>
</body>
</html>