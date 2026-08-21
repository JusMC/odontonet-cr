<?php
/*
    permisos.php — Matriz de permisos por rol (solo administradores).

    Vista de solo lectura: muestra, para cada módulo del sistema registrado
    en `permisos`, qué roles tienen acceso según la tabla `rol_permiso`.
    No modifica el control de acceso real de cada página (cada módulo sigue
    validando su propio guard por $_SESSION['id_rol']); esta pantalla
    documenta y visualiza esa matriz directamente desde la base de datos,
    para cumplir con "una tabla de roles que muestre los permisos
    correspondientes en el sistema de base de datos".
*/

session_start();
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';

// Solo el administrador (rol 4) puede ver esta pantalla.
if (!isset($_SESSION['id_rol']) || (int) $_SESSION['id_rol'] !== 4) {
    header('Location: login.php');
    exit;
}

// Traemos los roles activos (columnas de la tabla).
$roles = $pdo->query("SELECT id_rol, nombre FROM roles WHERE activo = 1 ORDER BY id_rol")->fetchAll();

// Traemos todos los módulos/páginas registrados (filas de la tabla).
$permisos = $pdo->query("SELECT id_permiso, modulo, pagina, descripcion FROM permisos ORDER BY id_permiso")->fetchAll();

// Traemos qué combinaciones de rol+permiso existen, y armamos una matriz fácil de consultar:
// $matriz[id_permiso][id_rol] = true si ese rol tiene ese permiso.
$stmt_rp = $pdo->query("SELECT id_rol, id_permiso FROM rol_permiso");
$matriz = [];
foreach ($stmt_rp->fetchAll() as $rp) {
    $matriz[(int) $rp['id_permiso']][(int) $rp['id_rol']] = true;
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permisos por Rol - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/home.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <link rel="stylesheet" href="assets/css/shared/dashboard_forms.css">
    <link rel="stylesheet" href="assets/css/shared/admin_hero.css">
</head>

<body>

    <div class="grid-overlay"></div>
    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>

    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <section class="wrap admin-wide">
        <div class="admin-hero">
            <span class="eyebrow">Módulo Administrador</span>
            <h1>Permisos <span class="grad">por Rol</span></h1>
            <p>Qué módulo del sistema puede usar cada rol, según la tabla <code>rol_permiso</code> de la base de datos.</p>
        </div>
    </section>

    <section class="wrap admin-wide section-tabla" style="padding-top: 0; padding-bottom: 80px;">
        <div class="sec-head form-head">
            <span class="eyebrow">Matriz de acceso</span>
            <h2>Módulos vs. Roles</h2>
            <p>Esta pantalla es de solo lectura: para cambiar el rol de un usuario ve a <a href="usuarios.php" style="color: var(--cyan);">Gestión de Usuarios</a>.</p>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Módulo</th>
                        <th>Página</th>
                        <!-- Una columna por cada rol activo -->
                        <?php foreach ($roles as $rol): ?>
                            <th style="text-align: center;"><?= ucfirst(htmlspecialchars($rol['nombre'])) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <!-- Una fila por cada módulo/página del sistema -->
                    <?php foreach ($permisos as $p): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($p['modulo']) ?></strong>
                                <?php if (!empty($p['descripcion'])): ?>
                                    <br><small class="muted-text"><?= htmlspecialchars($p['descripcion']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><small class="muted-text"><?= htmlspecialchars($p['pagina']) ?></small></td>
                            <!-- Por cada rol, mostramos si tiene (✓) o no tiene (—) acceso a este módulo -->
                            <?php foreach ($roles as $rol): ?>
                                <?php $tiene_acceso = $matriz[(int) $p['id_permiso']][(int) $rol['id_rol']] ?? false; ?>
                                <td style="text-align: center;">
                                    <?php if ($tiene_acceso): ?>
                                        <span class="badge badge-success">✓</span>
                                    <?php else: ?>
                                        <span class="muted-text">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php require_once 'assets/includes/partials/footer.php'; ?>

</body>
</html>
