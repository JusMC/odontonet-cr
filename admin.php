<?php
// admin.php — Panel de control del administrador.

// Guard: solo el administrador (id_rol = 4) puede acceder.
// Si no hay sesión activa o el rol no corresponde, se redirige al login.
session_start();

require_once './assets/includes/auth_check.php';

if (!isset($_SESSION['id_rol']) || (int) $_SESSION['id_rol'] !== 4) {
    header('Location: login.php');
    exit;
}

// Nombre del administrador para el saludo en el hero.
$nombre_admin = $_SESSION['nombre'] ?? 'Administrador';

// ---------------------------------------------------------------------
// ESTADÍSTICAS RÁPIDAS (KPIs)
// ---------------------------------------------------------------------
$stat_usuarios_activos = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE estado = 1")->fetchColumn(); // Cuántos usuarios activos hay en total.
$stat_nuevos_hoy       = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE DATE(fecha_creacion) = CURDATE()")->fetchColumn(); // Cuántos se registraron hoy.
$stat_citas_hoy        = (int) $pdo->query("SELECT COUNT(*) FROM citas WHERE DATE(fecha_hora) = CURDATE()")->fetchColumn(); // Cuántas citas hay agendadas para hoy.

// Ingresos del día = ventas pagadas de contado hoy (sin pago parcial de por
// medio) + TODOS los abonos cobrados hoy (sin importar si la venta se creó
// hoy o antes, ni si ya quedó saldada o sigue pendiente). Se separan para no
// contar dos veces el dinero de una venta que se pagó completa mediante abonos.
$stat_ingresos_directo = (float) $pdo->query("
    SELECT COALESCE(SUM(v.total), 0)
    FROM ventas v
    WHERE v.estado = 'pagada'
      AND DATE(v.fecha_venta) = CURDATE()
      AND NOT EXISTS (SELECT 1 FROM pagos_pendientes pp WHERE pp.id_venta = v.id_venta)
")->fetchColumn();
$stat_ingresos_abonos = (float) $pdo->query("
    SELECT COALESCE(SUM(monto), 0) FROM abonos WHERE DATE(fecha_pago) = CURDATE()
")->fetchColumn();
$stat_ingresos_hoy = $stat_ingresos_directo + $stat_ingresos_abonos; // Suma de ambos: lo que entró hoy en total.

// ---------------------------------------------------------------------
// GRÁFICO: citas registradas en los últimos 7 días (incluye hoy)
// ---------------------------------------------------------------------
// Cuántas citas hubo cada día, agrupadas por fecha (solo los últimos 7 días).
$citas_por_dia = [];
$stmt_dias = $pdo->query("
    SELECT DATE(fecha_hora) AS dia, COUNT(*) AS total
    FROM citas
    WHERE fecha_hora >= (CURDATE() - INTERVAL 6 DAY)
    GROUP BY DATE(fecha_hora)
");
foreach ($stmt_dias->fetchAll() as $fila) {
    $citas_por_dia[$fila['dia']] = (int) $fila['total'];
}

// Armamos los 7 días en orden (de hace 6 días a hoy), con 0 en los días que no tuvieron citas.
$grafico_citas_7dias = [];
for ($i = 6; $i >= 0; $i--) {
    $dia = date('Y-m-d', strtotime("-$i days"));
    $grafico_citas_7dias[] = [
        'etiqueta' => date('d/m', strtotime($dia)),
        'total'    => $citas_por_dia[$dia] ?? 0,
    ];
}
// El día con más citas define el 100% de altura de las barras del gráfico (mínimo 1, para no dividir entre 0).
$max_citas_dia = max(1, max(array_column($grafico_citas_7dias, 'total')));

// ---------------------------------------------------------------------
// GRÁFICO: distribución de citas por estado
// ---------------------------------------------------------------------
$estados_citas = [
    'pendiente'   => ['Pendiente', '#f1c40f'],
    'en_espera'   => ['En espera', '#3498db'],
    'en_atencion' => ['En atención', '#9b59b6'],
    'completada'  => ['Completada', '#2ecc71'],
    'cancelada'   => ['Cancelada', '#e74c3c'],
    'vencida'     => ['Vencida', '#8a93a6'],
];

// Arrancamos todos los estados en 0, y los vamos llenando con los datos reales de la base de datos.
$citas_por_estado = array_fill_keys(array_keys($estados_citas), 0);
$stmt_estado = $pdo->query("SELECT estado, COUNT(*) AS total FROM citas GROUP BY estado");
foreach ($stmt_estado->fetchAll() as $fila) {
    $citas_por_estado[$fila['estado']] = (int) $fila['total'];
}
$total_citas_general = max(1, array_sum($citas_por_estado)); // Total de citas (mínimo 1, para calcular porcentajes sin dividir entre 0).

// ---------------------------------------------------------------------
// REPORTE: productos con stock igual o por debajo del mínimo
// ---------------------------------------------------------------------
// Los 5 productos más urgentes: activos, con stock igual o menor al mínimo permitido, del más crítico al menos.
$productos_bajo_stock = $pdo->query("
    SELECT nombre, stock, stock_minimo
    FROM productos
    WHERE activo = 1 AND stock <= stock_minimo
    ORDER BY (stock - stock_minimo) ASC
    LIMIT 5
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Panel Administrador - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <!-- Enlaces a tus hojas de estilo -->
    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/home.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/admin_hero.css">
    <link rel="stylesheet" href="assets/css/pages/admin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>

<body>

    <!-- EFECTOS DE FONDO GLOBALES -->
    <div class="grid-overlay"></div>
    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>
    <div class="glow glow-c"></div>

    <!-- NAVBAR -->
    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <!-- ENCABEZADO COMPACTO -->
    <section class="wrap admin-wide">
        <div class="admin-hero">
            <span class="eyebrow">Módulo Central</span>
            <h1>Panel de Control <span class="grad">OdontoNet</span></h1>
            <p>Bienvenido, <?= htmlspecialchars($nombre_admin) ?>. Este es el resumen general de la actividad de la clínica hoy.</p>
        </div>
    </section>

    <!-- ESTADÍSTICAS RÁPIDAS -->
    <section class="wrap admin-wide" style="padding-top:0;">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon stat-icon-cyan"><i class="bi bi-people-fill"></i></div>
                <div>
                    <span class="stat-value"><?= $stat_usuarios_activos ?></span>
                    <span class="stat-label">Usuarios activos</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon stat-icon-lime"><i class="bi bi-person-plus-fill"></i></div>
                <div>
                    <span class="stat-value"><?= $stat_nuevos_hoy ?></span>
                    <span class="stat-label">Nuevos registros hoy</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon stat-icon-violet"><i class="bi bi-calendar-check-fill"></i></div>
                <div>
                    <span class="stat-value"><?= $stat_citas_hoy ?></span>
                    <span class="stat-label">Citas agendadas hoy</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon stat-icon-pink"><i class="bi bi-cash-coin"></i></div>
                <div>
                    <span class="stat-value">₡<?= number_format($stat_ingresos_hoy, 0, ',', '.') ?></span>
                    <span class="stat-label">Ingresos del día</span>
                </div>
            </div>
        </div>
    </section>

    <!-- GRÁFICOS Y REPORTES -->
    <section class="wrap admin-wide" style="padding-top:0;">
        <div class="sec-head">
            <span class="eyebrow">Reportes</span>
            <h2>Actividad y Estadísticas</h2>
            <p>Panorama de la operación clínica basado en los datos registrados en el sistema.</p>
        </div>

        <div class="reports-grid">
            <!-- Citas de los últimos 7 días -->
            <div class="card card--padded chart-card">
                <h3>Citas de los últimos 7 días</h3>
                <div class="bar-chart-7d">
                    <?php foreach ($grafico_citas_7dias as $dia): ?>
                        <div class="bar-col">
                            <span class="bar-value"><?= $dia['total'] ?></span>
                            <div class="bar" style="height: <?= max(4, round(($dia['total'] / $max_citas_dia) * 100)) ?>%;"></div>
                            <span class="bar-label"><?= $dia['etiqueta'] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Citas por estado -->
            <div class="card card--padded chart-card">
                <h3>Citas por estado</h3>
                <div class="estado-bars">
                    <?php foreach ($estados_citas as $clave => [$etiqueta, $color]): ?>
                        <?php $cantidad = $citas_por_estado[$clave]; ?>
                        <div>
                            <div class="estado-bar-top">
                                <span><?= $etiqueta ?></span>
                                <span><?= $cantidad ?></span>
                            </div>
                            <div class="estado-bar-track">
                                <div class="estado-bar-fill" style="width: <?= round(($cantidad / $total_citas_general) * 100) ?>%; background: <?= $color ?>;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Productos con bajo stock -->
            <div class="card card--padded chart-card">
                <h3>Productos con bajo stock</h3>
                <?php if (count($productos_bajo_stock) > 0): ?>
                    <div class="stock-alert-list">
                        <?php foreach ($productos_bajo_stock as $prod): ?>
                            <div class="stock-alert-item">
                                <span class="stock-nombre"><?= htmlspecialchars($prod['nombre']) ?></span>
                                <span class="stock-count"><?= (int) $prod['stock'] ?> / <?= (int) $prod['stock_minimo'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="empty-state-mini">Todos los productos tienen stock suficiente.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ACCESOS DIRECTOS AGRUPADOS POR ROL -->
    <section class="wrap admin-wide" style="padding-top:0;">
        <div class="sec-head">
            <span class="eyebrow">Administración</span>
            <h2>Accesos Directos</h2>
            <p>Accesos rápidos a los módulos de administrador, doctor y recepción.</p>
        </div>

        <div class="quick-links-group">
            <h3 class="quick-links-title"><i class="bi bi-shield-lock-fill"></i> Administrador</h3>
            <div class="quick-links-grid">
                <a href="usuarios.php" class="quick-link-tile">
                    <i class="bi bi-people-fill"></i>
                    <span>Usuarios</span>
                </a>
                <a href="ventas.php" class="quick-link-tile">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>Ventas Realizadas</span>
                </a>
                <a href="permisos.php" class="quick-link-tile">
                    <i class="bi bi-shield-lock-fill"></i>
                    <span>Permisos</span>
                </a>
                <a href="bitacora.php" class="quick-link-tile">
                    <i class="bi bi-journal-text"></i>
                    <span>Bitácora</span>
                </a>
            </div>
        </div>

        <div class="quick-links-group">
            <h3 class="quick-links-title"><i class="bi bi-heart-pulse-fill"></i> Doctor</h3>
            <div class="quick-links-grid">
                <a href="control_citas.php" class="quick-link-tile">
                    <i class="bi bi-calendar-check-fill"></i>
                    <span>Control de Citas</span>
                </a>
                <a href="examenes.php" class="quick-link-tile">
                    <i class="bi bi-clipboard2-pulse-fill"></i>
                    <span>Exámenes</span>
                </a>
            </div>
        </div>

        <div class="quick-links-group">
            <h3 class="quick-links-title"><i class="bi bi-receipt"></i> Recepción</h3>
            <div class="quick-links-grid">
                <a href="inventario.php" class="quick-link-tile">
                    <i class="bi bi-box-seam-fill"></i>
                    <span>Inventario</span>
                </a>
                <a href="tratamientos.php" class="quick-link-tile">
                    <i class="bi bi-heart-pulse-fill"></i>
                    <span>Tratamientos</span>
                </a>
                <a href="odontologos.php" class="quick-link-tile">
                    <i class="bi bi-person-badge-fill"></i>
                    <span>Odontólogos</span>
                </a>
                <a href="promociones.php" class="quick-link-tile">
                    <i class="bi bi-tags-fill"></i>
                    <span>Promociones &amp; Paquetes</span>
                </a>
                <a href="facturar_servicios.php" class="quick-link-tile">
                    <i class="bi bi-receipt"></i>
                    <span>Facturar</span>
                </a>
                <a href="pagos_pendientes.php" class="quick-link-tile">
                    <i class="bi bi-hourglass-split"></i>
                    <span>Pagos Pendientes</span>
                </a>
            </div>
        </div>
    </section>

    <!-- PIE DE PÁGINA -->
    <?php require_once 'assets/includes/partials/footer.php'; ?>

</body>
</html>
