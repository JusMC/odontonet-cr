<?php
/*
    usuarios.php — Gestión de Usuarios (dashboard).

    Alta/edición de usuario con datos extra según rol (paciente: antecedentes
    médicos y alergias; doctor: especialidad, colegiado y horario), soft
    delete (inactivar/activar). Filtros en una barra lateral (rol, estado),
    buscador, botón flotante para agregar usuario y formulario de alta/
    edición en un modal.
*/

session_start();

// Guard de rol: solo el administrador (id_rol = 4) puede acceder a esta página.
if (!isset($_SESSION['id_rol']) || (int) $_SESSION['id_rol'] !== 4) {
    header('Location: login.php');
    exit;
}

require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/bitacora_helper.php';
require_once './assets/includes/helpers/paginacion_helper.php';

$mensaje = "";
$tipo_mensaje = "";

// INACTIVAR usuario (soft-delete: estado = 0, nunca DELETE físico)
if (isset($_GET['accion']) && $_GET['accion'] === 'inactivar' && isset($_GET['id'])) {
    $id_inactivar = intval($_GET['id']);
    try {
        $stmt_inactivar = $pdo->prepare("UPDATE usuarios SET estado = 0 WHERE id_usuario = :id");
        $stmt_inactivar->execute([':id' => $id_inactivar]);
        registrar_bitacora($pdo, 'DELETE', "Se inactivó al usuario #$id_inactivar (soft-delete).");
        header("Location: usuarios.php?msg=inactivado");
        exit;
    } catch (\PDOException $e) {
        registrar_bitacora($pdo, 'ERROR', "Falló inactivar al usuario #$id_inactivar: " . $e->getMessage());
        $mensaje = "Error al inactivar el usuario: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}

// REACTIVAR usuario
if (isset($_GET['accion']) && $_GET['accion'] === 'activar' && isset($_GET['id'])) {
    $id_activar = intval($_GET['id']);
    try {
        $stmt_activar = $pdo->prepare("UPDATE usuarios SET estado = 1 WHERE id_usuario = :id");
        $stmt_activar->execute([':id' => $id_activar]);
        registrar_bitacora($pdo, 'UPDATE', "Se reactivó al usuario #$id_activar.");
        header("Location: usuarios.php?msg=activado");
        exit;
    } catch (\PDOException $e) {
        registrar_bitacora($pdo, 'ERROR', "Falló reactivar al usuario #$id_activar: " . $e->getMessage());
        $mensaje = "Error al activar el usuario: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}

if (isset($_GET['msg'])) {
    $mensajes_ok = [
        'inactivado' => 'Usuario inactivado. Sus datos se conservan en el sistema.',
        'activado'   => 'Usuario reactivado con éxito.',
    ];
    if (isset($mensajes_ok[$_GET['msg']])) {
        $mensaje = $mensajes_ok[$_GET['msg']];
        $tipo_mensaje = "exito";
    }
}

// VARIABLES PARA EDICIÓN (precargan el modal)
$modo_edicion = false;
$usuario_edit = [
    'id_usuario' => '', 'nombre' => '', 'apellido' => '', 'cedula' => '',
    'correo' => '', 'telefono' => '', 'id_rol' => '', 'fecha_nacimiento' => '',
    'direccion' => '', 'especialidad' => '', 'num_colegiado' => '', 'horario_atencion' => '',
    'antecedentes_medicos' => '', 'alergias' => ''
];

// Si viene un ?accion=editar&id=X en la URL, precargamos el modal con los datos de ese usuario.
if (isset($_GET['accion']) && $_GET['accion'] === 'editar' && isset($_GET['id'])) {
    $id_editar = intval($_GET['id']);

    // LEFT JOIN a doctores/pacientes porque un usuario puede no tener fila en ninguna de esas dos tablas.
    $sql_edit = "SELECT u.*, d.especialidad, d.num_colegiado, d.horario_atencion, p.antecedentes_medicos, p.alergias
             FROM usuarios u
             LEFT JOIN doctores d ON u.id_usuario = d.id_usuario
             LEFT JOIN pacientes p ON u.id_usuario = p.id_usuario
             WHERE u.id_usuario = :id";

    $stmt_edit = $pdo->prepare($sql_edit);
    $stmt_edit->execute([':id' => $id_editar]);
    $datos = $stmt_edit->fetch();

    if ($datos) {
        $modo_edicion = true;
        $usuario_edit = $datos;

        // Si es doctor, también cargamos su horario laboral estructurado (días + rango de horas).
        if ((int) $datos['id_rol'] === 2) {
            $stmt_horarios_edit = $pdo->prepare(
                "SELECT dh.dia_semana, dh.hora_inicio, dh.hora_fin
                 FROM doctor_horarios dh
                 INNER JOIN doctores d ON d.id_doctor = dh.id_doctor
                 WHERE d.id_usuario = :id
                 ORDER BY dh.dia_semana ASC"
            );
            $stmt_horarios_edit->execute([':id' => $id_editar]);
            $filas_horario_edit = $stmt_horarios_edit->fetchAll();

            $dias_labor_edit = array_map(fn($f) => (int) $f['dia_semana'], $filas_horario_edit);
            $hora_inicio_labor_edit = $filas_horario_edit[0]['hora_inicio'] ?? '';
            $hora_fin_labor_edit    = $filas_horario_edit[0]['hora_fin'] ?? '';
        }
    }
}
$dias_labor_edit        = $dias_labor_edit ?? [];
$hora_inicio_labor_edit = $hora_inicio_labor_edit ?? '';
$hora_fin_labor_edit    = $hora_fin_labor_edit ?? '';

// PROCESAR FORMULARIO (CREAR O EDITAR)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_usuario'])) {
    $id_usuario = isset($_POST['id_usuario']) ? intval($_POST['id_usuario']) : 0;
    $nombre     = trim($_POST['nombre']);
    $apellido   = trim($_POST['apellido']);
    $cedula     = trim($_POST['cedula']);
    $correo     = trim($_POST['correo']);
    $telefono   = trim($_POST['telefono']);
    $id_rol     = intval($_POST['id_rol']);
    $contrasena = $_POST['contrasena'] ?? '';
    $fecha_nac  = trim($_POST['fecha_nacimiento'] ?? '');
    $direccion  = trim($_POST['direccion'] ?? '');
    $antecedentes_medicos = trim($_POST['antecedentes_medicos'] ?? '');
    $alergias   = trim($_POST['alergias'] ?? '');

    // Validaciones básicas de que no falte nada obligatorio.
    if (!empty($nombre) && !empty($apellido) && !empty($cedula) && !empty($correo) && !empty($id_rol)) {

        // --- Validaciones de formato ---
        $regex_nombre = '/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]{2,50}$/';
        $errores_validacion = [];

        if (!preg_match($regex_nombre, $nombre)) {
            $errores_validacion[] = 'El nombre solo debe contener letras y máximo 50 caracteres.';
        }
        if (!preg_match($regex_nombre, $apellido)) {
            $errores_validacion[] = 'El apellido solo debe contener letras y máximo 50 caracteres.';
        }
        if (!preg_match('/^[0-9]{9}$/', $cedula)) {
            $errores_validacion[] = 'La cédula debe contener exactamente 9 dígitos numéricos.';
        }
        if ($telefono !== '' && !preg_match('/^[0-9]{8,15}$/', $telefono)) {
            $errores_validacion[] = 'El teléfono solo debe contener números, sin espacios ni guiones.';
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $errores_validacion[] = 'Ingresa un correo electrónico válido.';
        } elseif (mb_strlen($correo) > 150) {
            $errores_validacion[] = 'El correo electrónico no puede exceder 150 caracteres.';
        }
        if (mb_strlen($direccion) > 255) {
            $errores_validacion[] = 'La dirección no puede exceder 255 caracteres.';
        }
        if ($fecha_nac !== '') {
            $fecha_min = new DateTime('1876-01-01');
            $fecha_max = new DateTime();
            $fecha_dt  = DateTime::createFromFormat('Y-m-d', $fecha_nac);
            if (!$fecha_dt || $fecha_dt < $fecha_min || $fecha_dt > $fecha_max) {
                $errores_validacion[] = 'La fecha de nacimiento no es válida. Debe estar entre 1876 y la fecha actual.';
            }
        }

        // --- Rol Doctor: especialidad, colegiado y horario son obligatorios ---
        if ($id_rol === 2) {
            $especialidad_post = trim($_POST['especialidad'] ?? '');
            $num_colegiado_post = trim($_POST['num_colegiado'] ?? '');
            $horario_atencion_post = trim($_POST['horario_atencion'] ?? '');

            if ($especialidad_post === '') {
                $errores_validacion[] = 'Indica la especialidad odontológica del doctor.';
            } elseif (mb_strlen($especialidad_post) > 100) {
                $errores_validacion[] = 'La especialidad no puede exceder 100 caracteres.';
            }
            if ($num_colegiado_post === '') {
                $errores_validacion[] = 'Indica el número de colegiado del doctor.';
            } elseif (mb_strlen($num_colegiado_post) > 50) {
                $errores_validacion[] = 'El número de colegiado no puede exceder 50 caracteres.';
            }
            if ($horario_atencion_post === '') {
                $errores_validacion[] = 'Indica el horario de atención del doctor.';
            } elseif (mb_strlen($horario_atencion_post) > 255) {
                $errores_validacion[] = 'El horario de atención no puede exceder 255 caracteres.';
            }

            // El horario estructurado es obligatorio: sin él, el sistema no
            // puede validar que las citas se agenden dentro del horario del
            // doctor (ver validar_horario_doctor en horarios_helper.php).
            $dias_labor_post = isset($_POST['dias_labor']) ? array_map('intval', (array) $_POST['dias_labor']) : [];
            $hora_inicio_labor_post = trim($_POST['hora_inicio_labor'] ?? '');
            $hora_fin_labor_post    = trim($_POST['hora_fin_labor'] ?? '');

            if (empty($dias_labor_post)) {
                $errores_validacion[] = 'Selecciona al menos un día laboral del doctor.';
            }
            if ($hora_inicio_labor_post === '' || $hora_fin_labor_post === '') {
                $errores_validacion[] = 'Indica la hora de inicio y fin del horario laboral del doctor.';
            } elseif ($hora_inicio_labor_post >= $hora_fin_labor_post) {
                $errores_validacion[] = 'La hora de inicio del horario laboral debe ser anterior a la hora de fin.';
            }
        }

        // --- Duplicados: correo y cédula (excluyendo al propio usuario si se edita) ---
        // Solo revisamos duplicados si no hubo errores de formato antes (evita gastar una consulta innecesaria).
        if (empty($errores_validacion)) {
            $sql_dup = 'SELECT correo, cedula FROM usuarios WHERE (correo = :correo OR cedula = :cedula) AND id_usuario != :id LIMIT 1';
            $stmt_dup = $pdo->prepare($sql_dup);
            $stmt_dup->execute(['correo' => $correo, 'cedula' => $cedula, 'id' => $id_usuario]);
            $existente = $stmt_dup->fetch();

            if ($existente) {
                if ($existente['correo'] === $correo) {
                    $errores_validacion[] = 'Ya existe otro usuario registrado con ese correo electrónico.';
                } else {
                    $errores_validacion[] = 'Ya existe otro usuario registrado con esa cédula.';
                }
            }
        }

        // --- Duplicado: número de colegiado (solo aplica a doctores) ---
        if (empty($errores_validacion) && $id_rol === 2) {
            $num_colegiado_chk = trim($_POST['num_colegiado'] ?? '');
            if ($num_colegiado_chk !== '') {
                $sql_dup_col = 'SELECT id_doctor FROM doctores WHERE num_colegiado = :num_colegiado AND id_usuario != :id LIMIT 1';
                $stmt_dup_col = $pdo->prepare($sql_dup_col);
                $stmt_dup_col->execute(['num_colegiado' => $num_colegiado_chk, 'id' => $id_usuario]);
                if ($stmt_dup_col->fetch()) {
                    $errores_validacion[] = 'Ya existe otro doctor registrado con ese número de colegiado.';
                }
            }
        }

        // --- No permitir sacar del rol de doctor a alguien con citas pendientes asignadas ---
        // (no hay forma de reasignarlas a otro odontólogo/horario desde aquí; primero hay
        // que reasignar o cancelar esas citas desde Control de Citas).
        if (empty($errores_validacion) && $id_usuario > 0 && $id_rol !== 2) {
            $stmt_rol_actual = $pdo->prepare("SELECT id_rol FROM usuarios WHERE id_usuario = :id");
            $stmt_rol_actual->execute([':id' => $id_usuario]);
            if ((int) $stmt_rol_actual->fetchColumn() === 2) {
                $stmt_citas_doc = $pdo->prepare("
                    SELECT COUNT(*) FROM citas c
                    INNER JOIN doctores d ON d.id_doctor = c.id_doctor
                    WHERE d.id_usuario = :id AND c.estado IN ('pendiente', 'en_espera', 'en_atencion')
                ");
                $stmt_citas_doc->execute([':id' => $id_usuario]);
                $citas_pendientes_doc = (int) $stmt_citas_doc->fetchColumn();
                if ($citas_pendientes_doc > 0) {
                    $errores_validacion[] = "No se puede cambiar el rol de este doctor: tiene $citas_pendientes_doc cita(s) pendiente(s) asignada(s). Reasígnalas o cancélalas desde Control de Citas antes de cambiar su rol.";
                }
            }
        }

        // --- Contraseña obligatoria solo en creación ---
        if ($id_usuario === 0 && empty($errores_validacion)) {
            $clave_valida = preg_match('/^(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}$/', $contrasena);
            if (!$clave_valida) {
                $errores_validacion[] = 'La contraseña debe tener mínimo 8 caracteres, con mayúscula, minúscula, número y carácter especial.';
            }
        }

        if (!empty($errores_validacion)) {
            // Hubo al menos un error: mostramos todos juntos.
            $mensaje = implode(' ', $errores_validacion);
            $tipo_mensaje = "error";

            // Mantener los datos ingresados en el formulario tras el error (reabre el modal precargado).
            $modo_edicion = $id_usuario > 0;
            $usuario_edit = [
                'id_usuario' => $id_usuario, 'nombre' => $nombre, 'apellido' => $apellido,
                'cedula' => $cedula, 'correo' => $correo, 'telefono' => $telefono,
                'id_rol' => $id_rol, 'fecha_nacimiento' => $fecha_nac, 'direccion' => $direccion,
                'especialidad' => trim($_POST['especialidad'] ?? ''),
                'num_colegiado' => trim($_POST['num_colegiado'] ?? ''),
                'horario_atencion' => trim($_POST['horario_atencion'] ?? ''),
                'antecedentes_medicos' => $antecedentes_medicos,
                'alergias' => $alergias,
            ];
            $dias_labor_edit = isset($_POST['dias_labor']) ? array_map('intval', (array) $_POST['dias_labor']) : [];
            $hora_inicio_labor_edit = trim($_POST['hora_inicio_labor'] ?? '');
            $hora_fin_labor_edit    = trim($_POST['hora_fin_labor'] ?? '');

        } else {
        // Sin errores de validación: guardamos.
        // sp_guardar_usuario hace el alta/edición de usuarios + el upsert de
        // pacientes/doctores + el reemplazo del horario estructurado, todo
        // en una sola transacción con rollback. Es un parámetro INOUT, así
        // que se pasa como variable de sesión de MySQL (@id_usuario_sp), no
        // como parámetro ligado normal.
        try {
            $pdo->exec('SET @id_usuario_sp = ' . (int) $id_usuario);

            // Armamos el horario laboral como JSON (una entrada por cada día que trabaja) para el procedimiento.
            $horario_json = null;
            if ($id_rol === 2) {
                $horario_json = json_encode(array_values(array_map(
                    fn($dia) => ['dia_semana' => $dia, 'hora_inicio' => $hora_inicio_labor_post, 'hora_fin' => $hora_fin_labor_post],
                    array_unique($dias_labor_post)
                )));
            }

            $stmt_call = $pdo->prepare(
                'CALL sp_guardar_usuario(@id_usuario_sp, :rol, :nombre, :apellido, :cedula, :fecha_nac, :direccion, :correo, :hash, :tel, :antecedentes, :alergias, :esp, :colegiado, :horario_txt, :horario_json, @id_rol_anterior)'
            );
            $stmt_call->execute([
                ':rol'          => $id_rol,
                ':nombre'       => $nombre,
                ':apellido'     => $apellido,
                ':cedula'       => $cedula !== '' ? $cedula : null,
                ':fecha_nac'    => $fecha_nac !== '' ? $fecha_nac : null,
                ':direccion'    => $direccion !== '' ? $direccion : null,
                ':correo'       => $correo,
                ':hash'         => $id_usuario === 0 ? password_hash($contrasena, PASSWORD_BCRYPT) : null,
                ':tel'          => $telefono !== '' ? $telefono : null,
                ':antecedentes' => $id_rol === 1 && $antecedentes_medicos !== '' ? $antecedentes_medicos : null,
                ':alergias'     => $id_rol === 1 && $alergias !== '' ? $alergias : null,
                ':esp'          => $id_rol === 2 ? trim($_POST['especialidad']) : null,
                ':colegiado'    => $id_rol === 2 ? trim($_POST['num_colegiado']) : null,
                ':horario_txt'  => $id_rol === 2 ? (trim($_POST['horario_atencion'] ?? '') ?: null) : null,
                ':horario_json' => $horario_json,
            ]);
            $stmt_call->closeCursor();

            // Si ya tenía id, fue una edición; si no, fue un usuario nuevo.
            if ($id_usuario > 0) {
                $mensaje = "Usuario actualizado correctamente.";
                $modo_edicion = false;
            } else {
                $mensaje = "Usuario registrado con éxito.";
            }
            $tipo_mensaje = "exito";

        } catch (\PDOException $e) {
            registrar_bitacora($pdo, 'ERROR', "Falló " . ($id_usuario > 0 ? "actualizar el usuario #$id_usuario" : "registrar un usuario nuevo") . " ($nombre $apellido, $correo): " . $e->getMessage());
            $mensaje = "Error en la operación. Verifica que la cédula y el correo no estén ya registrados.";
            $tipo_mensaje = "error";
        }
        }
    } else {
        $mensaje = "Por favor complete todos los campos obligatorios.";
        $tipo_mensaje = "error";
    }
}

// ¿El modal de agregar/editar debe abrirse solo al cargar la página?
$abrir_modal_formulario = $modo_edicion || ($tipo_mensaje === 'error' && isset($_POST['guardar_usuario']));

// CONSULTAR ROLES
$roles = $pdo->query("SELECT id_rol, nombre FROM roles WHERE activo = 1")->fetchAll();

// LÓGICA DE BÚSQUEDA Y FILTROS (barra lateral)
$busqueda      = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$rol_filtro    = isset($_GET['rol']) ? intval($_GET['rol']) : 0;
$estado_filtro = isset($_GET['estado']) && in_array($_GET['estado'], ['1', '0'], true) ? $_GET['estado'] : '';

// Armamos la consulta SQL en pedazos ("condiciones"), agregando solo los filtros que el usuario realmente usó.
$condiciones_usuarios = [];
$params_usuarios = [];

if ($busqueda !== '') {
    $condiciones_usuarios[] = "(u.nombre LIKE :b1 OR u.apellido LIKE :b2 OR u.correo LIKE :b3 OR u.cedula LIKE :b4)";
    $param_valor = '%' . $busqueda . '%';
    $params_usuarios[':b1'] = $param_valor;
    $params_usuarios[':b2'] = $param_valor;
    $params_usuarios[':b3'] = $param_valor;
    $params_usuarios[':b4'] = $param_valor;
}

if ($rol_filtro > 0) {
    $condiciones_usuarios[] = "u.id_rol = :rol";
    $params_usuarios[':rol'] = $rol_filtro;
}

if ($estado_filtro !== '') {
    $condiciones_usuarios[] = "u.estado = :estado";
    $params_usuarios[':estado'] = (int) $estado_filtro;
}

// Unimos todas las condiciones activas con "AND" para formar el WHERE final.
$where_usuarios = count($condiciones_usuarios) > 0 ? ' WHERE ' . implode(' AND ', $condiciones_usuarios) : '';

$hay_filtros_activos = $busqueda !== '' || $rol_filtro > 0 || $estado_filtro !== '';

// Parámetros de filtro para conservarlos en los enlaces de paginación.
$query_filtros = '';
if ($busqueda !== '') $query_filtros .= '&buscar=' . urlencode($busqueda);
if ($rol_filtro > 0) $query_filtros .= '&rol=' . $rol_filtro;
if ($estado_filtro !== '') $query_filtros .= '&estado=' . urlencode($estado_filtro);

// PAGINACIÓN
$limite = 5; // Cuántos usuarios se muestran por página.
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

// Contamos cuántos usuarios hay en total con estos filtros (para saber cuántas páginas hacen falta).
$sql_total = "SELECT COUNT(*) FROM usuarios u" . $where_usuarios;
$stmt_total = $pdo->prepare($sql_total);
$stmt_total->execute($params_usuarios);
$total_registros = (int) $stmt_total->fetchColumn();

$total_paginas = max(1, (int) ceil($total_registros / $limite));
if ($pagina > $total_paginas) {
    $pagina = $total_paginas;
}

$inicio_int = (int) (($pagina - 1) * $limite); // Desde qué fila empezar (para el LIMIT de SQL).
$limite_int = (int) $limite;

// CONSULTAR USUARIOS
$sql_usuarios = "SELECT u.id_usuario, u.nombre, u.apellido, u.correo, u.telefono, u.estado, u.id_rol,
                        r.nombre AS rol_nombre,
                        u.cedula
                 FROM usuarios u
                 INNER JOIN roles r ON u.id_rol = r.id_rol"
                 . $where_usuarios .
                 " ORDER BY u.id_usuario DESC
                 LIMIT $inicio_int, $limite_int";

$stmt_usuarios = $pdo->prepare($sql_usuarios);
$stmt_usuarios->execute($params_usuarios);
$lista_usuarios = $stmt_usuarios->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/home.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <link rel="stylesheet" href="assets/css/shared/dashboard_forms.css">
    <link rel="stylesheet" href="assets/css/shared/pacientes_usuarios.css">
    <link rel="stylesheet" href="assets/css/pages/usuarios.css">
    <link rel="stylesheet" href="assets/css/shared/toast.css">
</head>

<body>

    <div class="grid-overlay"></div>
    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>

    <!-- NAVBAR -->
    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <section class="wrap dash-wide">
        <div class="dash-header">
            <div>
                <span class="eyebrow">Dashboard</span>
                <h1>Gestión de <span class="grad">Usuarios</span></h1>
            </div>
        </div>
    </section>

    <section class="wrap dash-wide">

        <!-- LAYOUT: SIDEBAR DE FILTROS + CONTENIDO -->
        <form action="usuarios.php" method="GET" class="dash-layout" id="formFiltros">

            <aside class="dash-sidebar">
                <h3>Filtros</h3>

                <div class="dash-filter-group">
                    <label for="rol">Rol en el sistema</label>
                    <select name="rol" id="rol" class="form-control">
                        <option value="">Todos los roles</option>
                        <?php foreach ($roles as $rol): ?>
                            <option value="<?= (int) $rol['id_rol'] ?>" <?= $rol_filtro === (int) $rol['id_rol'] ? 'selected' : '' ?>>
                                <?= ucfirst(htmlspecialchars($rol['nombre'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dash-filter-group">
                    <label for="estado">Estado</label>
                    <select name="estado" id="estado" class="form-control">
                        <option value="">Todos</option>
                        <option value="1" <?= $estado_filtro === '1' ? 'selected' : '' ?>>Activo</option>
                        <option value="0" <?= $estado_filtro === '0' ? 'selected' : '' ?>>Inactivo</option>
                    </select>
                </div>

                <div class="dash-sidebar-actions">
                    <button type="submit" class="btn-primary">Aplicar filtros</button>
                    <?php if ($hay_filtros_activos): ?>
                        <a href="usuarios.php" class="btn-cancel">Limpiar filtros</a>
                    <?php endif; ?>
                </div>
            </aside>

            <main class="dash-main">
                <div class="dash-search">
                    <button type="button" class="dash-fab-text" onclick="abrirModalUsuario(true)">
                        <span class="dash-fab-text-plus" aria-hidden="true">
                            <span class="dash-fab-text-plus-icon"></span>
                        </span>
                        Registrar Usuario
                    </button>
                    <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por nombre, apellido, correo o cédula..." class="form-control">
                    <button type="submit" class="btn-primary">Buscar</button>
                </div>

                <p class="dash-results-count">
                    <?= $total_registros ?> usuario(s) encontrado(s)<?= $hay_filtros_activos ? ' con los filtros aplicados' : '' ?>. ¿Qué puede hacer cada rol? Consulta la <a href="permisos.php" style="color: var(--cyan);">matriz de permisos</a>.
                </p>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Correo</th>
                                <th>Teléfono</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($lista_usuarios) > 0): ?>
                                <?php foreach ($lista_usuarios as $u): ?>
                                    <tr>
                                        <td>
                                            <div class="dash-user-cell">
                                                <?php
                                                    // El ícono depende del estado y del rol del usuario.
                                                    $icono_usuario = match (true) {
                                                        (int) $u['estado'] !== 1 => 'bi-person-fill-x',
                                                        (int) $u['id_rol'] === 4 => 'bi-person-fill-gear',
                                                        in_array((int) $u['id_rol'], [2, 3], true) => 'bi-person-badge',
                                                        default => 'bi-person-fill',
                                                    };
                                                ?>
                                                <i class="bi <?= $icono_usuario ?><?= (int) $u['estado'] !== 1 ? ' dash-user-icon-inactivo' : '' ?>"></i>
                                                <div class="dash-user-cell-text">
                                                    <strong><?= htmlspecialchars($u['nombre'] . ' ' . $u['apellido']) ?></strong>
                                                    <small class="muted-text">Identificación: <?= htmlspecialchars($u['cedula'] ?? 'N/A') ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($u['correo']) ?></td>
                                        <td><?= htmlspecialchars($u['telefono'] ?? 'N/A') ?></td>
                                        <td>
                                            <?php
                                                // Traducimos el nombre del rol a una de las clases de color ya definidas en CSS.
                                                $rol_clase = strtolower(trim((string) $u['rol_nombre']));
                                                $rol_clase = match ($rol_clase) {
                                                    'admin', 'administrador' => 'administrador',
                                                    'doctor', 'medico' => 'doctor',
                                                    'recepcionista', 'recepcion' => 'recepcionista',
                                                    'paciente' => 'paciente',
                                                    default => 'administrador',
                                                };
                                            ?>
                                            <span class="badge badge-<?= htmlspecialchars($rol_clase, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($u['rol_nombre']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?= (int) $u['estado'] === 1 ? 'success' : 'cancelada' ?>">
                                                <?= (int) $u['estado'] === 1 ? 'Activo' : 'Inactivo' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="usuarios.php?accion=editar&id=<?= $u['id_usuario'] ?>" class="btn-action btn-edit">Editar</a>
                                            <?php if ((int) $u['estado'] === 1): ?>
                                                <a href="#" class="btn-action btn-delete"
                                                   onclick="confirmarInactivacion('usuarios.php?accion=inactivar&id=<?= $u['id_usuario'] ?>', '<?= htmlspecialchars(addslashes($u['nombre'] . ' ' . $u['apellido'])) ?>'); return false;">
                                                    Inactivar
                                                </a>
                                            <?php else: ?>
                                                <a href="usuarios.php?accion=activar&id=<?= $u['id_usuario'] ?>"
                                                   class="btn-action btn-edit"
                                                   style="background:rgba(46,204,113,0.15); color:#2ecc71; border-color:#2ecc71;">
                                                    Activar
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="muted-text text-center">No hay usuarios registrados con estos filtros.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_paginas > 1): ?>
                    <div class="pagination-container">
                        <span class="pagination-info">Página <strong><?= $pagina ?></strong> de <strong><?= $total_paginas ?></strong> (<?= $total_registros ?> usuarios)</span>
                        <div class="pagination">
                            <?php if ($pagina > 1): ?>
                                <a href="usuarios.php?pagina=<?= $pagina - 1 ?><?= $query_filtros ?>" class="page-link">&laquo; Anterior</a>
                            <?php endif; ?>
                            <?php foreach (obtener_paginas_visibles($pagina, $total_paginas) as $item): ?>
                                <?php if ($item === '...'): ?>
                                    <span class="page-ellipsis">&hellip;</span>
                                <?php else: ?>
                                    <a href="usuarios.php?pagina=<?= $item ?><?= $query_filtros ?>" class="page-link <?= $item === $pagina ? 'active' : '' ?>"><?= $item ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($pagina < $total_paginas): ?>
                                <a href="usuarios.php?pagina=<?= $pagina + 1 ?><?= $query_filtros ?>" class="page-link">Siguiente &raquo;</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </form>
    </section>

    <!-- MODAL: AGREGAR / EDITAR USUARIO -->
    <div id="modalUsuario" class="dash-modal-overlay">
        <div class="dash-modal-card">
            <div class="dash-modal-head">
                <button type="button" class="dash-modal-close" onclick="cerrarModalUsuario()" aria-label="Cerrar">&times;</button>
                <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                    <span class="eyebrow" id="modalUsuarioEyebrow"><?= $modo_edicion ? 'Modificando Registro' : 'Registrar usuario' ?></span>
                    <h2 id="modalUsuarioTitulo" style="margin:4px 0 0;"><?= $modo_edicion ? 'Editar usuario' : 'Nuevo usuario' ?></h2>
                </div>
            </div>

            <form action="usuarios.php" method="POST" id="formUsuario">
                <input type="hidden" name="guardar_usuario" value="1">
                <input type="hidden" name="id_usuario" id="id_usuario" value="<?= htmlspecialchars($usuario_edit['id_usuario']) ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="cedula"><span class="dash-req">*</span> Cédula / Identificación</label>
                        <input type="text" id="cedula" name="cedula" value="<?= htmlspecialchars($usuario_edit['cedula'] ?? '') ?>" placeholder="Ej: 102930485" required maxlength="9" inputmode="numeric" pattern="[0-9]{9}" class="form-control" title="Debe tener exactamente 9 dígitos numéricos, sin guiones ni espacios.">
                    </div>

                    <div class="form-group">
                        <label for="nombre"><span class="dash-req">*</span> Nombre</label>
                        <input type="text" id="nombre" name="nombre" value="<?= htmlspecialchars($usuario_edit['nombre']) ?>" placeholder="Ej: María" required maxlength="50" pattern="[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+" class="form-control" title="Solo letras y espacios (2 a 50 caracteres).">
                    </div>

                    <div class="form-group">
                        <label for="apellido"><span class="dash-req">*</span> Apellido</label>
                        <input type="text" id="apellido" name="apellido" value="<?= htmlspecialchars($usuario_edit['apellido']) ?>" placeholder="Ej: Rodríguez" required maxlength="50" pattern="[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+" class="form-control" title="Solo letras y espacios (2 a 50 caracteres).">
                    </div>

                    <div class="form-group">
                        <label for="correo"><span class="dash-req">*</span> Correo Electrónico</label>
                        <input type="email" id="correo" name="correo" value="<?= htmlspecialchars($usuario_edit['correo'] ?? '') ?>" placeholder="correo@ejemplo.com" required maxlength="150" class="form-control" title="Debe ser un correo electrónico válido.">
                    </div>

                    <div class="form-group" id="grupo_contrasena" style="<?= $modo_edicion ? 'display:none;' : '' ?>">
                        <label for="contrasena"><span class="dash-req">*</span> Contraseña</label>
                        <input type="password" id="contrasena" name="contrasena" <?= $modo_edicion ? '' : 'required' ?> class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="telefono">Teléfono</label>
                        <input type="tel" id="telefono" name="telefono" value="<?= htmlspecialchars($usuario_edit['telefono'] ?? '') ?>" placeholder="+506 8888-8888" pattern="[0-9]{8,15}" inputmode="numeric" class="form-control" title="Solo números, sin espacios ni guiones (8 a 15 dígitos).">
                    </div>

                    <div class="form-group">
                        <label for="id_rol"><span class="dash-req">*</span> Rol en el Sistema</label>
                        <select name="id_rol" id="id_rol" required onchange="toggleCamposEspeciales(this.value)" class="form-control">
                            <option value="">-- Selecciona Rol --</option>
                            <?php foreach ($roles as $rol): ?>
                                <option value="<?= $rol['id_rol'] ?>" <?= $usuario_edit['id_rol'] == $rol['id_rol'] ? 'selected' : '' ?>>
                                    <?= ucfirst(htmlspecialchars($rol['nombre'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- CAMPOS EXTRA PARA PACIENTES -->
                <div id="seccion_paciente" class="form-section-extra">
                    <div class="sec-head">
                        <span class="eyebrow">Complementario</span>
                        <h2 style="font-size:1rem;">Datos Personales y Clínicos del Paciente</h2>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="fecha_nacimiento">Fecha de Nacimiento</label>
                            <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" value="<?= htmlspecialchars($usuario_edit['fecha_nacimiento'] ?? '') ?>" min="1876-01-01" max="<?= date('Y-m-d') ?>" class="form-control">
                        </div>
                        <div class="form-group col-full">
                            <label for="direccion">Dirección de Residencia</label>
                            <input type="text" id="direccion" name="direccion" value="<?= htmlspecialchars($usuario_edit['direccion'] ?? '') ?>" placeholder="Provincia, Cantón, Distrito..." maxlength="255" class="form-control">
                        </div>
                        <div class="form-group col-full">
                            <label for="antecedentes_medicos">Antecedentes Médicos</label>
                            <textarea id="antecedentes_medicos" name="antecedentes_medicos" rows="3" placeholder="Ej: Hipertensión, diabetes, cirugías previas..." class="form-control"><?= htmlspecialchars($usuario_edit['antecedentes_medicos'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group col-full">
                            <label for="alergias">Alergias</label>
                            <textarea id="alergias" name="alergias" rows="3" placeholder="Ej: Penicilina, látex..." class="form-control"><?= htmlspecialchars($usuario_edit['alergias'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- CAMPOS EXTRA PARA DOCTORES -->
                <div id="seccion_doctor" class="form-section-extra">
                    <div class="sec-head">
                        <span class="eyebrow">Complementario</span>
                        <h2 style="font-size:1rem;">Perfil Profesional</h2>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="especialidad"><span class="dash-req">*</span> Especialidad Odontológica</label>
                            <input type="text" id="especialidad" name="especialidad" value="<?= htmlspecialchars($usuario_edit['especialidad'] ?? '') ?>" placeholder="Ej: Ortodoncia, Endodoncia" maxlength="100" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="num_colegiado"><span class="dash-req">*</span> Nº de Colegiado</label>
                            <input type="text" id="num_colegiado" name="num_colegiado" value="<?= htmlspecialchars($usuario_edit['num_colegiado'] ?? '') ?>" placeholder="COL-XXXXX" maxlength="50" class="form-control">
                        </div>
                        <div class="form-group col-full">
                            <label for="horario_atencion"><span class="dash-req">*</span> Horario de Atención</label>
                            <input type="text" id="horario_atencion" name="horario_atencion" value="<?= htmlspecialchars($usuario_edit['horario_atencion'] ?? '') ?>" placeholder="Ej: Lunes a Viernes 8:00am - 5:00pm" maxlength="255" class="form-control">
                        </div>
                        <div class="form-group col-full">
                            <label><span class="dash-req">*</span> Horario estructurado</label>
                            <p class="muted-text" style="margin: 0 0 10px;">Marca los días que atiende y su rango de horas. El sistema usa esto para impedir que se agenden citas fuera del horario del doctor.</p>
                            <div class="checklist-caja" id="checklistDiasLabor">
                                <?php
                                $dias_semana_labels = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 0 => 'Domingo'];
                                foreach ($dias_semana_labels as $dia_val => $dia_label):
                                ?>
                                    <label>
                                        <input type="checkbox" name="dias_labor[]" value="<?= $dia_val ?>" <?= in_array($dia_val, $dias_labor_edit, true) ? 'checked' : '' ?>>
                                        <?= $dia_label ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-row" style="margin-top:12px;">
                                <div class="field">
                                    <label for="hora_inicio_labor">Hora de inicio</label>
                                    <input type="time" id="hora_inicio_labor" name="hora_inicio_labor" value="<?= htmlspecialchars(substr($hora_inicio_labor_edit, 0, 5)) ?>" class="form-control">
                                </div>
                                <div class="field">
                                    <label for="hora_fin_labor">Hora de fin</label>
                                    <input type="time" id="hora_fin_labor" name="hora_fin_labor" value="<?= htmlspecialchars(substr($hora_fin_labor_edit, 0, 5)) ?>" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModalUsuario()">Cancelar</button>
                    <button type="submit" class="btn-primary btn-submit" id="btnGuardarUsuario">
                        <?= $modo_edicion ? 'Actualizar Usuario' : 'Registrar Usuario' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: CONFIRMAR INACTIVACIÓN -->
    <div id="modalConfirmacion" class="custom-modal-overlay">
        <div class="custom-modal-card">
            <h3>¿Inactivar usuario?</h3>
            <p id="modalMensajeTexto">¿Estás seguro de que deseas inactivar este usuario? Podrás reactivarlo después.</p>
            <div class="custom-modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="cerrarModal()">Cancelar</button>
                <a id="btnConfirmarModal" href="#" class="btn-modal-confirm">Inactivar</a>
            </div>
        </div>
    </div>

    <?php require_once 'assets/includes/partials/footer.php'; ?>

    <script src="assets/js/pages/usuarios.js"></script>
    <script>
        // MODAL DE USUARIO (agregar / editar)
        const modalUsuario = document.getElementById('modalUsuario');
        const formUsuario = document.getElementById('formUsuario');
        const campoIdUsuario = document.getElementById('id_usuario');
        const eyebrowModalUsuario = document.getElementById('modalUsuarioEyebrow');
        const tituloModalUsuario = document.getElementById('modalUsuarioTitulo');
        const botonGuardarUsuario = document.getElementById('btnGuardarUsuario');
        const grupoContrasena = document.getElementById('grupo_contrasena');
        const campoContrasena = document.getElementById('contrasena');

        // Abre el modal. Si "esNuevo" es true, limpia todo primero (para no arrastrar datos de una edición anterior).
        function abrirModalUsuario(esNuevo) {
            if (esNuevo) {
                // No usamos formUsuario.reset(): si el modal ya se había
                // renderizado precargado (modo edición), reset() volvería a
                // esos valores en vez de dejar el formulario en blanco.
                campoIdUsuario.value = '';
                document.getElementById('cedula').value = '';
                document.getElementById('nombre').value = '';
                document.getElementById('apellido').value = '';
                document.getElementById('correo').value = '';
                document.getElementById('telefono').value = '';
                document.getElementById('id_rol').value = '';
                document.getElementById('fecha_nacimiento').value = '';
                document.getElementById('direccion').value = '';
                document.getElementById('antecedentes_medicos').value = '';
                document.getElementById('alergias').value = '';
                document.getElementById('especialidad').value = '';
                document.getElementById('num_colegiado').value = '';
                document.getElementById('horario_atencion').value = '';
                document.getElementById('hora_inicio_labor').value = '';
                document.getElementById('hora_fin_labor').value = '';
                document.querySelectorAll('#checklistDiasLabor input[type="checkbox"]').forEach(function (cb) { cb.checked = false; });

                grupoContrasena.style.display = '';
                campoContrasena.required = true;
                campoContrasena.value = '';

                toggleCamposEspeciales('');

                eyebrowModalUsuario.textContent = 'Registrar usuario';
                tituloModalUsuario.textContent = 'Nuevo usuario';
                botonGuardarUsuario.textContent = 'Registrar Usuario';
            }
            modalUsuario.classList.add('active');
        }

        function cerrarModalUsuario() {
            modalUsuario.classList.remove('active');
        }

        modalUsuario.addEventListener('click', function (e) {
            if (e.target === this) cerrarModalUsuario();
        });

        // MODAL DE CONFIRMACIÓN DE INACTIVACIÓN
        // Rellena el texto del modal con el nombre del usuario y prepara el link de confirmación.
        function confirmarInactivacion(url, nombre) {
            var mensaje = '¿Deseas inactivar al usuario "' + nombre + '"? Podrás reactivarlo en cualquier momento.';
            document.getElementById('modalMensajeTexto').innerText = mensaje;
            document.getElementById('btnConfirmarModal').href = url;
            document.getElementById('modalConfirmacion').classList.add('active');
        }

        function cerrarModal() {
            document.getElementById('modalConfirmacion').classList.remove('active');
        }

        document.getElementById('modalConfirmacion').addEventListener('click', function (e) {
            if (e.target === this) cerrarModal();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                cerrarModalUsuario();
                cerrarModal();
            }
        });

        // Si veníamos de "Editar" o de un error de validación al guardar, abrir el modal automáticamente
        // (sin limpiar el formulario, para conservar los datos ya precargados por PHP).
        <?php if ($abrir_modal_formulario): ?>
            abrirModalUsuario(false);
            <?php if ($usuario_edit['id_rol'] !== ''): ?>
                toggleCamposEspeciales('<?= (int) $usuario_edit['id_rol'] ?>');
            <?php endif; ?>
        <?php endif; ?>
    </script>

    <!-- NOTIFICACIÓN TEMPORAL (ver assets/js/shared/toast.js) -->
    <div id="toastNotificacion" class="toast-notification" role="alert" aria-live="assertive">
        <span class="toast-icon" aria-hidden="true"></span>
        <div class="toast-body">
            <strong id="toastTitulo"></strong>
            <p id="toastMensaje"></p>
        </div>
        <button type="button" class="toast-close" onclick="cerrarToast()" aria-label="Cerrar notificación">&times;</button>
    </div>

    <script src="assets/js/shared/toast.js"></script>
    <?php if (!empty($mensaje)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                mostrarToast(<?= json_encode($mensaje) ?>, <?= json_encode($tipo_mensaje) ?>);
            });
        </script>
    <?php endif; ?>

</body>
</html>
