<?php

// config.php — Este archivo se encarga de preparar la conexión con la base de datos.
// Se incluye en otros archivos (ej: login.php, guardar.php) para que todos usen la misma conexión.

// Lee una variable de entorno probando las tres formas en que PHP puede
// exponerla (getenv(), $_ENV, $_SERVER) — según el servidor/runtime, no
// siempre se llenan las tres, así que se revisan todas antes de rendirse
// y usar el valor por defecto (pensado para un entorno local de Laragon/XAMPP).
function config_env(string $nombre, string $por_defecto): string {
    $valor = getenv($nombre);
    if ($valor !== false && $valor !== '') {
        return $valor;
    }
    if (!empty($_ENV[$nombre])) {
        return $_ENV[$nombre];
    }
    if (!empty($_SERVER[$nombre])) {
        return $_SERVER[$nombre];
    }
    return $por_defecto;
}

// Datos de conexión a la base de datos. En producción (ej. Vercel) se configuran
// como variables de entorno DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD; en
// local, sin esas variables, se usan estos valores por defecto de Laragon/XAMPP.
$host = config_env('DB_HOST', 'localhost');
$port = config_env('DB_PORT', '3306');
$dbname = config_env('DB_NAME', 'schema');
$username = config_env('DB_USER', 'root');
$password = config_env('DB_PASSWORD', '');

/* ----------------------------------------------------------------------
    Conexión con la base de datos usando PDO (PHP Data Objects).
    PDO es la forma moderna y segura de conectarse a MySQL en PHP.
    Permite usar consultas preparadas para evitar ataques de inyección SQL.
---------------------------------------------------------------------- */
$opciones_pdo = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,        // Los errores de SQL lanzan excepciones en vez de fallar en silencio
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,   // Los resultados de SELECT se devuelven como arrays asociativos
];

// La mayoría de los proveedores de MySQL administrado (Railway, Aiven, Clever Cloud...)
// exigen conexión cifrada (TLS). Se activa solo si se define DB_SSL_CA (ruta al
// certificado de la entidad certificadora que entrega el proveedor); en local, sin esa
// variable, la conexión sigue siendo la normal sin TLS.
$ssl_ca = config_env('DB_SSL_CA', '');
if ($ssl_ca !== '') {
    $opciones_pdo[PDO::MYSQL_ATTR_SSL_CA] = $ssl_ca;
    $opciones_pdo[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = filter_var(config_env('DB_SSL_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN);
}

$dsn_base = "mysql:host=$host;port=$port;charset=utf8mb4";

try {

    // Se intenta conectar directo a la base de datos indicada. En un servidor
    // administrado (producción) esa base ya existe de antemano y el usuario normalmente
    // no tiene permiso para crear bases nuevas, así que este es el camino esperado ahí.
    $pdo = new PDO("$dsn_base;dbname=$dbname", $username, $password, $opciones_pdo);

} catch (PDOException $e) {

    // Si la base de datos todavía no existe (típico solo la primera vez, en un entorno
    // local nuevo), se crea automáticamente y se reintenta la conexión.
    if ((int)$e->getCode() === 1049 || str_contains($e->getMessage(), 'Unknown database')) {

        try {
            $pdo_sin_db = new PDO($dsn_base, $username, $password, $opciones_pdo);
            $pdo_sin_db->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo = new PDO("$dsn_base;dbname=$dbname", $username, $password, $opciones_pdo);

        } catch (PDOException $e2) {
            // Si ocurre un error (ej: credenciales incorrectas, servidor caído), se detiene el programa y se muestra el mensaje de error.
            die('Error de conexión: ' . $e2->getMessage());
        }

    } else {
        // Cualquier otro error de conexión (credenciales incorrectas, servidor caído, TLS mal configurado, etc.).
        die('Error de conexión: ' . $e->getMessage());
    }
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
