<?php
// logout.php — Cierra la sesión activa del usuario y redirige al login.

// 1. Inicializar la sesión para poder acceder a sus datos y destruirla después.
require_once __DIR__ . '/assets/includes/session_boot.php';

// Registrar el cierre de sesión en la bitácora ANTES de borrar los datos de sesión.
if (isset($_SESSION['usuario_id'])) {
    require_once './assets/includes/config/config.php';
    require_once './assets/includes/helpers/bitacora_helper.php';
    registrar_bitacora($pdo, 'LOGOUT', "Cierre de sesión de " . ($_SESSION['nombre'] ?? 'usuario') . " (#{$_SESSION['usuario_id']}).");
}

// 2. Eliminar todas las variables almacenadas en $_SESSION (nombre, id_rol, usuario_id, paciente_id, etc.) sin destruir la sesión en sí todavía.
session_unset();

// 3. Destruir completamente la sesión en el servidor
session_destroy();

// 4. Redirigir al login mediante un header
header('Location: login.php');

// 5. Detener la ejecución del script inmediatamente después del header.
exit;