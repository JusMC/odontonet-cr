<?php

require_once __DIR__ . '/assets/includes/session_boot.php';
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/bitacora_helper.php';
require_once './assets/includes/helpers/archivo_helper.php';
require_once './assets/includes/config/mfa_config.php';
require_once './assets/includes/helpers/mfa_helper.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php?redirect=perfil.php');
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
$nombre_sesion = $_SESSION['nombre'] ?? 'Paciente';
$message = '';
$message_type = '';
$campos_con_error = [];

$mfa_codigos_respaldo_mostrar = null;
$accion = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? 'guardar_perfil';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'guardar_perfil') {
    $nombre_actualizado = trim($_POST['nombre'] ?? '');
    $apellido_actualizado = trim($_POST['apellido'] ?? '');
    $correo_actualizado = trim($_POST['correo'] ?? '');
    $telefono_actualizado = trim($_POST['telefono'] ?? '');
    $cedula_actualizada = trim($_POST['cedula'] ?? '');
    $fecha_nacimiento_actualizada = trim($_POST['fecha_nacimiento'] ?? '');
    $direccion_actualizada = trim($_POST['direccion'] ?? '');
    $contrasena_nueva = trim($_POST['contrasena_nueva'] ?? '');
    $contrasena_confirmar = trim($_POST['contrasena_confirmar'] ?? '');
    $antecedentes_actualizados = trim($_POST['antecedentes_medicos'] ?? '');
    $alergias_actualizadas = trim($_POST['alergias'] ?? '');
    $quitar_foto = ($_POST['quitar_foto'] ?? '') === '1';

    // $campos_con_error: nombres de campo a resaltar en rojo en el formulario
    // (se llena en cada rama de validación que falle, para que el input
    // culpable quede marcado además del mensaje del toast).
    $campos_con_error = [];

    $campos_obligatorios = [
        'nombre'           => $nombre_actualizado,
        'apellido'         => $apellido_actualizado,
        'correo'           => $correo_actualizado,
        'cedula'           => $cedula_actualizada,
        'fecha_nacimiento' => $fecha_nacimiento_actualizada,
        'telefono'         => $telefono_actualizado,
        'direccion'        => $direccion_actualizada,
    ];
    foreach ($campos_obligatorios as $campo => $valor) {
        if ($valor === '') {
            $campos_con_error[] = $campo;
        }
    }

    if (!empty($campos_con_error)) {
        $message = 'Completa los campos obligatorios antes de guardar los cambios.';
        $message_type = 'error';
    } else {

        // --- Validaciones de formato ---
        $regex_nombre = '/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]{2,50}$/';
        $errores_validacion = [];

        if (!preg_match($regex_nombre, $nombre_actualizado)) {
            $errores_validacion[] = 'El nombre solo debe contener letras y máximo 50 caracteres.';
            $campos_con_error[] = 'nombre';
        }
        if (!preg_match($regex_nombre, $apellido_actualizado)) {
            $errores_validacion[] = 'El apellido solo debe contener letras y máximo 50 caracteres.';
            $campos_con_error[] = 'apellido';
        }
        if (!preg_match('/^[0-9]{9}$/', $cedula_actualizada)) {
            $errores_validacion[] = 'La cédula debe contener exactamente 9 dígitos numéricos.';
            $campos_con_error[] = 'cedula';
        }
        if ($telefono_actualizado !== '' && !preg_match('/^[0-9]{8,15}$/', $telefono_actualizado)) {
            $errores_validacion[] = 'El teléfono solo debe contener números, sin espacios ni guiones.';
            $campos_con_error[] = 'telefono';
        }
        if (!filter_var($correo_actualizado, FILTER_VALIDATE_EMAIL)) {
            $errores_validacion[] = 'Ingresa un correo electrónico válido.';
            $campos_con_error[] = 'correo';
        } elseif (mb_strlen($correo_actualizado) > 150) {
            $errores_validacion[] = 'El correo electrónico no puede exceder 150 caracteres.';
            $campos_con_error[] = 'correo';
        }
        if (mb_strlen($direccion_actualizada) > 255) {
            $errores_validacion[] = 'La dirección no puede exceder 255 caracteres.';
            $campos_con_error[] = 'direccion';
        }

        $fecha_min = new DateTime('1876-01-01');
        $fecha_max = new DateTime();
        $fecha_dt  = DateTime::createFromFormat('Y-m-d', $fecha_nacimiento_actualizada);
        if (!$fecha_dt || $fecha_dt < $fecha_min || $fecha_dt > $fecha_max) {
            $errores_validacion[] = 'La fecha de nacimiento no es válida. Debe estar entre 1876 y la fecha actual.';
            $campos_con_error[] = 'fecha_nacimiento';
        }

        // --- Foto de perfil (mismo patrón de validación que inventario.php) ---
        $foto_subida_valida = false;
        $extension_foto = '';
        $extensiones_foto_validas = ['jpg', 'jpeg', 'png'];
        $mimes_foto_validos = ['image/jpeg', 'image/png'];

        if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] !== UPLOAD_ERR_NO_FILE) {
            if (in_array($_FILES['foto_perfil']['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $errores_validacion[] = 'La foto de perfil es demasiado grande.';
                $campos_con_error[] = 'foto_perfil';
            } elseif ($_FILES['foto_perfil']['error'] !== UPLOAD_ERR_OK) {
                $errores_validacion[] = 'Ocurrió un error al subir la foto. Intenta de nuevo.';
                $campos_con_error[] = 'foto_perfil';
            } else {
                $extension_foto = strtolower(pathinfo($_FILES['foto_perfil']['name'], PATHINFO_EXTENSION));

                if (!in_array($extension_foto, $extensiones_foto_validas, true)) {
                    $errores_validacion[] = 'La foto de perfil debe ser JPG, JPEG o PNG.';
                    $campos_con_error[] = 'foto_perfil';
                } elseif ($_FILES['foto_perfil']['size'] > 3 * 1024 * 1024) {
                    $errores_validacion[] = 'La foto de perfil no puede superar los 3 MB.';
                    $campos_con_error[] = 'foto_perfil';
                } else {
                    $info_foto = @getimagesize($_FILES['foto_perfil']['tmp_name']);
                    if ($info_foto === false || !in_array($info_foto['mime'], $mimes_foto_validos, true)) {
                        $errores_validacion[] = 'El archivo de la foto de perfil no es una imagen JPG/PNG válida.';
                        $campos_con_error[] = 'foto_perfil';
                    } else {
                        $foto_subida_valida = true;
                    }
                }
            }
        }

        // --- Duplicados: correo y cédula (excluyendo al propio usuario) ---
        if (empty($errores_validacion)) {
            $stmt_dup = $pdo->prepare('SELECT correo, cedula FROM usuarios WHERE (correo = :correo OR cedula = :cedula) AND id_usuario != :id LIMIT 1');
            $stmt_dup->execute(['correo' => $correo_actualizado, 'cedula' => $cedula_actualizada, 'id' => $usuario_id]);
            $existente = $stmt_dup->fetch();

            if ($existente) {
                if ($existente['correo'] === $correo_actualizado) {
                    $errores_validacion[] = 'Ese correo electrónico ya está en uso por otra cuenta.';
                    $campos_con_error[] = 'correo';
                } else {
                    $errores_validacion[] = 'Esa cédula ya está registrada en otra cuenta.';
                    $campos_con_error[] = 'cedula';
                }
            }
        }

        if (!empty($errores_validacion)) {
            $message = implode(' ', $errores_validacion);
            $message_type = 'error';
        } else {
            try {

                $pdo->beginTransaction();

                // Validar contraseña si se quiere cambiar
                $nueva_contrasena_hash = null;
                if ($contrasena_nueva !== '') {
                    if (strlen($contrasena_nueva) < 8) {
                        throw new Exception('La contraseña debe tener al menos 8 caracteres.');
                    }
                    if ($contrasena_nueva !== $contrasena_confirmar) {
                        throw new Exception('Las contraseñas no coinciden.');
                    }

                    // La cuenta puede no tener contraseña todavía (creada por OAuth), en
                    // cuyo caso no hay "contraseña actual" con la que comparar.
                    $stmt_actual = $pdo->prepare('SELECT contrasena FROM usuarios WHERE id_usuario = :id');
                    $stmt_actual->execute(['id' => $usuario_id]);
                    $hash_actual = $stmt_actual->fetchColumn();

                    if (!empty($hash_actual) && password_verify($contrasena_nueva, $hash_actual)) {
                        throw new Exception('La nueva contraseña no puede ser igual a la actual.');
                    }

                    $nueva_contrasena_hash = password_hash($contrasena_nueva, PASSWORD_BCRYPT);
                }

                // Foto de perfil: se guarda en disco solo ahora que el resto del
                // formulario ya es válido (mismo patrón que inventario.php). Una
                // foto nueva tiene prioridad sobre "quitar foto" si llegaran ambas.
                $stmt_foto_actual = $pdo->prepare('SELECT foto_perfil FROM usuarios WHERE id_usuario = :id');
                $stmt_foto_actual->execute(['id' => $usuario_id]);
                $foto_actual = $stmt_foto_actual->fetchColumn();

                $debe_actualizar_foto = false;
                $foto_valor_nuevo = null;

                if ($foto_subida_valida) {
                    $nombre_archivo_foto = 'perfil_' . uniqid() . '.' . $extension_foto;
                    $foto_valor_nuevo = guardar_archivo_subido($_FILES['foto_perfil']['tmp_name'], 'assets/images/perfiles', $nombre_archivo_foto, $info_foto['mime']);
                    if ($foto_valor_nuevo === null) {
                        throw new Exception('No se pudo guardar la foto de perfil en el servidor.');
                    }
                    $debe_actualizar_foto = true;
                } elseif ($quitar_foto && !empty($foto_actual)) {
                    $foto_valor_nuevo = null;
                    $debe_actualizar_foto = true;
                }

                if ($debe_actualizar_foto && !empty($foto_actual)) {
                    // Se reemplaza o se quita: la foto anterior deja de usarse, se borra.
                    eliminar_archivo_guardado($foto_actual);
                }

                // Construir la query de forma dinámica: solo se incluyen
                // contraseña y/o foto si realmente cambiaron.
                $set_clauses = 'nombre = :nombre, apellido = :apellido, cedula = :cedula,
                    fecha_nacimiento = :fecha_nacimiento, direccion = :direccion,
                    correo = :correo, telefono = :telefono';
                $params = [
                    'nombre'           => $nombre_actualizado,
                    'apellido'         => $apellido_actualizado,
                    'cedula'           => $cedula_actualizada,
                    'fecha_nacimiento' => $fecha_nacimiento_actualizada,
                    'direccion'        => $direccion_actualizada !== '' ? $direccion_actualizada : null,
                    'correo'           => $correo_actualizado,
                    'telefono'         => $telefono_actualizado !== '' ? $telefono_actualizado : null,
                    'id_usuario'       => $usuario_id,
                ];

                if ($nueva_contrasena_hash !== null) {
                    $set_clauses .= ', contrasena = :contrasena';
                    $params['contrasena'] = $nueva_contrasena_hash;
                }
                if ($debe_actualizar_foto) {
                    $set_clauses .= ', foto_perfil = :foto_perfil';
                    $params['foto_perfil'] = $foto_valor_nuevo;
                }

                $stmtUpdateUser = $pdo->prepare("UPDATE usuarios SET $set_clauses WHERE id_usuario = :id_usuario");
                $stmtUpdateUser->execute($params);

                $stmtUpdatePaciente = $pdo->prepare('UPDATE pacientes SET antecedentes_medicos = :antecedentes, alergias = :alergias WHERE id_paciente = :id_paciente');
                $stmtUpdatePaciente->execute([
                    'antecedentes' => $antecedentes_actualizados !== '' ? $antecedentes_actualizados : null,
                    'alergias'     => $alergias_actualizadas !== '' ? $alergias_actualizadas : null,
                    'id_paciente'  => $paciente_id,
                ]);

                $pdo->commit();

                $detalle_clave = $nueva_contrasena_hash !== null ? ' Se cambió la contraseña.' : '';
                registrar_bitacora($pdo, 'UPDATE', "El usuario #$usuario_id ($nombre_actualizado $apellido_actualizado) actualizó su perfil.$detalle_clave");

                $_SESSION['nombre'] = $nombre_actualizado;
                $_SESSION['apellido'] = $apellido_actualizado;
                if ($debe_actualizar_foto) {
                    $_SESSION['foto_perfil'] = $foto_valor_nuevo;
                }
                $nombre_sesion = $nombre_actualizado;
                $message = 'Tus datos se actualizaron correctamente.';
                $message_type = 'success';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($e instanceof \PDOException) {
                    // Solo las fallas reales de base de datos se auditan aquí; los
                    // demás "throw" de este bloque son validaciones esperadas
                    // (contraseña, foto) que ya se le muestran al usuario.
                    registrar_bitacora($pdo, 'ERROR', "Falló actualizar el perfil del usuario #$usuario_id: " . $e->getMessage());
                }
                if (
                    strpos($e->getMessage(), 'correo') !== false ||
                    strpos($e->getMessage(), 'Duplicate') !== false ||
                    strpos($e->getMessage(), 'unique') !== false
                ) {
                    $message = 'El correo o la cédula ya están registrados. Usa otros datos o contacta a soporte.';
                    $campos_con_error = ['correo', 'cedula'];
                } elseif (strpos($e->getMessage(), 'foto de perfil') !== false) {
                    $message = $e->getMessage();
                    $campos_con_error = ['foto_perfil'];
                } else {
                    $message = $e->getMessage();
                    $campos_con_error = ['contrasena_nueva', 'contrasena_confirmar'];
                }
                $message_type = 'error';
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'mfa_generar') {
    // Qué app dijo la persona que va a usar — es solo informativo (el
    // protocolo TOTP no permite saber de verdad qué app escaneó el QR).
    $apps_mfa_validas = ['Google Authenticator', 'Microsoft Authenticator', 'Otra app autenticadora'];
    $app_nombre_mfa = in_array($_POST['app_nombre'] ?? '', $apps_mfa_validas, true) ? $_POST['app_nombre'] : 'Otra app autenticadora';

    // Se borra cualquier secreto pendiente sin confirmar previo, para no
    // acumular filas huérfanas si la persona pide el QR varias veces.
    $pdo->prepare('DELETE FROM mfa_totp WHERE id_usuario = :id AND confirmado = 0')->execute(['id' => $usuario_id]);

    $stmt_correo_mfa = $pdo->prepare('SELECT correo FROM usuarios WHERE id_usuario = :id');
    $stmt_correo_mfa->execute(['id' => $usuario_id]);
    $correo_para_mfa = $stmt_correo_mfa->fetchColumn();

    $nuevo_totp = mfa_generar_totp_nuevo($correo_para_mfa);
    $pdo->prepare('INSERT INTO mfa_totp (id_usuario, secreto_cifrado, app_nombre, confirmado) VALUES (:id, :secreto, :app, 0)')
        ->execute(['id' => $usuario_id, 'secreto' => mfa_cifrar_secreto($nuevo_totp['secreto']), 'app' => $app_nombre_mfa]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'mfa_confirmar') {
    $codigo_confirmacion_mfa = trim($_POST['mfa_codigo'] ?? '');

    $stmt_pend = $pdo->prepare('SELECT id_mfa, secreto_cifrado, app_nombre FROM mfa_totp WHERE id_usuario = :id AND confirmado = 0 LIMIT 1');
    $stmt_pend->execute(['id' => $usuario_id]);
    $pendiente_mfa = $stmt_pend->fetch();

    if (!$pendiente_mfa) {
        $message = 'No hay una activación de verificación en dos pasos pendiente. Genera un nuevo código QR.';
        $message_type = 'error';
    } elseif (!mfa_verificar_codigo(mfa_descifrar_secreto($pendiente_mfa['secreto_cifrado']), $codigo_confirmacion_mfa)) {
        $message = 'El código no es correcto. Verifica la hora de tu teléfono e inténtalo de nuevo.';
        $message_type = 'error';
    } else {
        $pdo->prepare('UPDATE mfa_totp SET confirmado = 1, fecha_confirmacion = NOW() WHERE id_mfa = :id')
            ->execute(['id' => $pendiente_mfa['id_mfa']]);

        // Los códigos de respaldo solo se generan la primera vez: si el
        // usuario ya tenía otra app activa con códigos vigentes, agregar
        // una app más no los invalida ni hace falta volver a mostrarlos.
        $stmt_hay_respaldo = $pdo->prepare('SELECT COUNT(*) FROM mfa_codigos_respaldo WHERE id_usuario = :id AND usado = 0');
        $stmt_hay_respaldo->execute(['id' => $usuario_id]);
        $tiene_respaldo_vigente = (int) $stmt_hay_respaldo->fetchColumn() > 0;

        if (!$tiene_respaldo_vigente) {
            $codigos_generados = mfa_generar_codigos_respaldo(8);
            $stmt_ins_respaldo = $pdo->prepare('INSERT INTO mfa_codigos_respaldo (id_usuario, codigo_hash) VALUES (:id, :hash)');
            foreach ($codigos_generados as $codigo_respaldo) {
                $stmt_ins_respaldo->execute(['id' => $usuario_id, 'hash' => password_hash($codigo_respaldo, PASSWORD_BCRYPT)]);
            }
            $mfa_codigos_respaldo_mostrar = $codigos_generados;
        }

        registrar_bitacora($pdo, 'UPDATE', "El usuario #$usuario_id activó la verificación en dos pasos con {$pendiente_mfa['app_nombre']}.", $usuario_id);

        $message = 'Verificación en dos pasos activada correctamente.';
        $message_type = 'success';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'mfa_cancelar_generacion') {
    $pdo->prepare('DELETE FROM mfa_totp WHERE id_usuario = :id AND confirmado = 0')->execute(['id' => $usuario_id]);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'mfa_eliminar_metodo') {
    $id_mfa_eliminar = (int) ($_POST['id_mfa'] ?? 0);

    $stmt_del_metodo = $pdo->prepare('DELETE FROM mfa_totp WHERE id_mfa = :id_mfa AND id_usuario = :id_usuario AND confirmado = 1');
    $stmt_del_metodo->execute(['id_mfa' => $id_mfa_eliminar, 'id_usuario' => $usuario_id]);

    if ($stmt_del_metodo->rowCount() > 0) {
        // Si ya no le queda ninguna app activa, los códigos de respaldo
        // tampoco tienen sentido — se limpian junto con el último método.
        if (!mfa_esta_activo($pdo, $usuario_id)) {
            $pdo->prepare('DELETE FROM mfa_codigos_respaldo WHERE id_usuario = :id')->execute(['id' => $usuario_id]);
        }

        registrar_bitacora($pdo, 'UPDATE', "El usuario #$usuario_id eliminó un método de verificación en dos pasos (id_mfa=$id_mfa_eliminar).", $usuario_id);
        $message = 'Método de verificación eliminado.';
        $message_type = 'success';
    } else {
        $message = 'No se encontró ese método de verificación.';
        $message_type = 'error';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'mfa_renombrar_app') {
    $id_mfa_renombrar = (int) ($_POST['id_mfa'] ?? 0);
    $nuevo_nombre_mfa = trim($_POST['nuevo_nombre'] ?? '');

    if ($nuevo_nombre_mfa === '' || mb_strlen($nuevo_nombre_mfa) > 50) {
        $message = 'Ingresa un nombre válido (máximo 50 caracteres).';
        $message_type = 'error';
    } else {
        $stmt_renombrar = $pdo->prepare('UPDATE mfa_totp SET app_nombre = :nombre WHERE id_mfa = :id_mfa AND id_usuario = :id_usuario AND confirmado = 1');
        $stmt_renombrar->execute(['nombre' => $nuevo_nombre_mfa, 'id_mfa' => $id_mfa_renombrar, 'id_usuario' => $usuario_id]);

        if ($stmt_renombrar->rowCount() > 0) {
            registrar_bitacora($pdo, 'UPDATE', "El usuario #$usuario_id renombró un método de verificación en dos pasos (id_mfa=$id_mfa_renombrar) a \"$nuevo_nombre_mfa\".", $usuario_id);
            $message = 'Nombre actualizado.';
            $message_type = 'success';
        } else {
            $message = 'No se encontró ese método de verificación.';
            $message_type = 'error';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'mfa_regenerar_respaldo') {
    if (!mfa_esta_activo($pdo, $usuario_id)) {
        $message = 'Primero debes activar la verificación en dos pasos.';
        $message_type = 'error';
    } else {
        $pdo->prepare('DELETE FROM mfa_codigos_respaldo WHERE id_usuario = :id')->execute(['id' => $usuario_id]);
        $codigos_generados = mfa_generar_codigos_respaldo(8);
        $stmt_ins_respaldo = $pdo->prepare('INSERT INTO mfa_codigos_respaldo (id_usuario, codigo_hash) VALUES (:id, :hash)');
        foreach ($codigos_generados as $codigo_respaldo) {
            $stmt_ins_respaldo->execute(['id' => $usuario_id, 'hash' => password_hash($codigo_respaldo, PASSWORD_BCRYPT)]);
        }
        registrar_bitacora($pdo, 'UPDATE', "El usuario #$usuario_id regeneró sus códigos de respaldo de MFA.", $usuario_id);
        $mfa_codigos_respaldo_mostrar = $codigos_generados;
        $message = 'Se generaron nuevos códigos de respaldo. Los anteriores ya no funcionan.';
        $message_type = 'success';
    }
}

$stmt = $pdo->prepare('SELECT u.nombre, u.apellido, u.correo, u.telefono, u.fecha_creacion,
    u.cedula, u.fecha_nacimiento, u.direccion, u.contrasena, u.foto_perfil,
    p.antecedentes_medicos, p.alergias
    FROM usuarios u
    INNER JOIN pacientes p ON p.id_usuario = u.id_usuario
    WHERE u.id_usuario = :id_usuario
    LIMIT 1');

$stmt->execute(['id_usuario' => $usuario_id]);

$usuario = $stmt->fetch();

if (!$usuario) {
    header('Location: login.php?redirect=perfil.php');
    exit;
}

// Estado de la verificación en dos pasos, para dibujar la sección "Seguridad".
// Un usuario puede tener varias apps confirmadas a la vez, pero como mucho
// un enrolamiento pendiente de confirmar en un momento dado.
$stmt_mfa = $pdo->prepare('SELECT id_mfa, secreto_cifrado, app_nombre, confirmado, fecha_confirmacion FROM mfa_totp WHERE id_usuario = :id ORDER BY fecha_creacion ASC');
$stmt_mfa->execute(['id' => $usuario_id]);
$mfa_todos = $stmt_mfa->fetchAll();

$mfa_apps_activas = array_values(array_filter($mfa_todos, fn($fila) => (int) $fila['confirmado'] === 1));
$mfa_registro_pendiente = null;
foreach ($mfa_todos as $fila) {
    if ((int) $fila['confirmado'] === 0) {
        $mfa_registro_pendiente = $fila;
        break;
    }
}
$mfa_hay_pendiente = $mfa_registro_pendiente !== null;
$mfa_activo = count($mfa_apps_activas) > 0; // al menos una app confirmada

// Íconos por app (solo Google/Microsoft Authenticator tienen ícono propio
// agregado en assets/images/icons/; "Otra app autenticadora" no muestra ninguno).
$mfa_iconos = [
    'Google Authenticator'    => 'assets/images/icons/google-authenticator.png',
    'Microsoft Authenticator' => 'assets/images/icons/microsoft-authenticator.png',
];

// Si hay una activación pendiente de confirmar (recién generada o tras
// recargar la página sin haberla confirmado todavía), se reconstruye el QR.
$mfa_uri_qr = '';
$mfa_secreto_manual = '';
if ($mfa_hay_pendiente) {
    $mfa_secreto_manual = mfa_descifrar_secreto($mfa_registro_pendiente['secreto_cifrado']);
    $mfa_uri_qr = mfa_uri_desde_secreto($mfa_secreto_manual, $usuario['correo']);
}

function format_date($value) {
    if (!$value) {
        return 'No disponible';
    }
    $date = new DateTime($value);
    return $date->format('d/m/Y');
}

function format_date_value($value) {
    if (!$value) {
        return '';
    }
    $date = new DateTime($value);
    return $date->format('Y-m-d');
}

// "Paciente desde marzo 2024" — se arma el nombre del mes a mano en vez de
// depender de la extensión intl (no siempre está habilitada).
function format_mes_anio($value) {
    if (!$value) {
        return '';
    }
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $date = new DateTime($value);
    return $meses[(int) $date->format('n') - 1] . ' ' . $date->format('Y');
}

// Valores a precargar en el formulario de edición: si el POST falló la
// validación, se reusan los datos que la persona ya había escrito (para no
// hacerle repetir todo el formulario); si no, se parte de lo que hay en BD.
$form_values = [
    'nombre'               => $usuario['nombre'],
    'apellido'             => $usuario['apellido'],
    'cedula'               => $usuario['cedula'] ?? '',
    'fecha_nacimiento'     => format_date_value($usuario['fecha_nacimiento']),
    'direccion'            => $usuario['direccion'] ?: '',
    'correo'               => $usuario['correo'],
    'telefono'             => $usuario['telefono'] ?: '',
    'antecedentes_medicos' => $usuario['antecedentes_medicos'] ?? '',
    'alergias'             => $usuario['alergias'] ?? '',
];
function campo_clase(string $campo, array $campos_con_error): string {
    return in_array($campo, $campos_con_error, true) ? 'field field-error' : 'field';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'guardar_perfil' && $message_type === 'error') {
    $form_values = [
        'nombre'               => $nombre_actualizado,
        'apellido'             => $apellido_actualizado,
        'cedula'               => $cedula_actualizada,
        'fecha_nacimiento'     => $fecha_nacimiento_actualizada,
        'direccion'            => $direccion_actualizada,
        'correo'               => $correo_actualizado,
        'telefono'             => $telefono_actualizado,
        'antecedentes_medicos' => $antecedentes_actualizados,
        'alergias'             => $alergias_actualizadas,
    ];
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OdontoNet — Perfil</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/toast.css">
    <link rel="stylesheet" href="assets/css/pages/perfil.css">
</head>

<body>

    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>
    <div class="glow glow-c"></div>
    <div class="grid-overlay"></div>

    <!-- NAVBAR -->
    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <main class="wrap page-shell perfil-shell">

        <?php $iniciales = mb_strtoupper(mb_substr($usuario['nombre'], 0, 1) . mb_substr($usuario['apellido'], 0, 1)); ?>

        <h1 class="perfil-titulo">Mi Perfil</h1>

        <div class="perfil-card-glow">
        <section class="card perfil-card">

            <form method="post" action="perfil.php" id="formPerfil" enctype="multipart/form-data">

                <div class="perfil-header">
                    <div class="perfil-identidad">
                        <div class="perfil-avatar-wrap">
                            <div class="perfil-avatar" data-iniciales="<?= htmlspecialchars($iniciales) ?>">
                                <?php if (!empty($usuario['foto_perfil'])): ?>
                                    <img src="<?= htmlspecialchars($usuario['foto_perfil']) ?>" alt="Foto de perfil" id="perfilAvatarImg">
                                <?php else: ?>
                                    <span id="perfilAvatarIniciales"><?= htmlspecialchars($iniciales) ?></span>
                                <?php endif; ?>
                            </div>
                            <label for="foto_perfil" class="perfil-avatar-editar" title="Cambiar foto de perfil">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                                    <circle cx="12" cy="13" r="4"/>
                                </svg>
                            </label>
                            <input type="file" id="foto_perfil" name="foto_perfil" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="perfil-avatar-input">
                        </div>
                        <div class="perfil-meta">
                            <h2><?= htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido']) ?></h2>
                            <p class="perfil-desde">Paciente desde <?= htmlspecialchars(format_mes_anio($usuario['fecha_creacion'])) ?> · <?= htmlspecialchars($usuario['correo']) ?></p>
                            <input type="hidden" name="quitar_foto" id="quitarFotoCheckbox" value="">
                            <?php if (!empty($usuario['foto_perfil'])): ?>
                                <button type="button" class="perfil-quitar-foto" onclick="abrirModalEliminarFoto()"><span>Eliminar foto de perfil</span></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="perfil-acciones-top">
                        <a href="citas.php" class="btn-ghost">Agendar cita</a>
                        <a href="historial.php" class="btn-ghost">Ver historial</a>
                        <button type="submit" class="btn-primary perfil-btn-guardar">Guardar cambios</button>
                    </div>
                </div>

                <div class="perfil-seccion">
                    <h2>Datos personales</h2>
                    <p class="perfil-seccion-hint">Tu información básica de contacto e identificación.</p>
                    <div class="perfil-grid">
                        <div class="<?= campo_clase('nombre', $campos_con_error) ?>">
                            <label for="nombre"><span class="dash-req">*</span> Nombre</label>
                            <input type="text" id="nombre" name="nombre" value="<?= htmlspecialchars($form_values['nombre']) ?>" required
                                maxlength="50" pattern="[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+" title="Solo letras y espacios (2 a 50 caracteres).">
                        </div>
                        <div class="<?= campo_clase('apellido', $campos_con_error) ?>">
                            <label for="apellido"><span class="dash-req">*</span> Apellido</label>
                            <input type="text" id="apellido" name="apellido" value="<?= htmlspecialchars($form_values['apellido']) ?>" required
                                maxlength="50" pattern="[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+" title="Solo letras y espacios (2 a 50 caracteres).">
                        </div>
                        <div class="<?= campo_clase('cedula', $campos_con_error) ?>">
                            <label for="cedula"><span class="dash-req">*</span> Cédula</label>
                            <input type="text" id="cedula" name="cedula" value="<?= htmlspecialchars($form_values['cedula']) ?>" required
                                maxlength="9" pattern="[0-9]{9}" inputmode="numeric" title="Debe tener exactamente 9 dígitos numéricos, sin guiones ni espacios.">
                        </div>
                        <div class="<?= campo_clase('telefono', $campos_con_error) ?>">
                            <label for="telefono"><span class="dash-req">*</span> Teléfono</label>
                            <input type="tel" id="telefono" name="telefono" value="<?= htmlspecialchars($form_values['telefono']) ?>" required
                                pattern="[0-9]{8,15}" inputmode="numeric" title="Solo números, sin espacios ni guiones (8 a 15 dígitos).">
                        </div>
                        <div class="<?= campo_clase('fecha_nacimiento', $campos_con_error) ?>">
                            <label for="fecha_nacimiento"><span class="dash-req">*</span> Fecha de nacimiento</label>
                            <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" value="<?= htmlspecialchars($form_values['fecha_nacimiento']) ?>" required
                                min="1876-01-01" max="<?= date('Y-m-d') ?>" title="Debe ser una fecha entre 1876 y hoy.">
                        </div>
                        <div class="<?= campo_clase('correo', $campos_con_error) ?>">
                            <label for="correo"><span class="dash-req">*</span> Correo</label>
                            <input type="email" id="correo" name="correo" value="<?= htmlspecialchars($form_values['correo']) ?>" required maxlength="150" title="Debe ser un correo electrónico válido.">
                        </div>
                        <div class="<?= campo_clase('direccion', $campos_con_error) ?> perfil-campo-full">
                            <label for="direccion"><span class="dash-req">*</span> Dirección</label>
                            <input type="text" id="direccion" name="direccion" value="<?= htmlspecialchars($form_values['direccion']) ?>" required maxlength="255" title="Completa este campo.">
                        </div>
                    </div>
                </div>

                <div class="perfil-seccion">
                    <h2>Información clínica</h2>
                    <p class="perfil-seccion-hint">Tu odontólogo revisa esto antes de cada consulta — mantenla actualizada.</p>
                    <div class="perfil-grid">
                        <div class="field">
                            <label for="antecedentes_medicos">Antecedentes médicos <span class="dash-hint">(opcional)</span></label>
                            <textarea id="antecedentes_medicos" name="antecedentes_medicos" rows="3" placeholder="Ej: Hipertensión, diabetes, cirugías previas..."><?= htmlspecialchars($form_values['antecedentes_medicos']) ?></textarea>
                        </div>
                        <div class="field">
                            <label for="alergias">Alergias <span class="dash-hint">(opcional)</span></label>
                            <textarea id="alergias" name="alergias" rows="3" placeholder="Ej: Penicilina, látex..."><?= htmlspecialchars($form_values['alergias']) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="perfil-seccion">
                    <h2>Seguridad</h2>
                    <p class="perfil-seccion-hint">
                        <?php if (is_null($usuario['contrasena'])): ?>
                            <span style="color:var(--cyan);">Tu cuenta fue creada con Google o Microsoft. Puedes establecer una contraseña para también poder iniciar sesión con tu correo.</span>
                        <?php else: ?>
                            Deja estos campos en blanco si no deseas cambiar tu contraseña.
                        <?php endif; ?>
                    </p>
                    <div class="perfil-grid">
                        <div class="<?= campo_clase('contrasena_nueva', $campos_con_error) ?>">
                            <label for="contrasena_nueva"><?= is_null($usuario['contrasena']) ? 'Establecer contraseña' : 'Nueva contraseña' ?></label>
                            <input type="password" id="contrasena_nueva" name="contrasena_nueva"
                                placeholder="Mínimo 8 caracteres"
                                autocomplete="new-password">
                        </div>
                        <div class="<?= campo_clase('contrasena_confirmar', $campos_con_error) ?>">
                            <label for="contrasena_confirmar">Confirmar contraseña</label>
                            <input type="password" id="contrasena_confirmar" name="contrasena_confirmar"
                                placeholder="Repite la contraseña"
                                autocomplete="new-password">
                        </div>
                    </div>
                </div>

            </form>

            <!-- Verificación en dos pasos: fuera de #formPerfil (no puede haber un
                 <form> dentro de otro <form>), con su propio mini-formulario por acción. -->
            <div class="perfil-seccion">
                <h2>Verificación en dos pasos</h2>

                <?php if ($mfa_activo): ?>

                    <p class="perfil-seccion-hint">Se acepta el código de cualquiera de estas apps al iniciar sesión — no hace falta indicar cuál vas a usar.</p>

                    <div class="perfil-mfa-lista">
                        <?php foreach ($mfa_apps_activas as $mfa_app): ?>
                            <?php $mfa_app_icono = $mfa_iconos[$mfa_app['app_nombre']] ?? null; ?>
                            <div class="perfil-mfa-item">
                                <div class="perfil-mfa-header">
                                    <?php if ($mfa_app_icono): ?>
                                        <img src="<?= htmlspecialchars($mfa_app_icono) ?>" alt="" class="perfil-mfa-icono-app">
                                    <?php endif; ?>
                                    <div class="perfil-mfa-header-texto">
                                        <span class="perfil-mfa-nombre"><?= htmlspecialchars($mfa_app['app_nombre'] ?: 'App autenticadora') ?></span>
                                        <span class="perfil-mfa-fecha">Agregada el <?= htmlspecialchars(format_date($mfa_app['fecha_confirmacion'])) ?></span>
                                    </div>
                                </div>
                                <div class="perfil-mfa-item-acciones">
                                    <?php if (!$mfa_app_icono): ?>
                                        <button type="button" class="btn-ghost perfil-mfa-btn-chico" onclick="abrirModalRenombrarMfa(<?= (int) $mfa_app['id_mfa'] ?>, '<?= htmlspecialchars(addslashes($mfa_app['app_nombre'] ?? ''), ENT_QUOTES) ?>')">Renombrar</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn-ghost perfil-mfa-btn-chico perfil-btn-desactivar-mfa" onclick="abrirModalEliminarMetodoMfa(<?= (int) $mfa_app['id_mfa'] ?>, '<?= htmlspecialchars(addslashes($mfa_app['app_nombre'] ?: 'esta app'), ENT_QUOTES) ?>')">Eliminar</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <form method="post" action="perfil.php">
                            <input type="hidden" name="accion" value="mfa_regenerar_respaldo">
                            <button type="submit" class="btn-ghost">Regenerar códigos de respaldo</button>
                        </form>
                        <button type="button" class="btn-ghost" onclick="document.getElementById('modalMfa').classList.add('active')">Agregar otra app</button>
                    </div>

                <?php else: ?>

                    <p class="perfil-seccion-hint">Agrega una capa extra de seguridad: además de tu contraseña, se pedirá un código de tu app autenticadora al iniciar sesión. Puedes registrar más de una app.</p>
                    <button type="button" class="btn-ghost" onclick="document.getElementById('modalMfa').classList.add('active')">Activar verificación en dos pasos</button>

                <?php endif; ?>
            </div>

        </section>
        </div>

    </main>

    <!-- Los modales viven fuera de <main> a propósito: <main class="page-shell">
         tiene position:relative + z-index:1 (de componentes.css), lo que crea su
         propio contexto de apilamiento — un z-index alto en un descendiente
         nunca "escapa" de ahí, así que un modal adentro de <main> queda siempre
         por DEBAJO del navbar (z-index:50, hermano de <main>, no descendiente).
         Como hijos directos de <body>, los modales sí compiten de igual a igual
         contra el navbar y el footer, y ganan por z-index:10000. -->
    <!-- MODAL: ACTIVAR VERIFICACIÓN EN DOS PASOS (instrucciones + QR + confirmación) —
         siempre presente (no solo cuando no hay ninguna app activa), ya que
         también sirve para agregar apps adicionales. -->
        <div class="dash-modal-overlay<?= $mfa_hay_pendiente ? ' active' : '' ?>" id="modalMfa">
            <div class="dash-modal-card" style="max-width:420px;">
                <div class="dash-modal-head">
                    <button type="button" class="dash-modal-close" onclick="document.getElementById('modalMfa').classList.remove('active')" aria-label="Cerrar">&times;</button>
                    <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                        <span class="eyebrow">Verificación en dos pasos</span>
                        <h2 style="margin:4px 0 0;"><?= $mfa_hay_pendiente ? 'Escanea el código QR' : 'Activar verificación en dos pasos' ?></h2>
                    </div>
                </div>

                <?php if (!$mfa_hay_pendiente): ?>

                    <!-- Paso 1: instrucciones + cuál app se va a usar -->
                    <ol style="color:var(--muted); font-size:0.88rem; line-height:1.9; margin:0 0 22px; padding-left:20px;">
                        <li>Descarga <strong style="color:var(--text);">Google Authenticator</strong> o <strong style="color:var(--text);">Microsoft Authenticator</strong> en tu teléfono (App Store o Google Play).</li>
                        <li>Indícanos cuál app vas a usar.</li>
                        <li>Escanea el código QR que te mostraremos con esa app.</li>
                        <li>Escribe el código de 6 dígitos que te muestre para confirmar.</li>
                    </ol>

                    <form method="post" action="perfil.php">
                        <input type="hidden" name="accion" value="mfa_generar">
                        <div class="field">
                            <label for="app_nombre">¿Qué app vas a usar?</label>
                            <select name="app_nombre" id="app_nombre">
                                <option value="Google Authenticator">Google Authenticator</option>
                                <option value="Microsoft Authenticator">Microsoft Authenticator</option>
                                <option value="Otra app autenticadora">Otra app autenticadora</option>
                            </select>
                        </div>
                        <div class="form-actions" style="justify-content:center;">
                            <button type="submit" class="btn-primary">Continuar</button>
                        </div>
                    </form>

                <?php else: ?>

                    <!-- Paso 2: QR + confirmación -->
                    <p class="reset-subtitle" style="margin-bottom:18px;">
                        Escanea este código con <?= htmlspecialchars($mfa_registro_pendiente['app_nombre'] ?? 'tu app autenticadora') ?> y escribe el código de 6 dígitos que te muestre.
                    </p>

                    <div style="display:flex; justify-content:center; margin-bottom:16px;">
                        <div id="mfaQr" data-uri="<?= htmlspecialchars($mfa_uri_qr) ?>" style="background:#fff; padding:12px; border-radius:12px; line-height:0;"></div>
                    </div>

                    <p class="muted-text" style="text-align:center; margin-bottom:18px;">
                        ¿No puedes escanear? Escribe este código manualmente en tu app:<br>
                        <strong style="font-family:var(--font-mono); letter-spacing:0.05em; word-break:break-all; color:var(--text);"><?= htmlspecialchars($mfa_secreto_manual) ?></strong>
                    </p>

                    <?php if ($accion === 'mfa_confirmar' && $message_type === 'error'): ?>
                        <div class="form-error" style="margin-bottom:18px;"><?= htmlspecialchars($message) ?></div>
                    <?php endif; ?>

                    <form method="post" action="perfil.php">
                        <input type="hidden" name="accion" value="mfa_confirmar">
                        <div class="<?= campo_clase('mfa_codigo', $campos_con_error) ?>" style="max-width:200px; margin:0 auto 20px;">
                            <label for="mfa_codigo" style="text-align:center; display:block;">Código de 6 dígitos</label>
                            <input type="text" id="mfa_codigo" name="mfa_codigo" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required autocomplete="one-time-code" placeholder="000000" style="text-align:center; font-family:var(--font-mono); letter-spacing:0.2em; font-size:1.1rem;">
                        </div>
                        <div class="form-actions" style="justify-content:center;">
                            <button type="submit" class="btn-primary">Confirmar y activar</button>
                        </div>
                    </form>

                    <div class="reset-links">
                        <form method="post" action="perfil.php" style="display:contents;">
                            <input type="hidden" name="accion" value="mfa_cancelar_generacion">
                            <button type="submit" class="perfil-quitar-foto"><span>Cancelar</span></button>
                        </form>
                    </div>

                <?php endif; ?>
            </div>
        </div>

        <?php if ($mfa_activo): ?>
        <!-- MODAL: CONFIRMAR ELIMINAR UN MÉTODO DE VERIFICACIÓN (compartido entre
             todas las filas de la lista; abrirModalEliminarMetodoMfa() lo llena
             con el id_mfa y el nombre de la app correspondientes). -->
        <div class="dash-modal-overlay" id="modalEliminarMetodoMfa">
            <div class="dash-modal-card" style="max-width:480px;">
                <div class="dash-modal-head">
                    <button type="button" class="dash-modal-close" onclick="document.getElementById('modalEliminarMetodoMfa').classList.remove('active')" aria-label="Cerrar">&times;</button>
                    <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                        <h2 style="margin:4px 0 0; font-size:1.15rem;" id="tituloEliminarMetodoMfa">¿Eliminar este método?</h2>
                    </div>
                </div>
                <p class="muted-text" style="text-align:center; margin-bottom:20px; font-size:0.85rem;">Ya no vas a poder usar esta app para iniciar sesión. Puedes volver a agregarla cuando quieras.</p>
                <form method="post" action="perfil.php">
                    <input type="hidden" name="accion" value="mfa_eliminar_metodo">
                    <input type="hidden" name="id_mfa" id="idMfaEliminar">
                    <div class="form-actions" style="justify-content:center;">
                        <button type="button" class="btn-cancel" onclick="document.getElementById('modalEliminarMetodoMfa').classList.remove('active')">Cancelar</button>
                        <button type="submit" class="btn-modal-confirm">Eliminar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- MODAL: RENOMBRAR UNA APP SIN ÍCONO CONOCIDO (compartido; abrirModalRenombrarMfa()
             lo llena con el id_mfa y el nombre actual). -->
        <div class="dash-modal-overlay" id="modalRenombrarMfa">
            <div class="dash-modal-card" style="max-width:420px;">
                <div class="dash-modal-head">
                    <button type="button" class="dash-modal-close" onclick="document.getElementById('modalRenombrarMfa').classList.remove('active')" aria-label="Cerrar">&times;</button>
                    <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                        <h2 style="margin:4px 0 0; font-size:1.15rem;">Ponle un nombre a esta app</h2>
                    </div>
                </div>
                <p class="muted-text" style="text-align:center; margin-bottom:20px; font-size:0.85rem;">Por ejemplo: NetIQ Auth, Authy, 1Password...</p>
                <form method="post" action="perfil.php">
                    <input type="hidden" name="accion" value="mfa_renombrar_app">
                    <input type="hidden" name="id_mfa" id="idMfaRenombrar">
                    <div class="field">
                        <label for="nuevo_nombre_mfa">Nombre</label>
                        <input type="text" id="nuevo_nombre_mfa" name="nuevo_nombre" maxlength="50" required>
                    </div>
                    <div class="form-actions" style="justify-content:center;">
                        <button type="button" class="btn-cancel" onclick="document.getElementById('modalRenombrarMfa').classList.remove('active')">Cancelar</button>
                        <button type="submit" class="btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($mfa_codigos_respaldo_mostrar !== null): ?>
        <!-- MODAL: CÓDIGOS DE RESPALDO (se muestran una sola vez) -->
        <div class="dash-modal-overlay active" id="modalCodigosRespaldo">
            <div class="dash-modal-card" style="max-width:420px;">
                <div class="dash-modal-head">
                    <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                        <span class="eyebrow">Verificación en dos pasos</span>
                        <h2 style="margin:4px 0 0;">Guarda tus códigos de respaldo</h2>
                    </div>
                </div>
                <p class="muted-text" style="text-align:center; margin-bottom:18px;">
                    Úsalos si pierdes acceso a tu app autenticadora. Cada uno funciona una sola vez y no se van a volver a mostrar.
                </p>
                <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-bottom:20px; font-family:var(--font-mono); text-align:center;">
                    <?php foreach ($mfa_codigos_respaldo_mostrar as $codigo_mostrar): ?>
                        <div style="background:var(--bg-panel-2); border:1px solid var(--line); border-radius:8px; padding:10px;"><?= htmlspecialchars($codigo_mostrar) ?></div>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions" style="justify-content:center;">
                    <button type="button" class="btn-primary" onclick="document.getElementById('modalCodigosRespaldo').classList.remove('active')">Ya los guardé</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- MODAL: AJUSTAR FOTO DE PERFIL (zoom + posición, recorte circular) -->
        <div class="dash-modal-overlay" id="modalRecorte">
            <div class="dash-modal-card" style="max-width:400px;">
                <div class="dash-modal-head">
                    <button type="button" class="dash-modal-close" onclick="cancelarRecorte()" aria-label="Cerrar">&times;</button>
                    <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                        <span class="eyebrow">Foto de perfil</span>
                        <h2 style="margin:4px 0 0;">Ajusta tu foto</h2>
                    </div>
                </div>

                <p class="muted-text" style="text-align:center; margin-bottom:18px;">Arrastra la imagen para moverla y usa el control para hacer zoom.</p>

                <div class="recorte-marco">
                    <canvas id="recorteCanvas" width="300" height="300"></canvas>
                </div>

                <div class="recorte-zoom">
                    <span aria-hidden="true">&minus;</span>
                    <input type="range" id="recorteZoom" min="1" max="3" step="0.01" value="1">
                    <span aria-hidden="true">+</span>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="cancelarRecorte()">Cancelar</button>
                    <button type="button" class="btn-primary" onclick="aplicarRecorte()">Aplicar</button>
                </div>
            </div>
        </div>

        <!-- MODAL: CONFIRMAR ELIMINAR FOTO DE PERFIL -->
        <div class="dash-modal-overlay" id="modalEliminarFoto">
            <div class="dash-modal-card" style="max-width:480px;">
                <div class="dash-modal-head">
                    <button type="button" class="dash-modal-close" onclick="cerrarModalEliminarFoto()" aria-label="Cerrar">&times;</button>
                    <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                        <h2 style="margin:4px 0 0; font-size:1.15rem;">¿Eliminar tu foto de perfil?</h2>
                    </div>
                </div>

                <p class="muted-text" style="text-align:center; margin-bottom:20px; font-size:0.85rem;">Se quitará al presionar "Guardar cambios". Puedes subir una nueva cuando quieras.</p>

                <div class="form-actions" style="justify-content:center;">
                    <button type="button" class="btn-cancel" onclick="cerrarModalEliminarFoto()">Cancelar</button>
                    <button type="button" class="btn-modal-confirm" onclick="confirmarEliminarFoto()">Eliminar foto</button>
                </div>
            </div>
        </div>

    <?php require_once 'assets/includes/partials/footer.php'; ?>

    <!-- NOTIFICACIÓN TEMPORAL (ver assets/js/shared/toast.js) -->
    <div id="toastNotificacion" class="toast-notification" data-mensaje="<?= htmlspecialchars($message) ?>" data-tipo="<?= htmlspecialchars($message_type === 'success' ? 'exito' : 'error') ?>" role="alert" aria-live="assertive">
        <span class="toast-icon" aria-hidden="true"></span>
        <div class="toast-body">
            <strong id="toastTitulo"></strong>
            <p id="toastMensaje"></p>
        </div>
        <button type="button" class="toast-close" onclick="cerrarToast()" aria-label="Cerrar notificación">&times;</button>
    </div>

    <script src="assets/js/shared/toast.js"></script>
    <?php if ($mfa_hay_pendiente): ?>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <?php endif; ?>
    <script src="assets/js/pages/perfil.js"></script>

</body>
</html>
