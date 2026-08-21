<?php
/*
    examenes.php — Control de radiografías y exámenes odontológicos.

    Permite a un doctor (o al administrador) solicitar un examen para un
    paciente (tipo de examen, fecha de solicitud, observaciones iniciales)
    y luego registrar su resultado (texto + archivo adjunto opcional:
    imagen JPG/PNG o PDF). Queda vinculado al paciente y, si aplica, a la
    cita/entrada del expediente que lo originó.
*/

require_once __DIR__ . '/assets/includes/session_boot.php';
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/bitacora_helper.php';
require_once './assets/includes/helpers/paginacion_helper.php';
require_once './assets/includes/helpers/archivo_helper.php';

// Guard: solo doctor (id_rol = 2) o administrador (id_rol = 4).
$id_rol = (int) ($_SESSION['id_rol'] ?? 0);
if (!in_array($id_rol, [2, 4], true)) {
    header('Location: inicio.php');
    exit;
}

// Si es doctor, averiguamos su propio id_doctor (solo puede solicitar/editar bajo su propio nombre).
$mi_id_doctor = null;
if ($id_rol === 2) {
    $stmt_mi_doctor = $pdo->prepare("SELECT id_doctor FROM doctores WHERE id_usuario = :id");
    $stmt_mi_doctor->execute([':id' => $_SESSION['usuario_id']]);
    $mi_id_doctor = (int) $stmt_mi_doctor->fetchColumn();
}
// A partir de aquí, $mi_id_doctor sirve para filtrar/validar que un doctor solo toque sus propios exámenes.

$mensaje = "";
$tipo_mensaje = "";

// MENSAJES DE FEEDBACK MEDIANTE GET
if (isset($_GET['msg'])) {
    $mensajes_ok = [
        'solicitado'  => 'Examen solicitado con éxito.',
        'registrado'  => 'Resultado registrado con éxito.',
        'cancelado'   => 'Examen cancelado.',
    ];
    if (isset($mensajes_ok[$_GET['msg']])) {
        $mensaje = $mensajes_ok[$_GET['msg']];
        $tipo_mensaje = "exito";
    }
}

// CANCELAR EXAMEN
if (isset($_GET['accion']) && $_GET['accion'] === 'cancelar' && isset($_GET['id'])) {
    $id_cancelar = intval($_GET['id']);

    $stmt_chk = $pdo->prepare("SELECT id_doctor FROM examenes WHERE id_examen = :id");
    $stmt_chk->execute([':id' => $id_cancelar]);
    $doctor_del_examen = $stmt_chk->fetchColumn();

    // Solo el administrador o el doctor que lo solicitó pueden cancelarlo.
    if ($doctor_del_examen !== false && ($id_rol === 4 || (int) $doctor_del_examen === $mi_id_doctor)) {
        $stmt_cancelar = $pdo->prepare("UPDATE examenes SET estado = 'cancelado' WHERE id_examen = :id AND estado = 'solicitado'");
        $stmt_cancelar->execute([':id' => $id_cancelar]);
        registrar_bitacora($pdo, 'UPDATE', "Se canceló el examen #$id_cancelar.");
    }

    header("Location: examenes.php?msg=cancelado");
    exit;
}

// PROCESAR SOLICITUD DE NUEVO EXAMEN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitar_examen'])) {
    $id_paciente      = intval($_POST['id_paciente']);
    $id_doctor        = $id_rol === 2 ? $mi_id_doctor : intval($_POST['id_doctor']);
    $id_cita          = !empty($_POST['id_cita']) ? intval($_POST['id_cita']) : null;
    $tipo_examen      = trim($_POST['tipo_examen']);
    $fecha_solicitud  = !empty($_POST['fecha_solicitud']) ? $_POST['fecha_solicitud'] : date('Y-m-d');
    $observaciones    = trim($_POST['observaciones'] ?? '');

    // Validaciones en cadena: la primera que falle define el mensaje de error.
    if (empty($id_paciente) || empty($id_doctor) || $tipo_examen === '') {
        $mensaje = "Completa el paciente, el tipo de examen y el doctor solicitante.";
        $tipo_mensaje = "error";
    } elseif (mb_strlen($tipo_examen) > 100) {
        $mensaje = "El tipo de examen no puede exceder los 100 caracteres.";
        $tipo_mensaje = "error";
    } else {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO examenes (id_paciente, id_doctor, id_cita, tipo_examen, fecha_solicitud, observaciones)
                 VALUES (:id_paciente, :id_doctor, :id_cita, :tipo_examen, :fecha_solicitud, :observaciones)"
            );
            $stmt->execute([
                ':id_paciente'     => $id_paciente,
                ':id_doctor'       => $id_doctor,
                ':id_cita'         => $id_cita,
                ':tipo_examen'     => $tipo_examen,
                ':fecha_solicitud' => $fecha_solicitud,
                ':observaciones'   => $observaciones !== '' ? $observaciones : null,
            ]);

            $id_nuevo = $pdo->lastInsertId();
            registrar_bitacora($pdo, 'INSERT', "Se solicitó el examen #$id_nuevo ($tipo_examen) para el paciente #$id_paciente.");

            $redir = $id_cita ? "expediente.php?id_cita=$id_cita" : "examenes.php?msg=solicitado";
            header("Location: $redir");
            exit;

        } catch (\PDOException $e) {
            registrar_bitacora($pdo, 'ERROR', "Falló solicitar un examen ($tipo_examen) para el paciente #$id_paciente: " . $e->getMessage());
            $mensaje = "Error al solicitar el examen: " . $e->getMessage();
            $tipo_mensaje = "error";
        }
    }
}

// PROCESAR REGISTRO DE RESULTADO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_resultado'])) {
    $id_examen               = intval($_POST['id_examen']);
    $resultado                = trim($_POST['resultado'] ?? '');
    $observaciones_resultado  = trim($_POST['observaciones_resultado'] ?? '');

    $stmt_examen = $pdo->prepare("SELECT * FROM examenes WHERE id_examen = :id");
    $stmt_examen->execute([':id' => $id_examen]);
    $examen_actual = $stmt_examen->fetch();

    // Solo el administrador o el doctor que solicitó este examen puede registrar su resultado.
    $autorizado = $examen_actual && ($id_rol === 4 || (int) $examen_actual['id_doctor'] === $mi_id_doctor);

    if (!$examen_actual || !$autorizado) {
        header("Location: examenes.php");
        exit;
    }

    // VALIDACIÓN DEL ARCHIVO ADJUNTO (opcional): imagen JPG/PNG o PDF.
    // No confiamos solo en la extensión del nombre: para PDF revisamos la firma real del archivo,
    // y para imágenes usamos getimagesize() para confirmar que de verdad es una imagen válida.
    $archivo_error = '';
    $archivo_subida_valida = false;
    $extension_archivo = '';
    $extensiones_validas = ['jpg', 'jpeg', 'png', 'pdf'];

    if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['archivo']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['archivo']['error'] === UPLOAD_ERR_FORM_SIZE) {
            $archivo_error = "El archivo es demasiado grande.";
        } elseif ($_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            $archivo_error = "Ocurrió un error al subir el archivo. Intenta de nuevo.";
        } else {
            $extension_archivo = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));

            if (!in_array($extension_archivo, $extensiones_validas, true)) {
                $archivo_error = "El archivo debe ser JPG, JPEG, PNG o PDF.";
            } elseif ($_FILES['archivo']['size'] > 5 * 1024 * 1024) {
                $archivo_error = "El archivo no puede superar los 5 MB.";
            } elseif (in_array($extension_archivo, ['jpg', 'jpeg', 'png'], true)) {
                $info_imagen = @getimagesize($_FILES['archivo']['tmp_name']);
                if ($info_imagen === false || !in_array($info_imagen['mime'], ['image/jpeg', 'image/png'], true)) {
                    $archivo_error = "El archivo no es una imagen JPG/PNG válida.";
                } else {
                    $archivo_subida_valida = true;
                    $mime_archivo = $info_imagen['mime'];
                }
            } else {
                // PDF: confirmamos que los primeros bytes del archivo sean "%PDF" (su firma real), no solo la extensión.
                $handle = @fopen($_FILES['archivo']['tmp_name'], 'rb');
                $firma = $handle ? fread($handle, 4) : '';
                if ($handle) {
                    fclose($handle);
                }
                if ($firma !== '%PDF') {
                    $archivo_error = "El archivo no es un PDF válido.";
                } else {
                    $archivo_subida_valida = true;
                    $mime_archivo = 'application/pdf';
                }
            }
        }
    }

    if ($resultado === '') {
        $mensaje = "Escribe el resultado del examen.";
        $tipo_mensaje = "error";
        $id_examen_form_error = $id_examen;
    } elseif ($archivo_error !== '') {
        $mensaje = $archivo_error;
        $tipo_mensaje = "error";
        $id_examen_form_error = $id_examen;
    } else {
        try {
            $ruta_archivo_nueva = null;
            if ($archivo_subida_valida) {
                // Nombre único para el archivo, para que dos exámenes distintos nunca se pisen entre sí.
                $nombre_archivo = 'examen_' . uniqid() . '.' . $extension_archivo;
                $ruta_archivo_nueva = guardar_archivo_subido($_FILES['archivo']['tmp_name'], 'storage/examenes', $nombre_archivo, $mime_archivo);
                if ($ruta_archivo_nueva === null) {
                    throw new RuntimeException('No se pudo guardar el archivo en el servidor.');
                }

                // Si había un archivo anterior, lo borramos para no acumular huérfanos.
                if (!empty($examen_actual['archivo'])) {
                    eliminar_archivo_guardado($examen_actual['archivo']);
                }
            }

            $sql = "UPDATE examenes SET estado = 'realizado', resultado = :resultado, observaciones = :observaciones"
                 . ($ruta_archivo_nueva !== null ? ", archivo = :archivo" : "")
                 . " WHERE id_examen = :id";
            $stmt_upd = $pdo->prepare($sql);
            $params = [
                ':resultado'     => $resultado,
                ':observaciones' => $observaciones_resultado !== '' ? $observaciones_resultado : null,
                ':id'            => $id_examen,
            ];
            if ($ruta_archivo_nueva !== null) {
                $params[':archivo'] = $ruta_archivo_nueva;
            }
            $stmt_upd->execute($params);

            registrar_bitacora($pdo, 'UPDATE', "Se registró el resultado del examen #$id_examen.");

            header("Location: examenes.php?msg=registrado");
            exit;

        } catch (\Throwable $e) {
            registrar_bitacora($pdo, 'ERROR', "Falló registrar el resultado del examen #$id_examen: " . $e->getMessage());
            $mensaje = $e instanceof RuntimeException ? $e->getMessage() : "Error al registrar el resultado: " . $e->getMessage();
            $tipo_mensaje = "error";
            $id_examen_form_error = $id_examen;
        }
    }
}

// ¿Mostrar el formulario de "registrar resultado" para un examen específico?
// Puede venir de un link (?accion=registrar&id=X) o de haber reabierto el modal tras un error de validación.
$examen_a_registrar = null;
$id_registrar = isset($_GET['accion']) && $_GET['accion'] === 'registrar' && isset($_GET['id'])
    ? intval($_GET['id'])
    : (isset($id_examen_form_error) ? $id_examen_form_error : 0);

if ($id_registrar > 0) {
    $stmt_reg = $pdo->prepare(
        "SELECT e.*, up.nombre AS paciente_nombre, up.apellido AS paciente_apellido
         FROM examenes e
         INNER JOIN pacientes p ON e.id_paciente = p.id_paciente
         INNER JOIN usuarios up ON p.id_usuario = up.id_usuario
         WHERE e.id_examen = :id"
    );
    $stmt_reg->execute([':id' => $id_registrar]);
    $fila = $stmt_reg->fetch();

    if ($fila && ($id_rol === 4 || (int) $fila['id_doctor'] === $mi_id_doctor)) {
        $examen_a_registrar = $fila;
    }
}

// Preservar lo escrito en el formulario de resultado si hubo un error de validación.
$form_resultado = [
    'resultado'     => $_POST['resultado'] ?? ($examen_a_registrar['resultado'] ?? ''),
    'observaciones' => $_POST['observaciones_resultado'] ?? ($examen_a_registrar['observaciones'] ?? ''),
];

// Pre-llenado del formulario "Solicitar examen" cuando se llega desde expediente.php
// (paciente y cita ya conocidos en ese contexto).
$prefill_id_paciente = isset($_GET['id_paciente']) ? intval($_GET['id_paciente']) : 0;
$prefill_id_cita      = isset($_GET['id_cita']) ? intval($_GET['id_cita']) : 0;

// Preservar lo escrito en "Solicitar examen" si hubo un error de validación al guardar.
$form_solicitar = [
    'id_paciente'     => $prefill_id_paciente,
    'id_doctor'       => '',
    'id_cita'         => $prefill_id_cita,
    'tipo_examen'     => '',
    'fecha_solicitud' => date('Y-m-d'),
    'observaciones'   => '',
];
if ($tipo_mensaje === 'error' && isset($_POST['solicitar_examen'])) {
    $form_solicitar = [
        'id_paciente'     => $_POST['id_paciente'] ?? '',
        'id_doctor'       => $_POST['id_doctor'] ?? '',
        'id_cita'         => !empty($_POST['id_cita']) ? intval($_POST['id_cita']) : $prefill_id_cita,
        'tipo_examen'     => $_POST['tipo_examen'] ?? '',
        'fecha_solicitud' => $_POST['fecha_solicitud'] ?? date('Y-m-d'),
        'observaciones'   => $_POST['observaciones'] ?? '',
    ];
}

// CONSULTAS PARA LOS CAMPOS DESPLEGABLES
$stmt_pacientes = $pdo->query("SELECT p.id_paciente, u.nombre, u.apellido, u.cedula
                               FROM pacientes p
                               INNER JOIN usuarios u ON p.id_usuario = u.id_usuario
                               WHERE u.estado = 1 ORDER BY u.nombre ASC");
$pacientes = $stmt_pacientes->fetchAll();

// Solo el administrador necesita elegir el doctor solicitante (el doctor siempre solicita a nombre propio).
$doctores = [];
if ($id_rol === 4) {
    $stmt_doctores = $pdo->query("SELECT d.id_doctor, u.nombre, u.apellido, d.especialidad
                                  FROM doctores d
                                  INNER JOIN usuarios u ON d.id_usuario = u.id_usuario
                                  WHERE u.id_rol = 2 AND u.estado = 1 ORDER BY u.nombre ASC");
    $doctores = $stmt_doctores->fetchAll();
}

// LÓGICA DE BÚSQUEDA, FILTROS Y PAGINACIÓN
$busqueda      = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$estado_filtro = isset($_GET['estado']) && in_array($_GET['estado'], ['solicitado', 'realizado', 'cancelado'], true) ? $_GET['estado'] : '';
$doctor_filtro = ($id_rol === 4 && !empty($_GET['doctor'])) ? intval($_GET['doctor']) : 0;
$fecha_desde   = isset($_GET['desde']) ? trim($_GET['desde']) : '';
$fecha_hasta   = isset($_GET['hasta']) ? trim($_GET['hasta']) : '';

// Armamos la consulta SQL en pedazos ("condiciones"), agregando solo los filtros que el usuario realmente usó.
$condiciones = [];
$params_lista = [];

// Un doctor solo ve sus propios exámenes; el administrador los ve todos.
if ($id_rol === 2) {
    $condiciones[] = "e.id_doctor = :mi_id_doctor";
    $params_lista[':mi_id_doctor'] = $mi_id_doctor;
}

if ($busqueda !== '') {
    $condiciones[] = "(up.nombre LIKE :b1 OR up.apellido LIKE :b2 OR e.tipo_examen LIKE :b3)";
    $param_valor = '%' . $busqueda . '%';
    $params_lista[':b1'] = $param_valor;
    $params_lista[':b2'] = $param_valor;
    $params_lista[':b3'] = $param_valor;
}

if ($estado_filtro !== '') {
    $condiciones[] = "e.estado = :estado";
    $params_lista[':estado'] = $estado_filtro;
}

if ($doctor_filtro > 0) {
    $condiciones[] = "e.id_doctor = :doctor_filtro";
    $params_lista[':doctor_filtro'] = $doctor_filtro;
}

if ($fecha_desde !== '') {
    $condiciones[] = "e.fecha_solicitud >= :desde";
    $params_lista[':desde'] = $fecha_desde;
}

if ($fecha_hasta !== '') {
    $condiciones[] = "e.fecha_solicitud <= :hasta";
    $params_lista[':hasta'] = $fecha_hasta;
}

// Unimos todas las condiciones activas con "AND" para formar el WHERE final.
$where_clause = count($condiciones) > 0 ? ' WHERE ' . implode(' AND ', $condiciones) : '';

$hay_filtros_activos = $busqueda !== '' || $estado_filtro !== '' || $doctor_filtro > 0 || $fecha_desde !== '' || $fecha_hasta !== '';

// Reconstruimos los filtros como texto de URL, para que los links de paginación no los pierdan.
$query_filtros = '';
if ($busqueda !== '') $query_filtros .= '&buscar=' . urlencode($busqueda);
if ($estado_filtro !== '') $query_filtros .= '&estado=' . urlencode($estado_filtro);
if ($doctor_filtro > 0) $query_filtros .= '&doctor=' . $doctor_filtro;
if ($fecha_desde !== '') $query_filtros .= '&desde=' . urlencode($fecha_desde);
if ($fecha_hasta !== '') $query_filtros .= '&hasta=' . urlencode($fecha_hasta);

$limite = 5; // Cuántos exámenes se muestran por página.
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

// Contamos cuántos exámenes hay en total con estos filtros (para saber cuántas páginas hacen falta).
$sql_total = "SELECT COUNT(*)
              FROM examenes e
              INNER JOIN pacientes p ON e.id_paciente = p.id_paciente
              INNER JOIN usuarios up ON p.id_usuario = up.id_usuario"
              . $where_clause;
$stmt_total = $pdo->prepare($sql_total);
$stmt_total->execute($params_lista);
$total_registros = (int) $stmt_total->fetchColumn();

$total_paginas = max(1, (int) ceil($total_registros / $limite));
if ($pagina > $total_paginas) {
    $pagina = $total_paginas;
}
$inicio_int = (int) (($pagina - 1) * $limite); // Desde qué fila empezar (para el LIMIT de SQL).
$limite_int = (int) $limite;

$sql_lista = "SELECT e.*, up.nombre AS paciente_nombre, up.apellido AS paciente_apellido,
                     ud.nombre AS doctor_nombre, ud.apellido AS doctor_apellido
              FROM examenes e
              INNER JOIN pacientes p ON e.id_paciente = p.id_paciente
              INNER JOIN usuarios up ON p.id_usuario = up.id_usuario
              INNER JOIN doctores d ON e.id_doctor = d.id_doctor
              INNER JOIN usuarios ud ON d.id_usuario = ud.id_usuario"
              . $where_clause .
              " ORDER BY e.fecha_solicitud DESC, e.id_examen DESC
              LIMIT $inicio_int, $limite_int";
$stmt_lista = $pdo->prepare($sql_lista);
$stmt_lista->execute($params_lista);
$lista_examenes = $stmt_lista->fetchAll();

$mapa_badge = ['solicitado' => 'pendiente', 'realizado' => 'completada', 'cancelado' => 'cancelada'];

// ¿Auto-abrir algún modal al cargar la página?
$abrir_modal_solicitar = $tipo_mensaje === 'error' && isset($_POST['solicitar_examen']);
$abrir_modal_resultado = $examen_a_registrar !== null;
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exámenes y Radiografías - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/home.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <!-- Estilos del formulario (compartidos con otras páginas) -->
    <link rel="stylesheet" href="assets/css/shared/gestionCitas.css">
    <!-- Estilos exclusivos del dashboard de exámenes -->
    <link rel="stylesheet" href="assets/css/pages/examenes.css">
    <link rel="stylesheet" href="assets/css/shared/toast.css">
</head>

<body>

    <div class="grid-overlay"></div>
    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>

    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <section class="wrap dash-wide">
        <div class="dash-header">
            <div>
                <span class="eyebrow">Dashboard</span>
                <h1>Radiografías y <span class="grad">Exámenes</span></h1>
            </div>
        </div>
    </section>

    <section class="wrap dash-wide">


        <!-- LAYOUT: SIDEBAR DE FILTROS + CONTENIDO -->
        <form action="examenes.php" method="GET" class="dash-layout" id="formFiltros">

            <aside class="dash-sidebar">
                <h3>Filtros</h3>

                <div class="dash-filter-group">
                    <label for="estado">Estado</label>
                    <select name="estado" id="estado" class="form-control">
                        <option value="">Todos</option>
                        <option value="solicitado" <?= $estado_filtro === 'solicitado' ? 'selected' : '' ?>>Solicitado</option>
                        <option value="realizado" <?= $estado_filtro === 'realizado' ? 'selected' : '' ?>>Realizado</option>
                        <option value="cancelado" <?= $estado_filtro === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                    </select>
                </div>

                <?php if ($id_rol === 4): ?>
                    <div class="dash-filter-group">
                        <label for="doctor">Doctor</label>
                        <select name="doctor" id="doctor" class="form-control">
                            <option value="">Todos los doctores</option>
                            <?php foreach ($doctores as $doc): ?>
                                <option value="<?= (int) $doc['id_doctor'] ?>" <?= $doctor_filtro === (int) $doc['id_doctor'] ? 'selected' : '' ?>>
                                    Dr(a). <?= htmlspecialchars($doc['nombre'] . ' ' . $doc['apellido']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="dash-filter-group">
                    <label for="desde">Fecha desde</label>
                    <input type="date" name="desde" id="desde" class="form-control" value="<?= htmlspecialchars($fecha_desde) ?>">
                </div>

                <div class="dash-filter-group">
                    <label for="hasta">Fecha hasta</label>
                    <input type="date" name="hasta" id="hasta" class="form-control" value="<?= htmlspecialchars($fecha_hasta) ?>">
                </div>

                <div class="dash-sidebar-actions">
                    <button type="submit" class="btn-primary">Aplicar filtros</button>
                    <?php if ($hay_filtros_activos): ?>
                        <a href="examenes.php" class="btn-cancel">Limpiar filtros</a>
                    <?php endif; ?>
                </div>
            </aside>

            <main class="dash-main">
                <div class="dash-search">
                    <button type="button" class="dash-fab-text" onclick="abrirModalSolicitar()">
                        <span class="dash-fab-text-plus" aria-hidden="true">
                            <span class="dash-fab-text-plus-icon"></span>
                        </span>
                        Solicitar Examen
                    </button>
                    <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por paciente o tipo de examen..." class="form-control">
                    <button type="submit" class="btn-primary">Buscar</button>
                </div>

                <p class="dash-results-count">
                    <?= $total_registros ?> examen(es) encontrado(s)<?= $hay_filtros_activos ? ' con los filtros aplicados' : '' ?>.
                </p>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Paciente</th>
                                <th>Tipo de Examen</th>
                                <th>Fecha Solicitud</th>
                                <th>Doctor Solicitante</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($lista_examenes) > 0): ?>
                                <?php foreach ($lista_examenes as $ex): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($ex['paciente_nombre'] . ' ' . $ex['paciente_apellido']) ?></strong></td>
                                        <td><?= htmlspecialchars($ex['tipo_examen']) ?></td>
                                        <td><?= date('d/m/Y', strtotime($ex['fecha_solicitud'])) ?></td>
                                        <td>Dr(a). <?= htmlspecialchars($ex['doctor_nombre'] . ' ' . $ex['doctor_apellido']) ?></td>
                                        <td>
                                            <span class="badge badge-<?= $mapa_badge[$ex['estado']] ?>">
                                                <?= ucfirst($ex['estado']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="dash-actions">
                                                <?php $puede_gestionar = $id_rol === 4 || (int) $ex['id_doctor'] === $mi_id_doctor; ?>
                                                <?php if ($ex['estado'] === 'solicitado' && $puede_gestionar): ?>
                                                    <a href="examenes.php?accion=registrar&id=<?= $ex['id_examen'] ?>" class="btn-action btn-edit">Registrar resultado</a>
                                                    <a href="#" class="btn-action btn-delete" onclick="confirmarCancelacion('examenes.php?accion=cancelar&id=<?= $ex['id_examen'] ?>', '<?= htmlspecialchars(addslashes($ex['tipo_examen'])) ?>'); return false;">Cancelar</a>
                                                <?php elseif ($ex['estado'] === 'realizado'): ?>
                                                    <?php if ($puede_gestionar): ?>
                                                        <a href="examenes.php?accion=registrar&id=<?= $ex['id_examen'] ?>" class="btn-action btn-edit">Editar resultado</a>
                                                    <?php endif; ?>
                                                    <?php if (!empty($ex['archivo'])): ?>
                                                        <a href="ver_archivo_examen.php?id=<?= $ex['id_examen'] ?>" target="_blank" rel="noopener" class="btn-action btn-edit">Ver archivo</a>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="muted-text">—</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="muted-text text-center">No se encontraron exámenes.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_paginas > 1): ?>
                    <div class="pagination-container">
                        <span class="pagination-info">Página <strong><?= $pagina ?></strong> de <strong><?= $total_paginas ?></strong> (<?= $total_registros ?> exámenes)</span>
                        <div class="pagination">
                            <?php if ($pagina > 1): ?>
                                <a href="examenes.php?pagina=<?= $pagina - 1 ?><?= $query_filtros ?>" class="page-link">&laquo; Anterior</a>
                            <?php endif; ?>
                            <?php foreach (obtener_paginas_visibles($pagina, $total_paginas) as $item): ?>
                                <?php if ($item === '...'): ?>
                                    <span class="page-ellipsis">&hellip;</span>
                                <?php else: ?>
                                    <a href="examenes.php?pagina=<?= $item ?><?= $query_filtros ?>" class="page-link <?= $item === $pagina ? 'active' : '' ?>"><?= $item ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($pagina < $total_paginas): ?>
                                <a href="examenes.php?pagina=<?= $pagina + 1 ?><?= $query_filtros ?>" class="page-link">Siguiente &raquo;</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </form>
    </section>

    <!-- MODAL: SOLICITAR EXAMEN -->
    <div id="modalSolicitar" class="dash-modal-overlay">
        <div class="dash-modal-card" style="max-width: 560px;">
            <div class="dash-modal-head">
                <button type="button" class="dash-modal-close" onclick="cerrarModalSolicitar()" aria-label="Cerrar">&times;</button>
                <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                    <span class="eyebrow">Nueva solicitud</span>
                    <h2 style="margin:4px 0 0;">Solicitar Examen</h2>
                </div>
            </div>

            <form action="examenes.php" method="POST">
                <input type="hidden" name="solicitar_examen" value="1">
                <?php if ($form_solicitar['id_cita'] > 0): ?>
                    <input type="hidden" name="id_cita" value="<?= (int) $form_solicitar['id_cita'] ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="id_paciente"><span class="dash-req">*</span> Paciente</label>
                        <select name="id_paciente" id="id_paciente" required class="form-control">
                            <option value="">-- Selecciona un paciente --</option>
                            <?php foreach ($pacientes as $pac): ?>
                                <option value="<?= $pac['id_paciente'] ?>" <?= (string) $form_solicitar['id_paciente'] === (string) $pac['id_paciente'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pac['nombre'] . ' ' . $pac['apellido'] . ' (Cédula: ' . $pac['cedula'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($id_rol === 4): ?>
                        <div class="form-group">
                            <label for="id_doctor"><span class="dash-req">*</span> Doctor solicitante</label>
                            <select name="id_doctor" id="id_doctor" required class="form-control">
                                <option value="">-- Selecciona un doctor --</option>
                                <?php foreach ($doctores as $doc): ?>
                                    <option value="<?= $doc['id_doctor'] ?>" <?= (string) $form_solicitar['id_doctor'] === (string) $doc['id_doctor'] ? 'selected' : '' ?>>
                                        Dr(a). <?= htmlspecialchars($doc['nombre'] . ' ' . $doc['apellido'] . ' - ' . $doc['especialidad']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="tipo_examen"><span class="dash-req">*</span> Tipo de examen</label>
                        <input type="text" id="tipo_examen" name="tipo_examen" list="tipos_examen" maxlength="100" required placeholder="Ej: Radiografía panorámica" value="<?= htmlspecialchars($form_solicitar['tipo_examen']) ?>" class="form-control">
                        <datalist id="tipos_examen">
                            <option value="Radiografía panorámica">
                            <option value="Radiografía periapical">
                            <option value="Radiografía bitewing (aleta de mordida)">
                            <option value="Radiografía oclusal">
                            <option value="Tomografía (CBCT)">
                            <option value="Escaneo intraoral">
                        </datalist>
                    </div>

                    <div class="form-group">
                        <label for="fecha_solicitud">Fecha de solicitud</label>
                        <input type="date" id="fecha_solicitud" name="fecha_solicitud" value="<?= htmlspecialchars($form_solicitar['fecha_solicitud']) ?>" max="<?= date('Y-m-d') ?>" class="form-control">
                    </div>

                    <div class="form-group col-full">
                        <label for="observaciones">Motivo / Observaciones <span class="dash-hint">(opcional)</span></label>
                        <textarea id="observaciones" name="observaciones" rows="2" placeholder="Ej: Sospecha de caries interproximal en pieza 36." class="form-control"><?= htmlspecialchars($form_solicitar['observaciones']) ?></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModalSolicitar()">Cancelar</button>
                    <button type="submit" class="btn-primary btn-guardar">Solicitar Examen</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: REGISTRAR RESULTADO -->
    <div id="modalResultado" class="dash-modal-overlay">
        <div class="dash-modal-card" style="max-width: 560px;">
            <div class="dash-modal-head">
                <button type="button" class="dash-modal-close" onclick="cerrarModalResultado()" aria-label="Cerrar">&times;</button>
                <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                    <span class="eyebrow">Examen <?= $examen_a_registrar ? '#' . (int) $examen_a_registrar['id_examen'] : '' ?></span>
                    <h2 style="margin:4px 0 0;">Registrar Resultado</h2>
                </div>
            </div>

            <?php if ($examen_a_registrar): ?>
                <p class="muted-text" style="text-align:center; margin-bottom:18px; font-size:0.9rem;">
                    <?= htmlspecialchars($examen_a_registrar['tipo_examen']) ?> ·
                    Paciente: <?= htmlspecialchars($examen_a_registrar['paciente_nombre'] . ' ' . $examen_a_registrar['paciente_apellido']) ?> ·
                    Solicitado el <?= date('d/m/Y', strtotime($examen_a_registrar['fecha_solicitud'])) ?>
                </p>


                <form action="examenes.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="registrar_resultado" value="1">
                    <input type="hidden" name="id_examen" value="<?= (int) $examen_a_registrar['id_examen'] ?>">

                    <div class="form-grid">
                        <div class="form-group col-full">
                            <label for="resultado"><span class="dash-req">*</span> Resultado</label>
                            <textarea id="resultado" name="resultado" rows="4" required placeholder="Hallazgos del examen..." class="form-control"><?= htmlspecialchars($form_resultado['resultado']) ?></textarea>
                        </div>

                        <div class="form-group col-full">
                            <label for="observaciones_resultado">Observaciones <span class="dash-hint">(opcional)</span></label>
                            <textarea id="observaciones_resultado" name="observaciones_resultado" rows="3" placeholder="Notas adicionales..." class="form-control"><?= htmlspecialchars($form_resultado['observaciones']) ?></textarea>
                        </div>

                        <div class="form-group col-full">
                            <label for="archivo">Adjuntar archivo <span class="dash-hint">(JPG, PNG o PDF, máx. 5 MB)</span></label>
                            <input type="file" id="archivo" name="archivo" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" class="form-control">
                            <?php if (!empty($examen_a_registrar['archivo'])): ?>
                                <p class="muted-text" style="margin-top:8px; font-size:0.85rem;">
                                    Ya hay un archivo adjunto —
                                    <a href="ver_archivo_examen.php?id=<?= (int) $examen_a_registrar['id_examen'] ?>" target="_blank" rel="noopener" style="color: var(--cyan);">verlo</a>.
                                    Sube uno nuevo para reemplazarlo.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-cancel" onclick="cerrarModalResultado()">Cancelar</button>
                        <button type="submit" class="btn-primary btn-guardar">Guardar Resultado</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- MODAL: CONFIRMAR CANCELACIÓN -->
    <div id="modalConfirmacion" class="custom-modal-overlay">
        <div class="custom-modal-card">
            <h3>¿Cancelar examen?</h3>
            <p id="modalMensajeTexto">¿Estás seguro de que deseas cancelar esta solicitud de examen?</p>
            <div class="custom-modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="cerrarModal()">Volver</button>
                <a id="btnConfirmarModal" href="#" class="btn-modal-confirm">Cancelar examen</a>
            </div>
        </div>
    </div>

    <?php require_once 'assets/includes/partials/footer.php'; ?>

    <script>
        // MODAL: SOLICITAR EXAMEN
        const modalSolicitar = document.getElementById('modalSolicitar');

        function abrirModalSolicitar() {
            modalSolicitar.classList.add('active');
        }

        function cerrarModalSolicitar() {
            modalSolicitar.classList.remove('active');
        }

        modalSolicitar.addEventListener('click', function (e) {
            if (e.target === this) cerrarModalSolicitar();
        });

        <?php if ($abrir_modal_solicitar): ?>
            abrirModalSolicitar();
        <?php endif; ?>

        // MODAL: REGISTRAR RESULTADO
        const modalResultado = document.getElementById('modalResultado');

        function cerrarModalResultado() {
            modalResultado.classList.remove('active');
            window.location.href = 'examenes.php';
        }

        modalResultado.addEventListener('click', function (e) {
            if (e.target === this) cerrarModalResultado();
        });

        <?php if ($abrir_modal_resultado): ?>
            modalResultado.classList.add('active');
        <?php endif; ?>

        // MODAL DE CONFIRMACIÓN DE CANCELACIÓN
        function confirmarCancelacion(url, tipoExamen) {
            var mensaje = '¿Deseas cancelar la solicitud de examen "' + tipoExamen + '"? Esta acción no se puede deshacer.';
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
                cerrarModalSolicitar();
                cerrarModal();
            }
        });
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
