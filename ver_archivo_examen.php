<?php
/*
    ver_archivo_examen.php

    Único punto de acceso a los archivos adjuntos de la tabla `examenes`
    (radiografías/resultados en JPG, PNG o PDF). Los archivos viven en
    /storage/examenes, fuera del alcance del servidor web (bloqueado por
    storage/.htaccess), así que la única forma de verlos es a través de
    este script, que exige sesión activa y verifica que quien pide el
    archivo tenga derecho a verlo:

      - Administrador: cualquier archivo.
      - Doctor: solo los exámenes que él mismo solicitó.
      - Paciente: solo sus propios exámenes.
      - Recepcionista: sin acceso (no gestiona exámenes clínicos).
*/

require_once __DIR__ . '/assets/includes/session_boot.php';
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

$id_examen = isset($_GET['id']) ? intval($_GET['id']) : 0; // El id del examen viene en la URL.
if ($id_examen <= 0) {
    http_response_code(404);
    die('Archivo no encontrado.');
}

// Buscamos el examen y también a quién pertenece (paciente y doctor), para poder validar el acceso más abajo.
$stmt = $pdo->prepare(
    "SELECT e.archivo, e.id_paciente, e.id_doctor, p.id_usuario AS paciente_id_usuario, d.id_usuario AS doctor_id_usuario
     FROM examenes e
     INNER JOIN pacientes p ON e.id_paciente = p.id_paciente
     INNER JOIN doctores d ON e.id_doctor = d.id_doctor
     WHERE e.id_examen = :id"
);
$stmt->execute([':id' => $id_examen]);
$examen = $stmt->fetch();

if (!$examen || empty($examen['archivo'])) {
    http_response_code(404);
    die('Archivo no encontrado.');
}

$id_rol = (int) ($_SESSION['id_rol'] ?? 0);
$usuario_id = (int) $_SESSION['usuario_id'];

// Reglas de acceso según el rol: administrador ve todo, doctor solo lo suyo, paciente solo lo suyo, el resto nada.
$autorizado = match ($id_rol) {
    4 => true,
    2 => $usuario_id === (int) $examen['doctor_id_usuario'],
    1 => $usuario_id === (int) $examen['paciente_id_usuario'],
    default => false,
};

if (!$autorizado) {
    http_response_code(403);
    die('No tienes permiso para ver este archivo.');
}

// El campo `archivo` siempre se genera como 'storage/examenes/<archivo>' (ver examenes.php).
$ruta_relativa = $examen['archivo'];
$ruta_absoluta = realpath(__DIR__ . '/' . $ruta_relativa);
$carpeta_permitida = realpath(__DIR__ . '/storage/examenes');

// Confirmamos que la ruta resuelta siga dentro de storage/examenes (evita path traversal).
if ($ruta_absoluta === false || $carpeta_permitida === false || !str_starts_with($ruta_absoluta, $carpeta_permitida)) {
    http_response_code(404);
    die('Archivo no encontrado.');
}

// Según la extensión del archivo, le decimos al navegador qué tipo de contenido es.
$extension = strtolower(pathinfo($ruta_absoluta, PATHINFO_EXTENSION));
$tipos_mime = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'pdf'  => 'application/pdf',
];
$mime = $tipos_mime[$extension] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($ruta_absoluta));
header('Content-Disposition: inline; filename="' . basename($ruta_absoluta) . '"'); // "inline" = se muestra en el navegador, no se descarga directo.
header('X-Content-Type-Options: nosniff'); // Evita que el navegador intente "adivinar" otro tipo de archivo.
header('Cache-Control: private, no-store'); // No se debe guardar en caché, es un archivo médico privado.

readfile($ruta_absoluta); // Enviamos el contenido real del archivo al navegador.
exit;
