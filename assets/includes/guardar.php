<?php

// guardar.php — Procesa el formulario de registro.php y crea el paciente.

session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/helpers/bitacora_helper.php';

// Redirige de vuelta a registro.php con un mensaje de error,
// conservando los datos ingresados (excepto la contraseña).
function registro_error(string $mensaje): never {
    $_SESSION['error_registro'] = $mensaje; // El mensaje que se le va a mostrar al usuario.
    $_SESSION['datos_registro'] = [
        // Guardamos lo que ya había escrito, para no obligarlo a llenar todo otra vez.
        'nombre'           => $_POST['nombre'] ?? '',
        'apellido'         => $_POST['apellido'] ?? '',
        'correo'           => $_POST['correo'] ?? '',
        'cedula'           => $_POST['cedula'] ?? '',
        'telefono'         => $_POST['telefono'] ?? '',
        'fecha_nacimiento' => $_POST['fecha_nacimiento'] ?? '',
        'direccion'        => $_POST['direccion'] ?? '',
    ];
    header('Location: ../../registro.php');
    exit;
}

// Lista de campos que no pueden quedar vacíos.
$campos_requeridos = ['nombre', 'apellido', 'correo', 'clave', 'cedula', 'fecha_nacimiento', 'telefono'];

// Revisamos uno por uno; si falta alguno, cortamos aquí mismo con un mensaje de error.
foreach ($campos_requeridos as $campo) {
    if (empty($_POST[$campo])) {
        registro_error('Falta el campo requerido: ' . $campo);
    }
}

// Leemos y limpiamos (quitamos espacios de más) cada dato del formulario.
$nombre           = trim($_POST['nombre']);
$apellido         = trim($_POST['apellido']);
$correo           = trim($_POST['correo']);
$cedula           = trim($_POST['cedula']);
$telefono         = trim($_POST['telefono']);
$fecha_nacimiento = trim($_POST['fecha_nacimiento']);
$direccion        = trim($_POST['direccion'] ?? '');

// --- Nombre y apellido: solo letras y espacios, máx. 50 caracteres ---
$regex_nombre = '/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]{2,50}$/';

if (!preg_match($regex_nombre, $nombre)) {
    registro_error('El nombre solo debe contener letras (sin números ni símbolos) y máximo 50 caracteres.');
}

if (!preg_match($regex_nombre, $apellido)) {
    registro_error('El apellido solo debe contener letras (sin números ni símbolos) y máximo 50 caracteres.');
}

// --- Cédula: exactamente 9 dígitos numéricos ---
if (!preg_match('/^[0-9]{9}$/', $cedula)) {
    registro_error('La cédula debe contener exactamente 9 dígitos numéricos, sin guiones ni espacios.');
}

// --- Teléfono: solo números ---
if (!preg_match('/^[0-9]{8,15}$/', $telefono)) {
    registro_error('El teléfono solo debe contener números, sin espacios, guiones ni otros caracteres.');
}

// --- Correo: formato válido y dentro del límite de la columna (varchar 150) ---
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    registro_error('Ingresa un correo electrónico válido.');
}
if (mb_strlen($correo) > 150) {
    registro_error('El correo electrónico no puede exceder 150 caracteres.');
}

// --- Dirección: opcional, dentro del límite de la columna (varchar 255) ---
if (mb_strlen($direccion) > 255) {
    registro_error('La dirección no puede exceder 255 caracteres.');
}

// --- Fecha de nacimiento: entre 1876 y hoy ---
$fecha_min = new DateTime('1876-01-01');
$fecha_max = new DateTime(); // hoy
$fecha_dt  = DateTime::createFromFormat('Y-m-d', $fecha_nacimiento);

if (!$fecha_dt || $fecha_dt < $fecha_min || $fecha_dt > $fecha_max) {
    registro_error('La fecha de nacimiento no es válida. Debe estar entre 1876 y la fecha actual.');
}

// --- Contraseña ---
// Exige: al menos un número, una minúscula, una mayúscula, un símbolo, y mínimo 8 caracteres en total.
$clave_valida = preg_match('/^(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}$/', $_POST['clave']);
if (!$clave_valida) {
    registro_error('La contraseña no cumple con los requisitos mínimos de seguridad.');
}

// --- Duplicados: correo y cédula ---
// Buscamos si ya existe alguien registrado con ese correo o esa cédula.
$stmt_dup = $pdo->prepare('SELECT correo, cedula FROM usuarios WHERE correo = :correo OR cedula = :cedula LIMIT 1');
$stmt_dup->execute(['correo' => $correo, 'cedula' => $cedula]);
$existente = $stmt_dup->fetch();

if ($existente) {
    if ($existente['correo'] === $correo) {
        registro_error('Ya existe una cuenta registrada con ese correo electrónico.');
    }
    registro_error('Ya existe una cuenta registrada con esa cédula.');
}

// --- Rol paciente ---
// Buscamos el id_rol de "paciente" (no lo escribimos fijo por número, por si cambia en la base de datos).
$stmt_rol = $pdo->prepare('SELECT id_rol FROM roles WHERE nombre = :nombre_rol LIMIT 1');
$stmt_rol->execute(['nombre_rol' => 'paciente']);
$rol = $stmt_rol->fetch();

if (!$rol) {
    registro_error('No existe el rol "paciente" en la base de datos.');
}

$id_rol_paciente = $rol['id_rol'];

try {
    // Empezamos una transacción: las dos inserciones (usuario + paciente) se guardan juntas, o ninguna.
    $pdo->beginTransaction();

    $sql_usuario = 'INSERT INTO usuarios
                    (id_rol, nombre, apellido, cedula, fecha_nacimiento, direccion, correo, contrasena, telefono, estado)
                    VALUES
                    (:id_rol, :nombre, :apellido, :cedula, :fecha_nacimiento, :direccion, :correo, :contrasena, :telefono, 1)';

    // Primero creamos la fila en "usuarios" (los datos de cuenta: nombre, correo, contraseña, etc.).
    $stmt_usuario = $pdo->prepare($sql_usuario);
    $stmt_usuario->execute([
        'id_rol'           => $id_rol_paciente,
        'nombre'           => $nombre,
        'apellido'         => $apellido,
        'cedula'           => $cedula,
        'fecha_nacimiento' => $fecha_nacimiento,
        'direccion'        => $direccion !== '' ? $direccion : null,
        'correo'           => $correo,
        'contrasena'       => password_hash($_POST['clave'], PASSWORD_DEFAULT), // Nunca se guarda la contraseña tal cual, siempre cifrada/hasheada.
        'telefono'         => $telefono,
    ]);

    // Tomamos el id que acaba de generar la base de datos para ese usuario nuevo.
    $id_usuario_nuevo = $pdo->lastInsertId();

    // Y con ese id creamos también su fila en "pacientes" (lo que lo marca como paciente del sistema).
    $sql_paciente = 'INSERT INTO pacientes (id_usuario) VALUES (:id_usuario)';
    $stmt_paciente = $pdo->prepare($sql_paciente);
    $stmt_paciente->execute(['id_usuario' => $id_usuario_nuevo]);

    // Todo salió bien: confirmamos los cambios de forma definitiva.
    $pdo->commit();

} catch (PDOException $e) {
    // Si algo falló a mitad de camino, deshacemos todo (no queda un usuario a medias sin paciente).
    $pdo->rollBack();
    registrar_bitacora($pdo, 'ERROR', "Falló el registro público de un paciente nuevo ('$correo'): " . $e->getMessage());
    registro_error('Ocurrió un error al registrar tus datos. Verifica que la cédula y el correo no estén ya registrados.');
}

// Registro exitoso: limpiar cualquier dato viejo de sesión y redirigir al login.
unset($_SESSION['error_registro'], $_SESSION['datos_registro']);
header('Location: ../../login.php');
exit;
