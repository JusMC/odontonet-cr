<?php
/**
 * mail_helper.php — Envío de correos por SMTP (PHPMailer).
 *
 * Requiere que mail_config.php ya haya sido incluido antes (usa MAIL_HOST,
 * MAIL_PORT, MAIL_ENCRYPTION, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_*).
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Envía el código de recuperación de contraseña por correo.
 * Devuelve true si el correo se envió correctamente, false si falló
 * (el llamador decide qué mostrarle al usuario en ese caso).
 */
function enviar_codigo_reset(string $destinatario, string $nombreCompleto, string $codigo): bool {
    $mail = new PHPMailer(true); // true = que lance excepciones si algo falla, así lo podemos atrapar abajo.

    try {
        // Configuramos con qué servidor y cuenta se va a enviar el correo (datos de mail_config.php).
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';

        // Quién envía y quién recibe el correo.
        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($destinatario, $nombreCompleto);

        // El correo se arma en HTML, con su asunto y el diseño del mensaje.
        $mail->isHTML(true);
        $mail->Subject = 'Tu código para restablecer tu contraseña — OdontoNet';
        $mail->Body    = '
            <div style="font-family:Arial,sans-serif; max-width:480px; margin:0 auto; padding:32px 24px; background:#10131d; color:#e7ecf4; border-radius:16px;">
                <h2 style="margin:0 0 16px; color:#e7ecf4;">Recupera tu contraseña</h2>
                <p style="color:#8a93a6; line-height:1.6;">Hola ' . htmlspecialchars($nombreCompleto) . ',</p>
                <p style="color:#8a93a6; line-height:1.6;">Usa este código para continuar con el restablecimiento de tu contraseña en OdontoNet. Vence en 15 minutos.</p>
                <p style="font-size:2.2rem; font-weight:700; letter-spacing:0.3em; text-align:center; color:#00f0ff; margin:28px 0;">' . htmlspecialchars($codigo) . '</p>
                <p style="color:#8a93a6; line-height:1.6; font-size:0.85rem;">Si no solicitaste este código, puedes ignorar este correo — tu contraseña no cambiará.</p>
            </div>
        ';
        // Versión en texto plano, por si el programa de correo del destinatario no puede mostrar HTML.
        $mail->AltBody = "Tu código para restablecer tu contraseña en OdontoNet es: $codigo (vence en 15 minutos). Si no lo solicitaste, ignora este correo.";

        $mail->send(); // Aquí es donde realmente se manda el correo.
        return true;    // Todo salió bien.
    } catch (PHPMailerException $e) {
        return false;   // Algo falló al enviar (servidor caído, credenciales malas, etc.); avisamos que no se pudo.
    }
}
