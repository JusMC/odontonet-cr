<?php

// config.php — Este archivo se encarga de preparar la conexión con la base de datos.
// Se incluye en otros archivos (ej: login.php, guardar.php) para que todos usen la misma conexión.

// Datos de conexión a la base de datos.
$host = 'localhost';
$dbname = 'schema';
$username = 'root';
$password = '';

/* ----------------------------------------------------------------------
    Conexión con la base de datos usando PDO (PHP Data Objects).
    PDO es la forma moderna y segura de conectarse a MySQL en PHP.
    Permite usar consultas preparadas para evitar ataques de inyección SQL.
---------------------------------------------------------------------- */
try {

    // Primera conexión: SIN especificar todavía la base de datos (dbname), solo para poder ejecutar el CREATE DATABASE de abajo si todavía no existe.
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password, [

        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,        // Los errores de SQL lanzan excepciones en vez de fallar en silencio
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,   // Los resultados de SELECT se devuelven como arrays asociativos
    ]);

    // Crea la base de datos 'schema' si todavía no existe
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Segunda conexión: ahora sí indicando el nombre de la base de datos. Esta es la conexión que se usará en el resto del sistema.
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

}

catch (PDOException $e) {

    // Si ocurre un error (ej: credenciales incorrectas, servidor caído), se detiene el programa y se muestra el mensaje de error.
    die('Error de conexión: ' . $e->getMessage());
}

/* ----------------------------------------------------------------------
    Seed de recuperación: crea el usuario administrador del sistema
    automáticamente si no existe todavía. Esto garantiza que siempre
    haya al menos una cuenta de acceso aunque la base de datos se
    resetee o se instale en un servidor nuevo.

    Credenciales:
    - Identificador : .\recovery
    - Correo        : admin.sistema@odontonetcr.com
    - Contraseña    : ADMINodontonet26%
---------------------------------------------------------------------- */
try {

    // Verificamos si la tabla "usuarios" existe (puede no existir en una instalación nueva).
    $tabla_existe = $pdo->query("SHOW TABLES LIKE 'usuarios'")->fetch();

    if ($tabla_existe) {

        // Buscamos el id_rol del administrador en la tabla "roles". No usamos un número fijo, lo buscamos por nombre.
        $stmt_rol_admin = $pdo->prepare("SELECT id_rol FROM roles WHERE nombre = 'administrador' LIMIT 1");

        $stmt_rol_admin->execute();

        $rol_admin = $stmt_rol_admin->fetch();

        if ($rol_admin) {

            // Insertamos el usuario administrador con INSERT IGNORE. Si el correo ya existe, no se duplica ni lanza error.
            $stmt_seed = $pdo->prepare("
                INSERT IGNORE INTO usuarios
                    (id_rol, nombre, apellido, cedula, correo, contrasena, estado)
                VALUES
                    (:id_rol, 'Sistema', 'OdontoNet', :cedula, :correo, :contrasena, 1)
            ");

            // Ejecutamos la inserción con los valores correspondientes.
            $stmt_seed->execute([
                'id_rol'     => $rol_admin['id_rol'],
                'cedula'     => '.\\recovery',
                'correo'     => 'admin.sistema@odontonetcr.com',
                'contrasena' => password_hash('ADMINodontonet26%', PASSWORD_DEFAULT),
            ]);
        }
    }

} catch (PDOException $e) {
    // Si falla (ej: tabla roles no existe aún), se ignora el error. No bloquea el funcionamiento de la aplicación.
}

// Limpieza de intentos de login viejos. Se ejecuta con probabilidad 1/50 para no afectar el rendimiento. Borra intentos de login con más de 24 horas.
if (rand(1, 50) === 1) {

    try {

        $pdo->exec("DELETE FROM login_intentos WHERE fecha < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        
    } 
    
    catch (PDOException $e) {
        // Si la tabla no existe aún, se ignora el error.
    }
}