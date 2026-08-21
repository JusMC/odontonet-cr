<?php
/**
 * mfa_helper.php — Verificación en dos pasos (TOTP) para Google/Microsoft
 * Authenticator y cualquier otra app compatible (son el mismo estándar,
 * RFC 6238, así que a la app le da igual cuál de las dos sea).
 *
 * Requiere que mfa_config.php ya haya sido incluido antes (usa MFA_ENCRYPTION_KEY).
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use OTPHP\TOTP;

/**
 * Genera un secreto TOTP nuevo (todavía sin guardar/confirmar) y arma la URI
 * "otpauth://" que se codifica en el código QR de enrolamiento.
 */
function mfa_generar_totp_nuevo(string $correo): array {
    // 20 bytes (160 bits) es el tamaño de secreto estándar que todas las
    // apps TOTP conocidas soportan (el valor por defecto de la librería,
    // 64 bytes, es innecesariamente largo y no aporta seguridad real aquí).
    $totp = TOTP::generate(null, 20); // Crea el secreto aleatorio.
    $totp->setLabel($correo);         // El correo del usuario aparece como etiqueta dentro de la app.
    $totp->setIssuer('OdontoNet');    // Y "OdontoNet" aparece como el nombre del servicio.

    return [
        'secreto' => $totp->getSecret(),          // El secreto en sí, para guardarlo cifrado.
        'uri'     => $totp->getProvisioningUri(), // La dirección que se convierte en código QR.
    ];
}

/**
 * Reconstruye la URI "otpauth://" (para el QR) a partir de un secreto ya
 * existente — usado cuando se recarga la página con una activación todavía
 * pendiente de confirmar, para poder seguir mostrando el mismo QR.
 */
function mfa_uri_desde_secreto(string $secreto, string $correo): string {
    $totp = TOTP::createFromSecret($secreto); // Reconstruimos el objeto TOTP a partir del secreto guardado.
    $totp->setLabel($correo);
    $totp->setIssuer('OdontoNet');
    return $totp->getProvisioningUri(); // Devolvemos la misma URI de antes, para volver a dibujar el QR.
}

/**
 * Cifra el secreto TOTP para guardarlo en mfa_totp.secreto_cifrado. Se cifra
 * (no se hashea, a diferencia de una contraseña) porque hace falta poder
 * leerlo de vuelta para calcular códigos en cada verificación.
 */
function mfa_cifrar_secreto(string $secreto): string {
    $clave = base64_decode(MFA_ENCRYPTION_KEY);                     // La clave secreta del sistema (mfa_config.php).
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);      // Un valor aleatorio distinto cada vez, para que el mismo secreto no se cifre siempre igual.
    $cifrado = sodium_crypto_secretbox($secreto, $nonce, $clave);   // Cifrado real.

    return base64_encode($nonce . $cifrado); // Guardamos el nonce pegado al cifrado, porque hace falta para descifrar después.
}

function mfa_descifrar_secreto(string $cifradoBase64): string {
    $clave = base64_decode(MFA_ENCRYPTION_KEY);
    $datos = base64_decode($cifradoBase64);
    // Separamos de vuelta el nonce (los primeros bytes) del texto cifrado (el resto).
    $nonce = mb_substr($datos, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
    $cifrado = mb_substr($datos, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');

    $secreto = sodium_crypto_secretbox_open($cifrado, $nonce, $clave); // Intentamos descifrar.
    if ($secreto === false) {
        // Si devuelve false es que la clave no coincide o los datos están dañados/manipulados.
        throw new RuntimeException('No se pudo descifrar el secreto TOTP.');
    }

    return $secreto;
}

/**
 * Verifica un código de 6 dígitos contra un secreto TOTP en texto plano.
 * La tolerancia de 1 "paso" (±30s) acepta pequeños desfases de reloj entre
 * el teléfono y el servidor, que son normales.
 */
function mfa_verificar_codigo(string $secreto, string $codigo): bool {
    $codigo = trim($codigo);
    // El código siempre debe ser exactamente 6 dígitos; si no cumple ese formato, ni lo comprobamos.
    if (!preg_match('/^[0-9]{6}$/', $codigo)) {
        return false;
    }

    $totp = TOTP::createFromSecret($secreto);
    return $totp->verify($codigo, null, 1); // El "1" permite un pequeño margen de tiempo (±30s) por si el reloj del celular está un poco desfasado.
}

/** ¿El usuario tiene MFA activo (secreto confirmado, no solo generado)? */
function mfa_esta_activo(PDO $pdo, int $id_usuario): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM mfa_totp WHERE id_usuario = :id AND confirmado = 1 LIMIT 1');
    $stmt->execute(['id' => $id_usuario]);
    return (bool) $stmt->fetchColumn(); // true si encontró una fila confirmada, false si no.
}

/**
 * Genera códigos de respaldo en texto plano (el llamador los muestra una
 * sola vez y los guarda hasheados con password_hash, igual que una contraseña).
 */
function mfa_generar_codigos_respaldo(int $cantidad = 8): array {
    $codigos = [];
    for ($i = 0; $i < $cantidad; $i++) {
        // Formato "1234-5678": más fácil de transcribir a mano que un
        // bloque largo de caracteres random.
        $codigos[] = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT) // 4 dígitos, rellenando con ceros a la izquierda si hace falta.
            . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT); // Y otros 4 dígitos más, separados por un guion.
    }
    return $codigos;
}

/**
 * Verifica un código de respaldo y lo marca como usado (de un solo uso).
 * Devuelve true si era válido y no se había usado antes.
 */
function mfa_verificar_codigo_respaldo(PDO $pdo, int $id_usuario, string $codigo): bool {
    $codigo = trim($codigo);
    if ($codigo === '') {
        return false;
    }

    // Traemos todos los códigos de respaldo de este usuario que todavía no se han usado.
    $stmt = $pdo->prepare('SELECT id_codigo, codigo_hash FROM mfa_codigos_respaldo WHERE id_usuario = :id AND usado = 0');
    $stmt->execute(['id' => $id_usuario]);

    // Los comparamos uno por uno contra el código que escribió el usuario (están hasheados, como una contraseña).
    foreach ($stmt->fetchAll() as $fila) {
        if (password_verify($codigo, $fila['codigo_hash'])) {
            // Coincide: lo marcamos como usado para que no se pueda volver a usar.
            $pdo->prepare('UPDATE mfa_codigos_respaldo SET usado = 1, fecha_uso = NOW() WHERE id_codigo = :id')
                ->execute(['id' => $fila['id_codigo']]);
            return true;
        }
    }

    return false; // Ninguno coincidió.
}

/**
 * Termina de armar la sesión completa de un usuario ya autenticado (con
 * contraseña u OAuth) y ya verificado por MFA. Se centraliza aquí porque
 * los 3 flujos de login (contraseña, Google, Microsoft) dejan de fijar la
 * sesión ellos mismos cuando el usuario tiene MFA activo — la sesión real
 * solo se crea después de que verificar_mfa.php confirme el código.
 */
function mfa_finalizar_sesion(PDO $pdo, int $id_usuario): void {
    // Buscamos todos los datos del usuario que se guardan en la sesión (y su id_paciente, si aplica).
    $stmt = $pdo->prepare('
        SELECT u.id_usuario, u.id_rol, u.nombre, u.apellido, u.correo, u.cedula, u.foto_perfil, p.id_paciente
        FROM usuarios u
        LEFT JOIN pacientes p ON p.id_usuario = u.id_usuario
        WHERE u.id_usuario = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $id_usuario]);
    $usuario = $stmt->fetch();

    // Guardamos cada dato en la sesión: a partir de aquí el usuario queda oficialmente "logueado".
    $_SESSION['usuario_id']  = $usuario['id_usuario'];
    $_SESSION['id_rol']      = (int) $usuario['id_rol'];
    $_SESSION['nombre']      = $usuario['nombre'];
    $_SESSION['apellido']    = $usuario['apellido'];
    $_SESSION['correo']      = $usuario['correo'];
    $_SESSION['cedula']      = $usuario['cedula'];
    $_SESSION['foto_perfil'] = $usuario['foto_perfil'];

    // Si el usuario es paciente, guardamos también su id_paciente (lo usan las páginas de citas/historial).
    if (!empty($usuario['id_paciente'])) {
        $_SESSION['paciente_id'] = $usuario['id_paciente'];
    }
}
