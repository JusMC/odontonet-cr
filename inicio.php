<?php

session_start();
require_once 'assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/bitacora_helper.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php?redirect=inicio.php');
    exit;
}
if (!isset($_SESSION['paciente_id'])) {
    $destino = match((int)($_SESSION['id_rol'] ?? 0)) {
        4 => 'admin.php',
        2, 3 => 'control_citas.php',
        default => 'login.php',
    };
    header('Location: ' . $destino);
    exit;
}

$usuario_id = (int) ($_SESSION['usuario_id'] ?? 0);
$paciente_id = (int) ($_SESSION['paciente_id'] ?? 0);
$nombre = $_SESSION['nombre'] ?? 'Paciente';

// Traemos los datos personales del usuario, para saber si su registro quedó incompleto (ver abajo).
$stmt_profile = $pdo->prepare('SELECT u.nombre, u.apellido, u.correo, u.telefono,
    u.cedula, u.fecha_nacimiento, u.direccion
    FROM usuarios u
    WHERE u.id_usuario = :id_usuario
    LIMIT 1');
    
$stmt_profile->execute(['id_usuario' => $usuario_id]);
$profile = $stmt_profile->fetch();

// Las cuentas creadas por OAuth (Google/Microsoft) se crean sin cédula,
// fecha de nacimiento, teléfono ni contraseña (esos campos no vienen del
// proveedor). La ausencia de cédula es la señal de que el registro quedó
// incompleto, y mientras lo esté, se fuerza el modal de abajo.
$registro_incompleto = empty($profile['cedula']);

$error_completar = '';
$datos_completar = [];

// Si el registro está incompleto y el usuario envió el modal "Completa tu registro"...
if ($registro_incompleto && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['completar_registro'])) {
    $cedula_nueva        = trim($_POST['cedula'] ?? '');
    $fecha_nueva         = trim($_POST['fecha_nacimiento'] ?? '');
    $telefono_nuevo      = trim($_POST['telefono'] ?? '');
    $direccion_nueva     = trim($_POST['direccion'] ?? '');
    $clave_nueva         = $_POST['clave'] ?? '';
    $clave_confirmar     = $_POST['clave_confirmar'] ?? '';
    $antecedentes_nuevos = trim($_POST['antecedentes_medicos'] ?? '');
    $alergias_nuevas     = trim($_POST['alergias'] ?? '');
    $datos_completar     = $_POST;

    $errores = [];

    // --- Mismas validaciones que assets/includes/guardar.php (registro.php) ---
    if (!preg_match('/^[0-9]{9}$/', $cedula_nueva)) {
        $errores[] = 'La cédula debe contener exactamente 9 dígitos numéricos, sin guiones ni espacios.';
    }
    if (!preg_match('/^[0-9]{8,15}$/', $telefono_nuevo)) {
        $errores[] = 'El teléfono solo debe contener números, sin espacios ni guiones.';
    }
    if (mb_strlen($direccion_nueva) > 255) {
        $errores[] = 'La dirección no puede exceder 255 caracteres.';
    }

    $fecha_min = new DateTime('1876-01-01');
    $fecha_max = new DateTime();
    $fecha_dt  = DateTime::createFromFormat('Y-m-d', $fecha_nueva);
    if (!$fecha_dt || $fecha_dt < $fecha_min || $fecha_dt > $fecha_max) {
        $errores[] = 'La fecha de nacimiento no es válida. Debe estar entre 1876 y la fecha actual.';
    }

    if (!preg_match('/^(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}$/', $clave_nueva)) {
        $errores[] = 'La contraseña debe tener al menos 8 caracteres, incluir una mayúscula, una minúscula, un número y un carácter especial.';
    } elseif ($clave_nueva !== $clave_confirmar) {
        $errores[] = 'Las contraseñas no coinciden.';
    }

    // --- Duplicado: cédula (excluyendo al propio usuario) ---
    if (empty($errores)) {
        $stmt_dup = $pdo->prepare('SELECT id_usuario FROM usuarios WHERE cedula = :cedula AND id_usuario != :id LIMIT 1');
        $stmt_dup->execute(['cedula' => $cedula_nueva, 'id' => $usuario_id]);
        if ($stmt_dup->fetch()) {
            $errores[] = 'Ya existe una cuenta registrada con esa cédula.';
        }
    }

    // Si no hubo ningún error, guardamos los datos completados.
    if (empty($errores)) {
        try {
            $pdo->beginTransaction();

            // Completamos los datos que faltaban en "usuarios" (incluida la contraseña, que antes no tenía).
            $stmt_upd_usuario = $pdo->prepare('UPDATE usuarios SET cedula = :cedula, fecha_nacimiento = :fecha_nacimiento,
                direccion = :direccion, telefono = :telefono, contrasena = :contrasena
                WHERE id_usuario = :id_usuario');
            $stmt_upd_usuario->execute([
                'cedula'           => $cedula_nueva,
                'fecha_nacimiento' => $fecha_nueva,
                'direccion'        => $direccion_nueva !== '' ? $direccion_nueva : null,
                'telefono'         => $telefono_nuevo,
                'contrasena'       => password_hash($clave_nueva, PASSWORD_BCRYPT),
                'id_usuario'       => $usuario_id,
            ]);

            // Y los datos médicos opcionales en "pacientes".
            $stmt_upd_paciente = $pdo->prepare('UPDATE pacientes SET antecedentes_medicos = :antecedentes, alergias = :alergias WHERE id_usuario = :id_usuario');
            $stmt_upd_paciente->execute([
                'antecedentes' => $antecedentes_nuevos !== '' ? $antecedentes_nuevos : null,
                'alergias'     => $alergias_nuevas !== '' ? $alergias_nuevas : null,
                'id_usuario'   => $usuario_id,
            ]);

            $pdo->commit();

            registrar_bitacora($pdo, 'UPDATE', "El usuario #$usuario_id completó el registro de su cuenta creada por OAuth.", $usuario_id);

            // La cédula ya se usa como respaldo del correo en el botón de ajustes del navbar.
            $_SESSION['cedula'] = $cedula_nueva;

            header('Location: inicio.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            registrar_bitacora($pdo, 'ERROR', "Falló completar el registro OAuth del usuario #$usuario_id: " . $e->getMessage(), $usuario_id);
            $errores[] = 'Ocurrió un error al guardar tus datos. Verifica que la cédula no esté ya registrada.';
        }
    }

    $error_completar = implode(' ', $errores);
}

// La cita más próxima del paciente (para la tarjeta destacada "Próxima cita").
$stmt_next = $pdo->prepare('SELECT c.fecha_hora, c.motivo, c.estado, u.nombre AS doctor_nombre, u.apellido AS doctor_apellido
    FROM citas c
    INNER JOIN doctores d ON d.id_doctor = c.id_doctor
    INNER JOIN usuarios u ON u.id_usuario = d.id_usuario
    WHERE c.id_paciente = :id_paciente AND c.fecha_hora >= NOW()
        AND c.estado NOT IN (\'completada\', \'cancelada\')
    ORDER BY c.fecha_hora ASC
    LIMIT 1');
$stmt_next->execute(['id_paciente' => $paciente_id]);
$next_appointment = $stmt_next->fetch();

// Las próximas 4 citas del paciente (lista completa, para la sección "Próximas citas").
$stmt_upcoming = $pdo->prepare('SELECT c.fecha_hora, c.motivo, c.estado, u.nombre AS doctor_nombre, u.apellido AS doctor_apellido
    FROM citas c
    INNER JOIN doctores d ON d.id_doctor = c.id_doctor
    INNER JOIN usuarios u ON u.id_usuario = d.id_usuario
    WHERE c.id_paciente = :id_paciente AND c.fecha_hora >= NOW()
        AND c.estado NOT IN (\'completada\', \'cancelada\')
    ORDER BY c.fecha_hora ASC
    LIMIT 4');
$stmt_upcoming->execute(['id_paciente' => $paciente_id]);
$upcoming_appointments = $stmt_upcoming->fetchAll();

// Las 4 entradas más recientes del historial médico.
$stmt_history = $pdo->prepare('SELECT hm.fecha_revision, hm.diagnostico, hm.tratamiento, hm.proxima_revision, u.nombre AS doctor_nombre, u.apellido AS doctor_apellido
    FROM historial_medico hm
    INNER JOIN doctores d ON d.id_doctor = hm.id_doctor
    INNER JOIN usuarios u ON u.id_usuario = d.id_usuario
    WHERE hm.id_paciente = :id_paciente
    ORDER BY hm.fecha_revision DESC
    LIMIT 4');
$stmt_history->execute(['id_paciente' => $paciente_id]);
$recent_history = $stmt_history->fetchAll();

// Las últimas 4 compras del paciente.
$stmt_compras = $pdo->prepare('SELECT id_venta, fecha_venta, total, estado
    FROM ventas
    WHERE id_paciente = :id_paciente
    ORDER BY fecha_venta DESC
    LIMIT 4');
$stmt_compras->execute(['id_paciente' => $paciente_id]);
$compras_recientes = $stmt_compras->fetchAll();

// Los 3 números que se muestran arriba (citas pendientes, citas pasadas, entradas de historial).
$stmt_counts = $pdo->prepare('SELECT
        (SELECT COUNT(*) FROM citas WHERE id_paciente = :id_paciente AND fecha_hora >= NOW() AND estado NOT IN (\'completada\', \'cancelada\')) AS citas_pendientes,
        (SELECT COUNT(*) FROM citas WHERE id_paciente = :id_paciente AND fecha_hora < NOW()) AS citas_pasadas,
        (SELECT COUNT(*) FROM historial_medico WHERE id_paciente = :id_paciente) AS historial_total
    ');
$stmt_counts->execute(['id_paciente' => $paciente_id]);
$counts = $stmt_counts->fetch();

// Da formato legible (día/mes/año hora:minuto AM/PM) a una fecha de la base de datos.
function format_date_time($value) {
    if (!$value) {
        return 'No disponible';
    }
    $date = new DateTime($value);
    return $date->format('d/m/Y h:i A');
}

// Igual, pero solo con la fecha (sin hora).
function format_date($value) {
    if (!$value) {
        return 'No disponible';
    }
    $date = new DateTime($value);
    return $date->format('d/m/Y');
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Área del Paciente - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <link rel="stylesheet" href="assets/css/pages/inicio.css">
</head>

<body>
    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>
    <div class="glow glow-c"></div>
    <div class="grid-overlay"></div>

    <!-- NAVBAR -->
    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <?php if ($registro_incompleto): ?>
    <!-- MODAL: COMPLETAR REGISTRO (cuentas creadas por OAuth) -->
    <div class="dash-modal-overlay active" id="modalCompletarRegistro">
        <div class="dash-modal-card" style="max-width:640px;">
            <div class="dash-modal-head">
                <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                    <span class="eyebrow">Completa tu registro</span>
                    <h2 style="margin:4px 0 0;">Un último paso, <?= htmlspecialchars($nombre) ?></h2>
                </div>
            </div>

            <p class="muted-text" style="text-align:center; margin-bottom:20px;">
                Tu cuenta se creó con Google o Microsoft, así que todavía faltan algunos datos que necesitamos para atenderte: cédula, fecha de nacimiento, teléfono y una contraseña para tu cuenta.
            </p>

            <?php if ($error_completar): ?>
                <div class="alert alert-error" style="margin-bottom:18px;"><?= htmlspecialchars($error_completar) ?></div>
            <?php endif; ?>

            <form action="inicio.php" method="POST">
                <input type="hidden" name="completar_registro" value="1">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="cr_cedula"><span class="dash-req">*</span> Cédula</label>
                        <input type="text" id="cr_cedula" name="cedula" class="form-control" required
                            maxlength="9" inputmode="numeric" pattern="[0-9]{9}" placeholder="123456789"
                            title="La cédula debe tener exactamente 9 dígitos numéricos, sin guiones ni espacios."
                            value="<?= htmlspecialchars($datos_completar['cedula'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="cr_telefono"><span class="dash-req">*</span> Teléfono</label>
                        <input type="text" id="cr_telefono" name="telefono" class="form-control" required
                            inputmode="numeric" pattern="[0-9]{8,15}" placeholder="88888888"
                            title="Solo números, sin espacios ni guiones."
                            value="<?= htmlspecialchars($datos_completar['telefono'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="cr_fecha_nacimiento"><span class="dash-req">*</span> Fecha de nacimiento</label>
                        <input type="date" id="cr_fecha_nacimiento" name="fecha_nacimiento" class="form-control" required
                            min="1876-01-01" max="<?= date('Y-m-d') ?>"
                            value="<?= htmlspecialchars($datos_completar['fecha_nacimiento'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="cr_direccion">Dirección <span class="dash-hint">(opcional)</span></label>
                        <input type="text" id="cr_direccion" name="direccion" class="form-control" placeholder="Dirección" maxlength="255"
                            value="<?= htmlspecialchars($datos_completar['direccion'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="cr_clave"><span class="dash-req">*</span> Contraseña</label>
                        <input type="password" id="cr_clave" name="clave" class="form-control" required
                            pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}"
                            title="La contraseña debe tener al menos 8 caracteres, incluir una mayúscula, una minúscula, un número y un carácter especial."
                            placeholder="••••••••">
                    </div>
                    <div class="form-group">
                        <label for="cr_clave_confirmar"><span class="dash-req">*</span> Confirmar contraseña</label>
                        <input type="password" id="cr_clave_confirmar" name="clave_confirmar" class="form-control" required
                            placeholder="••••••••">
                    </div>
                </div>

                <div class="form-group">
                    <label for="cr_antecedentes">Antecedentes médicos <span class="dash-hint">(opcional)</span></label>
                    <textarea id="cr_antecedentes" name="antecedentes_medicos" class="form-control" rows="2"><?= htmlspecialchars($datos_completar['antecedentes_medicos'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="cr_alergias">Alergias <span class="dash-hint">(opcional)</span></label>
                    <textarea id="cr_alergias" name="alergias" class="form-control" rows="2"><?= htmlspecialchars($datos_completar['alergias'] ?? '') ?></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary">Completar registro</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <main class="wrap page-shell">
            <section class="card" style="padding:40px; margin-bottom:32px;">
                <div style="display:flex; flex-wrap:wrap; justify-content:space-between; gap:24px; align-items:center;">
                    <div style="max-width:720px;">
                        <span class="eyebrow">Panel principal</span>
                        <h1>Hola, <?= htmlspecialchars($nombre) ?></h1>
                        <p class="lead" style="margin-top:16px; max-width:760px;">
                            Aquí tienes un resumen centralizado de tu atención dental: tus próximas citas, tu historial clínico reciente y el acceso rápido a tu perfil para actualizar tus datos.
                        </p>
                    </div>
                    <a href="perfil.php" class="btn-primary" style="white-space:nowrap;">Editar perfil</a>
                </div>
            </section>

            <section class="wrap" style="display:grid; gap:24px; margin-bottom:32px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
                <article class="card" style="padding:28px;">
                    <strong>Citas pendientes</strong>
                    <p style="font-size:2rem; margin:14px 0 0; color:var(--cyan);"><?= htmlspecialchars($counts['citas_pendientes'] ?? 0) ?></p>
                </article>
                <article class="card" style="padding:28px;">
                    <strong>Citas pasadas</strong>
                    <p style="font-size:2rem; margin:14px 0 0; color:var(--purple);"><?= htmlspecialchars($counts['citas_pasadas'] ?? 0) ?></p>
                </article>
                <article class="card" style="padding:28px;">
                    <strong>Entradas de historial</strong>
                    <p style="font-size:2rem; margin:14px 0 0; color:var(--lime);"><?= htmlspecialchars($counts['historial_total'] ?? 0) ?></p>
                </article>
            </section>

            <section class="wrap" style="display:grid; gap:24px; grid-template-columns:1.7fr 1fr; margin-bottom:32px;">
                <article class="card" style="padding:32px;">
                    <span class="eyebrow">Próxima cita</span>
                    <?php if ($next_appointment): ?>
                        <h2><?= htmlspecialchars($next_appointment['doctor_nombre'] . ' ' . $next_appointment['doctor_apellido']) ?></h2>
                        <p style="margin:12px 0 0; color:var(--muted);"><?= htmlspecialchars($next_appointment['motivo'] ?: 'Consulta dental') ?></p>
                        <div style="display:grid; gap:10px; margin-top:18px;">
                            <div><strong>Fecha y hora</strong><p><?= htmlspecialchars(format_date_time($next_appointment['fecha_hora'])) ?></p></div>
                            <div><strong>Estado</strong><p><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $next_appointment['estado']))) ?></p></div>
                        </div>
                        <a href="citas.php" class="btn-ghost" style="margin-top:22px; display:inline-block;">Ver todas las citas</a>
                    <?php else: ?>
                        <p>No tienes ninguna cita programada todavía.</p>
                        <a href="citas.php" class="btn-primary" style="margin-top:22px; display:inline-block;">Agendar cita</a>
                    <?php endif; ?>
                </article>

                <article class="card" style="padding:32px;">
                    <span class="eyebrow">Recomendaciones</span>
                    <ul style="margin-top:20px; display:grid; gap:16px; list-style:none; padding:0;">
                        <li>Asiste a tus citas programadas para mantener tu tratamiento al día.</li>
                        <li>Actualiza tus datos de contacto si cambiaste de teléfono o correo.</li>
                        <li>Revisa tu historial regularmente para tomar decisiones informadas sobre tu salud bucal.</li>
                    </ul>
                    <a href="perfil.php" class="btn-ghost" style="margin-top:22px; display:inline-block;">Actualizar datos</a>
                </article>
            </section>

            <section class="wrap" style="display:grid; gap:24px; grid-template-columns:1fr 1fr;">
                <article class="card" style="padding:32px;">
                    <span class="eyebrow">Próximas citas</span>
                    <?php if (count($upcoming_appointments) === 0): ?>
                        <p>No hay citas próximas programadas.</p>
                    <?php else: ?>
                        <div style="display:grid; gap:16px; margin-top:20px;">
                            <?php foreach ($upcoming_appointments as $cita): ?>
                                <div style="padding:18px; border:1px solid var(--line); border-radius:18px; background:var(--bg-panel-2);">
                                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                                        <strong><?= htmlspecialchars($cita['doctor_nombre'] . ' ' . $cita['doctor_apellido']) ?></strong>
                                        <span style="color:var(--cyan);"><?= htmlspecialchars(format_date_time($cita['fecha_hora'])) ?></span>
                                    </div>
                                    <p style="margin:10px 0 0; color:var(--muted);"><?= htmlspecialchars($cita['motivo'] ?: 'Consulta dental') ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="card" style="padding:32px;">
                    <span class="eyebrow">Historial reciente</span>
                    <?php if (count($recent_history) === 0): ?>
                        <p>No se encontraron entradas de historial recientes.</p>
                    <?php else: ?>
                        <div style="display:grid; gap:16px; margin-top:20px;">
                            <?php foreach ($recent_history as $entry): ?>
                                <div style="padding:18px; border:1px solid var(--line); border-radius:18px; background:var(--bg-panel-2);">
                                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                                        <strong><?= htmlspecialchars($entry['doctor_nombre'] . ' ' . $entry['doctor_apellido']) ?></strong>
                                        <span style="color:var(--muted);"><?= htmlspecialchars(format_date($entry['fecha_revision'])) ?></span>
                                    </div>
                                    <p style="margin:10px 0 0; color:var(--muted);">Diagnóstico: <?= htmlspecialchars($entry['diagnostico'] ?: 'Sin diagnóstico registrado') ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <a href="historial.php" class="btn-ghost" style="margin-top:22px; display:inline-block;">Ver historial completo</a>
                </article>
            </section>

            <section class="wrap" style="margin-top:24px;">
                <article class="card" style="padding:32px;">
                    <span class="eyebrow">Mis compras</span>
                    <?php if (count($compras_recientes) === 0): ?>
                        <p style="margin-top:12px;">Aún no tienes compras registradas.</p>
                    <?php else: ?>
                        <div style="display:grid; gap:16px; margin-top:20px;">
                            <?php foreach ($compras_recientes as $compra): ?>
                                <a href="ver_factura.php?id=<?= (int) $compra['id_venta'] ?>" style="padding:18px; border:1px solid var(--line); border-radius:18px; background:var(--bg-panel-2); display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; text-decoration:none; color:inherit;">
                                    <div>
                                        <strong>Compra #<?= (int) $compra['id_venta'] ?></strong>
                                        <p style="margin:6px 0 0; color:var(--muted);"><?= htmlspecialchars(format_date_time($compra['fecha_venta'])) ?></p>
                                    </div>
                                    <div style="text-align:right;">
                                        <span style="color:var(--cyan); font-weight:600;">₡<?= number_format((float) $compra['total'], 2) ?></span>
                                        <?php $badge_venta = ['pendiente' => 'badge-pendiente', 'pagada' => 'badge-success', 'anulada' => 'badge-cancelada'][$compra['estado']] ?? 'badge-pendiente'; ?>
                                        <p style="margin:6px 0 0;"><span class="badge <?= $badge_venta ?>"><?= ucfirst($compra['estado']) ?></span></p>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <a href="tienda.php" class="btn-ghost" style="margin-top:22px; display:inline-block;">Ir a la tienda</a>
                </article>
            </section>
    </main>

    <?php require_once 'assets/includes/partials/footer.php'; ?>

</body>

</html>