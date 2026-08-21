<?php
/**
 * auth_check.php — Verificación de sesión activa y estado del usuario en DB.
 *
 * Incluir con require_once al inicio de cualquier página protegida,
 * DESPUÉS de session_start() y ANTES de cualquier output HTML.
 *
 * Uso:
 *   session_start();
 *   require_once 'assets/includes/auth_check.php';
 *
 * El archivo asume que $pdo ya está disponible (incluir config.php antes).
 * Hace dos cosas:
 *   1. Verifica que haya una sesión con usuario_id.
 *   2. Consulta la DB para confirmar que el usuario sigue activo (estado = 1).
 *      Si fue inactivado, destruye la sesión y redirige al login.
 */

require_once './assets/includes/config/config.php'; // Trae $pdo, la conexión a la base de datos

// Si en la sesión no hay un usuario_id guardado, es que nadie inició sesión: lo mandamos al login.
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /login.php');
    exit; // Detenemos la página aquí, no se muestra nada más.
}

// Verificar que el usuario siga activo en la base de datos.
// Esta consulta es muy ligera (PK lookup) y se ejecuta en microsegundos.
$_stmt_auth = $pdo->prepare('SELECT estado FROM usuarios WHERE id_usuario = :id LIMIT 1'); // Preparamos la consulta.
$_stmt_auth->execute([':id' => $_SESSION['usuario_id']]); // La ejecutamos con el id guardado en sesión.
$_usuario_estado = $_stmt_auth->fetchColumn(); // Leemos el valor de "estado" (1 = activo, 0 = inactivo).

// Si no encontramos al usuario (false) o su estado ya no es 1 (activo), no lo dejamos seguir.
if ($_usuario_estado === false || (int)$_usuario_estado !== 1) {
    // Usuario inactivado o eliminado: destruir sesión y redirigir.
    session_unset();   // Borra todas las variables de la sesión.
    session_destroy(); // Elimina la sesión por completo en el servidor.
    header('Location: /login.php?razon=inactivo'); // Vuelve al login con un aviso de por qué.
    exit;
}