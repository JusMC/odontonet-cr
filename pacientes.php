<?php
/*
    pacientes.php — Vista de pacientes (dashboard).

    Permite a recepción y administración BUSCAR pacientes ya registrados
    (por nombre, cédula o correo) y actualizar sus datos de contacto y
    clínicos (antecedentes médicos, alergias) sobre el mismo registro,
    en vez de crear una cuenta nueva y duplicar al paciente.

    No crea pacientes nuevos ni cambia roles/contraseñas — eso sigue
    siendo exclusivo de Gestión de Usuarios (solo administrador). Esta
    página solo actualiza el registro de un paciente que ya existe.
*/

session_start();
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/bitacora_helper.php';
require_once './assets/includes/helpers/paginacion_helper.php';

// Guard: administrador (id_rol = 4) o recepcionista (id_rol = 3).
$id_rol_sesion = (int) ($_SESSION['id_rol'] ?? 0);
if (!in_array($id_rol_sesion, [3, 4], true)) {
    header('Location: login.php');
    exit;
}

$mensaje = '';
$tipo_mensaje = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'actualizado') {
    $mensaje = 'Datos del paciente actualizados correctamente.';
    $tipo_mensaje = 'exito';
}

// VARIABLES PARA EDICIÓN (precargan el modal)
$modo_edicion = false;
$paciente_edit = [
    'id_usuario' => '', 'nombre' => '', 'apellido' => '', 'cedula' => '',
    'correo' => '', 'telefono' => '', 'fecha_nacimiento' => '', 'direccion' => '',
    'antecedentes_medicos' => '', 'alergias' => '',
];

// Si viene un ?accion=editar&id=X en la URL, precargamos el modal con los datos de ese paciente.
if (isset($_GET['accion']) && $_GET['accion'] === 'editar' && isset($_GET['id'])) {
    $id_editar = intval($_GET['id']);

    $stmt_edit = $pdo->prepare(
        "SELECT u.id_usuario, u.nombre, u.apellido, u.cedula, u.correo, u.telefono,
                u.fecha_nacimiento, u.direccion, p.antecedentes_medicos, p.alergias
         FROM usuarios u
         INNER JOIN pacientes p ON p.id_usuario = u.id_usuario
         WHERE u.id_usuario = :id AND u.id_rol = 1"
    );
    $stmt_edit->execute([':id' => $id_editar]);
    $datos = $stmt_edit->fetch();

    if ($datos) {
        $modo_edicion = true;
        $paciente_edit = $datos;
    }
}

// PROCESAR FORMULARIO DE EDICIÓN
// Si el modal se envió con los datos editados del paciente...
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_paciente'])) {
    $id_usuario = intval($_POST['id_usuario'] ?? 0);
    $nombre     = trim($_POST['nombre'] ?? '');
    $apellido   = trim($_POST['apellido'] ?? '');
    $cedula     = trim($_POST['cedula'] ?? '');
    $correo     = trim($_POST['correo'] ?? '');
    $telefono   = trim($_POST['telefono'] ?? '');
    $fecha_nac  = trim($_POST['fecha_nacimiento'] ?? '');
    $direccion  = trim($_POST['direccion'] ?? '');
    $antecedentes_medicos = trim($_POST['antecedentes_medicos'] ?? '');
    $alergias   = trim($_POST['alergias'] ?? '');

    $errores_validacion = [];

    // Defensa en profundidad: este formulario solo puede tocar cuentas con rol paciente.
    $stmt_chk_rol = $pdo->prepare('SELECT id_rol, cedula FROM usuarios WHERE id_usuario = :id');
    $stmt_chk_rol->execute([':id' => $id_usuario]);
    $fila_objetivo = $stmt_chk_rol->fetch();
    if (!$fila_objetivo || (int) $fila_objetivo['id_rol'] !== 1) {
        $errores_validacion[] = 'El registro seleccionado no corresponde a un paciente.';
    }

    // Recepción puede actualizar el resto de los datos, pero no la cédula
    // (documento de identidad) — solo administración puede corregirla. Esto
    // se cumple aquí en el servidor, no solo deshabilitando el campo en el
    // formulario, por si alguien intenta forzar un envío con otro valor.
    if ($id_rol_sesion === 3 && $fila_objetivo) {
        $cedula = $fila_objetivo['cedula'] ?? '';
    }

    // Validaciones normales de formato (mismas reglas que en registro.php/guardar.php).
    if (empty($errores_validacion)) {
        $regex_nombre = '/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]{2,50}$/';

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
    }

    // Duplicados: correo y cédula (excluyendo al propio paciente)
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

    if (!empty($errores_validacion)) {
        $mensaje = implode(' ', $errores_validacion);
        $tipo_mensaje = 'error';

        // Mantener los datos ingresados en el formulario tras el error (reabre el modal precargado).
        $modo_edicion = true;
        $paciente_edit = [
            'id_usuario' => $id_usuario, 'nombre' => $nombre, 'apellido' => $apellido,
            'cedula' => $cedula, 'correo' => $correo, 'telefono' => $telefono,
            'fecha_nacimiento' => $fecha_nac, 'direccion' => $direccion,
            'antecedentes_medicos' => $antecedentes_medicos, 'alergias' => $alergias,
        ];
    } else {
        // Sin errores: guardamos los cambios.
        try {
            $pdo->beginTransaction();

            // Datos de cuenta (nombre, cédula, contacto) en "usuarios"...
            $stmt_u = $pdo->prepare(
                "UPDATE usuarios SET nombre = :nombre, apellido = :apellido, cedula = :cedula,
                    fecha_nacimiento = :fecha_nacimiento, direccion = :direccion, correo = :correo, telefono = :telefono
                 WHERE id_usuario = :id"
            );
            $stmt_u->execute([
                ':nombre'           => $nombre,
                ':apellido'         => $apellido,
                ':cedula'           => $cedula,
                ':fecha_nacimiento' => $fecha_nac !== '' ? $fecha_nac : null,
                ':direccion'        => $direccion !== '' ? $direccion : null,
                ':correo'           => $correo,
                ':telefono'         => $telefono !== '' ? $telefono : null,
                ':id'               => $id_usuario,
            ]);

            // ...y los datos médicos (antecedentes, alergias) en "pacientes".
            $stmt_p = $pdo->prepare(
                "UPDATE pacientes SET antecedentes_medicos = :antecedentes, alergias = :alergias WHERE id_usuario = :id"
            );
            $stmt_p->execute([
                ':antecedentes' => $antecedentes_medicos !== '' ? $antecedentes_medicos : null,
                ':alergias'     => $alergias !== '' ? $alergias : null,
                ':id'           => $id_usuario,
            ]);

            $pdo->commit();

            registrar_bitacora($pdo, 'UPDATE', "Se actualizó el registro del paciente #$id_usuario ($nombre $apellido).");
            header('Location: pacientes.php?msg=actualizado');
            exit;
        } catch (\PDOException $e) {
            $pdo->rollBack();
            registrar_bitacora($pdo, 'ERROR', "Falló actualizar el registro del paciente #$id_usuario: " . $e->getMessage());
            $mensaje = 'Error al actualizar el paciente: ' . $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }
}

$abrir_modal_formulario = $modo_edicion || ($tipo_mensaje === 'error' && isset($_POST['guardar_paciente']));

// LÓGICA DE BÚSQUEDA, FILTROS Y PAGINACIÓN
$busqueda      = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$estado_filtro = isset($_GET['estado']) && in_array($_GET['estado'], ['1', '0'], true) ? $_GET['estado'] : '';

// Armamos la consulta SQL en pedazos ("condiciones"). Siempre filtramos por rol paciente primero.
$condiciones = ['u.id_rol = 1'];
$params = [];

if ($busqueda !== '') {
    $condiciones[] = "(u.nombre LIKE :b1 OR u.apellido LIKE :b2 OR u.cedula LIKE :b3 OR u.correo LIKE :b4)";
    $param_valor = '%' . $busqueda . '%';
    $params[':b1'] = $param_valor;
    $params[':b2'] = $param_valor;
    $params[':b3'] = $param_valor;
    $params[':b4'] = $param_valor;
}
if ($estado_filtro !== '') {
    $condiciones[] = 'u.estado = :estado';
    $params[':estado'] = (int) $estado_filtro;
}

$where = ' WHERE ' . implode(' AND ', $condiciones);
$hay_filtros_activos = $busqueda !== '' || $estado_filtro !== '';

// Reconstruimos los filtros como texto de URL, para que los links de paginación no los pierdan.
$query_filtros = '';
if ($busqueda !== '') $query_filtros .= '&buscar=' . urlencode($busqueda);
if ($estado_filtro !== '') $query_filtros .= '&estado=' . urlencode($estado_filtro);

$limite = 8; // Cuántos pacientes se muestran por página.
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

// Contamos cuántos pacientes hay en total con estos filtros (para saber cuántas páginas hacen falta).
$sql_total = "SELECT COUNT(*) FROM usuarios u" . $where;
$stmt_total = $pdo->prepare($sql_total);
$stmt_total->execute($params);
$total_registros = (int) $stmt_total->fetchColumn();

$total_paginas = max(1, (int) ceil($total_registros / $limite));
if ($pagina > $total_paginas) {
    $pagina = $total_paginas;
}
$inicio_int = (int) (($pagina - 1) * $limite); // Desde qué fila empezar (para el LIMIT de SQL).
$limite_int = (int) $limite;

// La lista de pacientes de esta página, con la edad ya calculada.
$sql_pacientes = "SELECT u.id_usuario, u.nombre, u.apellido, u.cedula, u.correo, u.telefono, u.estado,
                          TIMESTAMPDIFF(YEAR, u.fecha_nacimiento, CURDATE()) AS edad
                   FROM usuarios u"
                   . $where .
                   " ORDER BY u.nombre ASC, u.apellido ASC
                   LIMIT $inicio_int, $limite_int";
$stmt_pacientes = $pdo->prepare($sql_pacientes);
$stmt_pacientes->execute($params);
$pacientes_lista = $stmt_pacientes->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pacientes - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/home.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <link rel="stylesheet" href="assets/css/shared/pacientes_usuarios.css">
    <link rel="stylesheet" href="assets/css/pages/pacientes.css">
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
                <h1>Vista de <span class="grad">Pacientes</span></h1>
            </div>
        </div>
    </section>

    <section class="wrap dash-wide">
        <form action="pacientes.php" method="GET" class="dash-layout" id="formFiltros">

            <aside class="dash-sidebar">
                <h3>Filtros</h3>

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
                        <a href="pacientes.php" class="btn-cancel">Limpiar filtros</a>
                    <?php endif; ?>
                </div>
            </aside>

            <main class="dash-main">
                <div class="dash-search">
                    <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por nombre, cédula o correo..." class="form-control">
                    <button type="submit" class="btn-primary">Buscar</button>
                </div>

                <p class="dash-hint-nota">Busca al paciente antes de registrar uno nuevo — así actualizas su expediente en vez de duplicarlo. El alta de cuentas nuevas sigue siendo desde Gestión de Usuarios.</p>

                <p class="dash-results-count">
                    <?= $total_registros ?> paciente(s) encontrado(s)<?= $hay_filtros_activos ? ' con los filtros aplicados' : '' ?>.
                </p>

                <?php if (count($pacientes_lista) === 0): ?>
                    <div class="card" style="padding: 32px; text-align: center; color: var(--muted);">
                        No hay pacientes registrados con estos filtros.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Paciente</th>
                                    <th>Cédula</th>
                                    <th>Contacto</th>
                                    <th>Edad</th>
                                    <th>Estado</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pacientes_lista as $pac): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($pac['nombre'] . ' ' . $pac['apellido']) ?></td>
                                        <td><?= htmlspecialchars($pac['cedula'] ?? 'N/A') ?></td>
                                        <td>
                                            <?= htmlspecialchars($pac['correo']) ?>
                                            <?php if (!empty($pac['telefono'])): ?><br><small style="color: var(--muted);"><?= htmlspecialchars($pac['telefono']) ?></small><?php endif; ?>
                                        </td>
                                        <td><?= $pac['edad'] !== null ? (int) $pac['edad'] . ' años' : 'N/A' ?></td>
                                        <td>
                                            <span class="badge badge-<?= (int) $pac['estado'] === 1 ? 'success' : 'cancelada' ?>">
                                                <?= (int) $pac['estado'] === 1 ? 'Activo' : 'Inactivo' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="pacientes.php?accion=editar&id=<?= $pac['id_usuario'] ?>" class="btn-action btn-edit">Ver / Editar</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_paginas > 1): ?>
                        <div class="pagination-container">
                            <span class="pagination-info">Página <strong><?= $pagina ?></strong> de <strong><?= $total_paginas ?></strong> (<?= $total_registros ?> pacientes)</span>
                            <div class="pagination">
                                <?php if ($pagina > 1): ?>
                                    <a href="pacientes.php?pagina=<?= $pagina - 1 ?><?= $query_filtros ?>" class="page-link">&laquo; Anterior</a>
                                <?php endif; ?>
                                <?php foreach (obtener_paginas_visibles($pagina, $total_paginas) as $item): ?>
                                    <?php if ($item === '...'): ?>
                                        <span class="page-ellipsis">&hellip;</span>
                                    <?php else: ?>
                                        <a href="pacientes.php?pagina=<?= $item ?><?= $query_filtros ?>" class="page-link <?= $item === $pagina ? 'active' : '' ?>"><?= $item ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if ($pagina < $total_paginas): ?>
                                    <a href="pacientes.php?pagina=<?= $pagina + 1 ?><?= $query_filtros ?>" class="page-link">Siguiente &raquo;</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </main>
        </form>
    </section>

    <!-- MODAL: EDITAR PACIENTE -->
    <div id="modalPaciente" class="dash-modal-overlay<?= $abrir_modal_formulario ? ' active' : '' ?>">
        <div class="dash-modal-card">
            <div class="dash-modal-head">
                <button type="button" class="dash-modal-close" onclick="cerrarModalPaciente()" aria-label="Cerrar">&times;</button>
                <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                    <span class="eyebrow">Registro existente</span>
                    <h2 style="margin:4px 0 0;">Datos del Paciente</h2>
                </div>
            </div>

            <form method="post" action="pacientes.php">
                <input type="hidden" id="id_usuario" name="id_usuario" value="<?= htmlspecialchars($paciente_edit['id_usuario']) ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="cedula"><span class="dash-req">*</span> Cédula<?= $id_rol_sesion === 3 ? ' <span class="dash-hint">(solo administración puede corregirla)</span>' : '' ?></label>
                        <input type="text" id="cedula" name="cedula" value="<?= htmlspecialchars($paciente_edit['cedula'] ?? '') ?>" required maxlength="9" inputmode="numeric" pattern="[0-9]{9}" class="form-control" title="Debe tener exactamente 9 dígitos numéricos, sin guiones ni espacios." <?= $id_rol_sesion === 3 ? 'readonly' : '' ?>>
                    </div>
                    <div class="form-group">
                        <label for="nombre"><span class="dash-req">*</span> Nombre</label>
                        <input type="text" id="nombre" name="nombre" value="<?= htmlspecialchars($paciente_edit['nombre']) ?>" required maxlength="50" pattern="[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+" class="form-control" title="Solo letras y espacios (2 a 50 caracteres).">
                    </div>
                    <div class="form-group">
                        <label for="apellido"><span class="dash-req">*</span> Apellido</label>
                        <input type="text" id="apellido" name="apellido" value="<?= htmlspecialchars($paciente_edit['apellido']) ?>" required maxlength="50" pattern="[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+" class="form-control" title="Solo letras y espacios (2 a 50 caracteres).">
                    </div>
                    <div class="form-group">
                        <label for="correo"><span class="dash-req">*</span> Correo electrónico</label>
                        <input type="email" id="correo" name="correo" value="<?= htmlspecialchars($paciente_edit['correo'] ?? '') ?>" required maxlength="150" class="form-control" title="Debe ser un correo electrónico válido.">
                    </div>
                    <div class="form-group">
                        <label for="telefono">Teléfono <span class="dash-hint">(opcional)</span></label>
                        <input type="tel" id="telefono" name="telefono" value="<?= htmlspecialchars($paciente_edit['telefono'] ?? '') ?>" placeholder="+506 8888-8888" pattern="[0-9]{8,15}" inputmode="numeric" class="form-control" title="Solo números, sin espacios ni guiones (8 a 15 dígitos).">
                    </div>
                    <div class="form-group">
                        <label for="fecha_nacimiento">Fecha de nacimiento <span class="dash-hint">(opcional)</span></label>
                        <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" value="<?= htmlspecialchars($paciente_edit['fecha_nacimiento'] ?? '') ?>" min="1876-01-01" max="<?= date('Y-m-d') ?>" class="form-control">
                    </div>
                    <div class="form-group col-full">
                        <label for="direccion">Dirección <span class="dash-hint">(opcional)</span></label>
                        <input type="text" id="direccion" name="direccion" value="<?= htmlspecialchars($paciente_edit['direccion'] ?? '') ?>" placeholder="Provincia, Cantón, Distrito..." maxlength="255" class="form-control">
                    </div>
                    <div class="form-group col-full">
                        <label for="antecedentes_medicos">Antecedentes médicos <span class="dash-hint">(opcional)</span></label>
                        <textarea id="antecedentes_medicos" name="antecedentes_medicos" rows="3" placeholder="Ej: Hipertensión, diabetes, cirugías previas..." class="form-control"><?= htmlspecialchars($paciente_edit['antecedentes_medicos'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group col-full">
                        <label for="alergias">Alergias <span class="dash-hint">(opcional)</span></label>
                        <textarea id="alergias" name="alergias" rows="3" placeholder="Ej: Penicilina, látex..." class="form-control"><?= htmlspecialchars($paciente_edit['alergias'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModalPaciente()">Cancelar</button>
                    <button type="submit" name="guardar_paciente" class="btn-primary btn-submit">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>

    <?php require_once 'assets/includes/partials/footer.php'; ?>

    <script>
        const modalPaciente = document.getElementById('modalPaciente');

        // Cerrar el modal recarga la página sin el "?accion=editar", así vuelve a la lista limpia.
        function cerrarModalPaciente() {
            modalPaciente.classList.remove('active');
            window.location.href = 'pacientes.php';
        }

        // Clic fuera de la tarjeta del modal (en el fondo oscuro) también lo cierra.
        modalPaciente.addEventListener('click', function (e) {
            if (e.target === this) cerrarModalPaciente();
        });

        // Tecla Escape también lo cierra.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') cerrarModalPaciente();
        });
    </script>

    <!-- NOTIFICACIÓN TEMPORAL -->
    <div id="toastNotificacion" class="toast-notification" role="alert" aria-live="assertive">
        <span class="toast-icon" aria-hidden="true"></span>
        <div class="toast-body">
            <strong id="toastTitulo"></strong>
            <p id="toastMensaje"></p>
        </div>
        <button type="button" class="toast-close" onclick="cerrarToast()" aria-label="Cerrar notificación">&times;</button>
    </div>

    <script src="assets/js/shared/toast.js"></script>
    <?php if (!empty($mensaje) && !$abrir_modal_formulario): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                mostrarToast(<?= json_encode($mensaje) ?>, <?= json_encode($tipo_mensaje === 'exito' ? 'exito' : 'error') ?>);
            });
        </script>
    <?php endif; ?>

</body>
</html>
