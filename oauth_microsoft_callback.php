<?php
// oauth_microsoft_callback.php — Recibe la respuesta de Microsoft y gestiona la sesión.

require_once __DIR__ . '/assets/includes/session_boot.php';
require_once 'assets/includes/config/config.php';
require_once 'assets/includes/config/oauth_config.php';
require_once 'assets/includes/helpers/bitacora_helper.php';

// ── 1. Verificar state contra CSRF ─────────────────────────────────────────
// El "state" que mandamos antes de ir a Microsoft debe ser exactamente el mismo que regresa,
// si no coincide es que la petición no vino realmente de nuestro propio flujo de login.
if (
    empty($_GET['state']) ||
    empty($_SESSION['ms_oauth_state']) ||
    !hash_equals($_SESSION['ms_oauth_state'], $_GET['state'])
) {
    session_unset();
    header('Location: /login.php?error=ms_state');
    exit;
}
unset($_SESSION['ms_oauth_state']); // Ya cumplió su función, no hace falta guardarlo más.

// ── 2. Verificar que Microsoft nos envió un code ───────────────────────────
// El "code" es como un boleto de un solo uso que canjeamos por el token en el siguiente paso.
if (empty($_GET['code'])) {
    header('Location: /login.php?error=ms_code');
    exit;
}

// ── 3. Canjear el code por un access_token ────────────────────────────────
// Le pedimos a Microsoft, por atrás (servidor a servidor), un token de acceso a cambio del code.
$token_response = file_get_contents(
    'https://login.microsoftonline.com/common/oauth2/v2.0/token',
    false,
    stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'code'          => $_GET['code'],
                'client_id'     => MS_CLIENT_ID,
                'client_secret' => MS_CLIENT_SECRET,
                'redirect_uri'  => MS_REDIRECT_URI,
                'grant_type'    => 'authorization_code',
                'scope'         => MS_SCOPES,
            ]),
        ],
    ])
);

if ($token_response === false) {
    header('Location: /login.php?error=ms_token');
    exit;
}

$token_data = json_decode($token_response, true); // Convertimos la respuesta JSON a un array de PHP.

if (empty($token_data['access_token'])) {
    header('Location: /login.php?error=ms_token');
    exit;
}

// ── 4. Obtener perfil del usuario desde Microsoft Graph ───────────────────
// Con el access_token pedimos a la API de Microsoft Graph los datos básicos del usuario.
$perfil_response = file_get_contents(
    'https://graph.microsoft.com/v1.0/me?$select=displayName,givenName,surname,mail,userPrincipalName',
    false,
    stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Authorization: Bearer ' . $token_data['access_token'],
        ],
    ])
);

if ($perfil_response === false) {
    header('Location: /login.php?error=ms_perfil');
    exit;
}

$perfil = json_decode($perfil_response, true);

// Microsoft a veces devuelve el correo en 'mail' o en 'userPrincipalName'.
// userPrincipalName siempre existe pero puede ser un UPN corporativo (user@tenant.onmicrosoft.com).
// Preferimos 'mail' si está disponible.
$ms_email    = $perfil['mail'] ?? $perfil['userPrincipalName'] ?? null;
$ms_nombre   = $perfil['givenName']  ?? ($perfil['displayName'] ?? 'Usuario');
$ms_apellido = $perfil['surname']    ?? 'Microsoft';

if (empty($ms_email)) {
    header('Location: /login.php?error=ms_perfil');
    exit;
}

// ── 5. Buscar si el correo ya existe en OdontoNet ─────────────────────────
$stmt = $pdo->prepare('
    SELECT u.id_usuario, u.id_rol, u.nombre, u.apellido, u.cedula, u.foto_perfil, u.estado, p.id_paciente
    FROM usuarios u
    LEFT JOIN pacientes p ON p.id_usuario = u.id_usuario
    WHERE u.correo = :correo
    LIMIT 1
');
$stmt->execute([':correo' => $ms_email]);
$usuario = $stmt->fetch();

// ── 6. Si el usuario existe pero está inactivo, bloquear ──────────────────
if ($usuario && (int)$usuario['estado'] !== 1) {
    header('Location: /login.php?razon=inactivo');
    exit;
}

// ── 7. Si no existe, crear cuenta nueva como paciente ─────────────────────
if (!$usuario) {
    try {
        $pdo->beginTransaction();

        $stmt_rol = $pdo->prepare("SELECT id_rol FROM roles WHERE nombre = 'paciente' LIMIT 1");
        $stmt_rol->execute();
        $id_rol_paciente = (int)$stmt_rol->fetchColumn();

        $stmt_usr = $pdo->prepare('
            INSERT INTO usuarios (id_rol, nombre, apellido, correo, contrasena, estado)
            VALUES (:id_rol, :nombre, :apellido, :correo, NULL, 1)
        ');
        $stmt_usr->execute([
            ':id_rol'   => $id_rol_paciente,
            ':nombre'   => $ms_nombre,
            ':apellido' => $ms_apellido,
            ':correo'   => $ms_email,
        ]);

        $id_nuevo = (int)$pdo->lastInsertId();

        $stmt_pac = $pdo->prepare('INSERT INTO pacientes (id_usuario) VALUES (:id)');
        $stmt_pac->execute([':id' => $id_nuevo]);

        $pdo->commit();

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
        registrar_bitacora($pdo, 'ERROR', "Falló la creación de cuenta vía Microsoft OAuth para '$ms_email': " . $e->getMessage());
        header('Location: /login.php?error=ms_db');
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
    $_SESSION['mfa_pendiente_destino']    = '/' . $destino;
    header('Location: /verificar_mfa.php');
    exit;
}

// ── 9. Crear sesión ───────────────────────────────────────────────────────
// Sin MFA (o ya verificado): guardamos los datos del usuario en la sesión, queda logueado.
$_SESSION['usuario_id'] = $usuario['id_usuario'];
$_SESSION['id_rol']     = (int)$usuario['id_rol'];
$_SESSION['nombre']     = $usuario['nombre'];
$_SESSION['apellido']   = $usuario['apellido'];
$_SESSION['correo']     = $ms_email;
$_SESSION['cedula']     = $usuario['cedula'] ?? null;
$_SESSION['foto_perfil'] = $usuario['foto_perfil'] ?? null;

// Si es paciente, guardamos también su id_paciente.
if (!empty($usuario['id_paciente'])) {
    $_SESSION['paciente_id'] = $usuario['id_paciente'];
}

registrar_bitacora($pdo, 'LOGIN', "Inicio de sesión con Microsoft de {$usuario['nombre']} (#{$usuario['id_usuario']}).", (int) $usuario['id_usuario']);

header('Location: /' . $destino);
exit;
