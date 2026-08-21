<?php
// oauth_google_callback.php — Recibe la respuesta de Google y gestiona la sesión.

session_start();
require_once 'assets/includes/config/config.php';
require_once 'assets/includes/config/oauth_config.php';
require_once 'assets/includes/helpers/bitacora_helper.php';

// ── 1. Verificar state contra CSRF ─────────────────────────────────────────
// El "state" que mandamos antes de ir a Google debe ser exactamente el mismo que regresa,
// si no coincide es que la petición no vino realmente de nuestro propio flujo de login.
if (
    empty($_GET['state']) ||
    empty($_SESSION['oauth_state']) ||
    !hash_equals($_SESSION['oauth_state'], $_GET['state'])
) {
    session_unset();
    header('Location: /OdontoNet/login.php?error=oauth_state');
    exit;
}
unset($_SESSION['oauth_state']); // Ya cumplió su función, no hace falta guardarlo más.

// ── 2. Verificar que Google nos envió un code ──────────────────────────────
// El "code" es como un boleto de un solo uso que canjeamos por el token en el siguiente paso.
if (empty($_GET['code'])) {
    header('Location: /OdontoNet/login.php?error=oauth_code');
    exit;
}

// ── 3. Canjear el code por un access_token ────────────────────────────────
// Le pedimos a Google, por atrás (servidor a servidor), un token de acceso a cambio del code.
$token_response = file_get_contents('https://oauth2.googleapis.com/token', false,
    stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'code'          => $_GET['code'],
                'client_id'     => GOOGLE_CLIENT_ID,
                'client_secret' => GOOGLE_CLIENT_SECRET,
                'redirect_uri'  => GOOGLE_REDIRECT_URI,
                'grant_type'    => 'authorization_code',
            ]),
        ],
    ])
);

if ($token_response === false) {
    header('Location: /OdontoNet/login.php?error=oauth_token');
    exit;
}

$token_data = json_decode($token_response, true); // Convertimos la respuesta JSON a un array de PHP.

if (empty($token_data['access_token'])) {
    header('Location: /OdontoNet/login.php?error=oauth_token');
    exit;
}

// ── 4. Obtener perfil del usuario desde Google ────────────────────────────
// Con el access_token ya podemos pedirle a Google los datos básicos del usuario (nombre, correo).
$perfil_response = file_get_contents('https://www.googleapis.com/oauth2/v2/userinfo', false,
    stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Authorization: Bearer ' . $token_data['access_token'],
        ],
    ])
);

if ($perfil_response === false) {
    header('Location: /OdontoNet/login.php?error=oauth_perfil');
    exit;
}

$perfil = json_decode($perfil_response, true);

if (empty($perfil['email'])) {
    header('Location: /OdontoNet/login.php?error=oauth_perfil');
    exit;
}

// Datos básicos que nos interesan del perfil de Google.
$google_email  = $perfil['email'];
$google_nombre = $perfil['given_name']  ?? 'Usuario';
$google_apellido = $perfil['family_name'] ?? 'Google';

// ── 5. Buscar si el correo ya existe en OdontoNet ─────────────────────────
$stmt = $pdo->prepare('
    SELECT u.id_usuario, u.id_rol, u.nombre, u.apellido, u.cedula, u.foto_perfil, u.estado, p.id_paciente
    FROM usuarios u
    LEFT JOIN pacientes p ON p.id_usuario = u.id_usuario
    WHERE u.correo = :correo
    LIMIT 1
');
$stmt->execute([':correo' => $google_email]);
$usuario = $stmt->fetch();

// ── 6. Si el usuario existe pero está inactivo, bloquear ──────────────────
if ($usuario && (int)$usuario['estado'] !== 1) {
    header('Location: /OdontoNet/login.php?razon=inactivo');
    exit;
}

// ── 7. Si no existe, crear cuenta nueva como paciente ────────────────────
if (!$usuario) {
    try {
        $pdo->beginTransaction();

        // Buscar id_rol de paciente
        $stmt_rol = $pdo->prepare("SELECT id_rol FROM roles WHERE nombre = 'paciente' LIMIT 1");
        $stmt_rol->execute();
        $id_rol_paciente = (int)$stmt_rol->fetchColumn();

        // Insertar en usuarios (sin contraseña — cuenta OAuth)
        $stmt_usr = $pdo->prepare('
            INSERT INTO usuarios (id_rol, nombre, apellido, correo, contrasena, estado)
            VALUES (:id_rol, :nombre, :apellido, :correo, NULL, 1)
        ');
        $stmt_usr->execute([
            ':id_rol'   => $id_rol_paciente,
            ':nombre'   => $google_nombre,
            ':apellido' => $google_apellido,
            ':correo'   => $google_email,
        ]);

        $id_nuevo = (int)$pdo->lastInsertId();

        // Insertar en pacientes
        $stmt_pac = $pdo->prepare('INSERT INTO pacientes (id_usuario) VALUES (:id)');
        $stmt_pac->execute([':id' => $id_nuevo]);

        $pdo->commit();

        // Refetch para tener los datos completos
        $stmt = $pdo->prepare('
            SELECT u.id_usuario, u.id_rol, u.nombre, u.apellido, u.cedula, u.foto_perfil, u.estado, p.id_paciente
            FROM usuarios u
            LEFT JOIN pacientes p ON p.id_usuario = u.id_usuario
            WHERE u.id_usuario = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $id_nuevo]);
        $usuario = $stmt->fetch();

    } catch (PDOException $e) {
        $pdo->rollBack();
        registrar_bitacora($pdo, 'ERROR', "Falló la creación de cuenta vía Google OAuth para '$google_email': " . $e->getMessage());
        header('Location: /OdontoNet/login.php?error=oauth_db');
        exit;
    }
}

// ── 8. Redirigir según rol ────────────────────────────────────────────────
// A dónde va cada rol después de iniciar sesión.
$destino = match((int)$usuario['id_rol']) {
    4       => 'admin.php',
    2, 3    => 'control_citas.php',
    default => 'inicio.php',
};

// Si tiene la verificación en dos pasos activa, la sesión completa NO se
// crea todavía: queda pendiente hasta que verificar_mfa.php confirme el código.
require_once 'assets/includes/config/mfa_config.php';
require_once 'assets/includes/helpers/mfa_helper.php';

if (mfa_esta_activo($pdo, (int) $usuario['id_usuario'])) {
    // Guardamos en sesión quién es y a dónde debe ir DESPUÉS de verificar el código MFA.
    $_SESSION['mfa_pendiente_usuario_id'] = (int) $usuario['id_usuario'];
    $_SESSION['mfa_pendiente_destino']    = '/OdontoNet/' . $destino;
    header('Location: /OdontoNet/verificar_mfa.php');
    exit;
}

// ── 9. Crear sesión ───────────────────────────────────────────────────────
// Sin MFA (o ya verificado): guardamos los datos del usuario en la sesión, queda logueado.
$_SESSION['usuario_id'] = $usuario['id_usuario'];
$_SESSION['id_rol']     = (int)$usuario['id_rol'];
$_SESSION['nombre']     = $usuario['nombre'];
$_SESSION['apellido']   = $usuario['apellido'];
$_SESSION['correo']     = $google_email;
$_SESSION['cedula']     = $usuario['cedula'] ?? null;
$_SESSION['foto_perfil'] = $usuario['foto_perfil'] ?? null;

// Si es paciente, guardamos también su id_paciente.
if (!empty($usuario['id_paciente'])) {
    $_SESSION['paciente_id'] = $usuario['id_paciente'];
}

registrar_bitacora($pdo, 'LOGIN', "Inicio de sesión con Google de {$usuario['nombre']} (#{$usuario['id_usuario']}).", (int) $usuario['id_usuario']);

header('Location: /OdontoNet/' . $destino);
exit;
