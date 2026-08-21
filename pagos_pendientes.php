<?php
/*
    pagos_pendientes.php — Control de pagos pendientes.

    Muestra las facturas que no se cancelaron por completo al momento de
    recibir el servicio (facturar_servicios.php, pago parcial): monto
    total, monto abonado, saldo pendiente, fecha límite de pago, estado de
    la deuda y su relación con la factura correspondiente (ventas).

    Permite registrar abonos hasta saldar la deuda; al saldarse, la venta
    vinculada se marca automáticamente como 'pagada'.
*/

require_once __DIR__ . '/assets/includes/session_boot.php';
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/paginacion_helper.php';

// Guard: administrador (id_rol = 4) o recepcionista (id_rol = 3).
if (!isset($_SESSION['id_rol']) || !in_array((int) $_SESSION['id_rol'], [3, 4], true)) {
    header('Location: login.php');
    exit;
}

$mensaje = "";
$tipo_mensaje = "";

// Se revisa en cada carga de la página: si una deuda ya pasó su fecha límite y sigue con saldo, se marca 'vencido'.
try {
    $pdo->exec("
        UPDATE pagos_pendientes
        SET estado = 'vencido'
        WHERE fecha_limite_pago < CURDATE()
          AND saldo_pendiente > 0
          AND estado NOT IN ('vencido', 'pagado')
    ");
} catch (PDOException $e) { /* silencioso */ }

// REGISTRAR ABONO
// Si se envió el modal "Registrar Abono" con un pago a una deuda...
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_abono'])) {
    $id_pago_pendiente = intval($_POST['id_pago_pendiente'] ?? 0);
    $monto_abono       = (float) ($_POST['monto'] ?? 0);
    $metodo_pago_abono = $_POST['metodo_pago'] ?? '';
    $metodos_validos   = ['efectivo', 'tarjeta', 'transferencia'];

    if (!in_array($metodo_pago_abono, $metodos_validos, true)) {
        $mensaje = "Selecciona un método de pago válido.";
        $tipo_mensaje = "error";
    } else {
        // La validación de la deuda/monto, el registro del abono, la
        // actualización del saldo y el posible cierre de la venta como
        // 'pagada' ocurren dentro de sp_registrar_abono (stored procedure
        // con su propia transacción y rollback ante cualquier error).
        try {
            // Llamamos al procedimiento que valida el monto, actualiza el saldo y guarda el abono.
            $stmt_call = $pdo->prepare('CALL sp_registrar_abono(:id, :monto, :metodo, :usuario, @id_abono, @saldo, @estado)');
            $stmt_call->execute([
                ':id'      => $id_pago_pendiente,
                ':monto'   => $monto_abono,
                ':metodo'  => $metodo_pago_abono,
                ':usuario' => (int) $_SESSION['usuario_id'],
            ]);
            $stmt_call->closeCursor();

            // Recuperamos el id del abono recién creado.
            $salida = $pdo->query('SELECT @id_abono AS id_abono')->fetch();
            $id_abono_nuevo = (int) $salida['id_abono'];

            // Cada abono genera su propio comprobante, en vez de dejar una
            // única factura para toda la deuda sin registro por pago.
            header("Location: ver_factura.php?id_abono=" . $id_abono_nuevo);
            exit;
        } catch (\PDOException $e) {
            // sp_registrar_abono usa SIGNAL para mensajes de validación
            // (deuda inexistente, monto inválido o mayor al saldo); ese
            // texto ya es apto para mostrarlo directamente al usuario.
            $mensaje = $e->errorInfo[2] ?? 'Ocurrió un error al registrar el abono.';
            $tipo_mensaje = "error";
        }
    }
}

// ¿El registro de abono falló? Si es así, el modal se vuelve a abrir
// automáticamente con los datos de esa misma deuda precargados.
$abrir_modal_abono = $tipo_mensaje === 'error' && isset($_POST['registrar_abono']);
$modal_abono_data = null;
if ($abrir_modal_abono) {
    // Volvemos a traer los datos de esa deuda, para que el modal se reabra con el nombre y saldo correctos.
    $stmt_deuda_error = $pdo->prepare(
        "SELECT pp.id_pago_pendiente, pp.saldo_pendiente, up.nombre AS paciente_nombre, up.apellido AS paciente_apellido
         FROM pagos_pendientes pp
         INNER JOIN ventas v ON pp.id_venta = v.id_venta
         INNER JOIN pacientes p ON v.id_paciente = p.id_paciente
         INNER JOIN usuarios up ON p.id_usuario = up.id_usuario
         WHERE pp.id_pago_pendiente = :id"
    );
    $stmt_deuda_error->execute([':id' => intval($_POST['id_pago_pendiente'] ?? 0)]);
    $modal_abono_data = $stmt_deuda_error->fetch();
}

// FILTROS Y PAGINACIÓN
$estado_filtro = isset($_GET['estado']) && in_array($_GET['estado'], ['pendiente', 'abonado', 'vencido', 'pagado'], true) ? $_GET['estado'] : '';
$busqueda      = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';

// Armamos la consulta SQL en pedazos ("condiciones"), agregando solo los filtros que el usuario realmente usó.
$condiciones = [];
$params = [];

if ($estado_filtro !== '') {
    $condiciones[] = "pp.estado = :estado";
    $params[':estado'] = $estado_filtro;
} else {
    // Por defecto, ocultar las ya saldadas para enfocarse en lo que falta cobrar.
    $condiciones[] = "pp.estado != 'pagado'";
}

if ($busqueda !== '') {
    $condiciones[] = "(up.nombre LIKE :b1 OR up.apellido LIKE :b2 OR up.cedula LIKE :b3)";
    $param_valor = '%' . $busqueda . '%';
    $params[':b1'] = $param_valor;
    $params[':b2'] = $param_valor;
    $params[':b3'] = $param_valor;
}

// Unimos todas las condiciones activas con "AND" para formar el WHERE final.
$where = count($condiciones) > 0 ? ' WHERE ' . implode(' AND ', $condiciones) : '';

$hay_filtros_activos = $estado_filtro !== '' || $busqueda !== '';

// Reconstruimos los filtros como texto de URL, para que los links de paginación no los pierdan.
$query_filtros = '';
if ($estado_filtro !== '') $query_filtros .= '&estado=' . urlencode($estado_filtro);
if ($busqueda !== '') $query_filtros .= '&buscar=' . urlencode($busqueda);

$limite = 5; // Cuántas deudas se muestran por página.
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

// Contamos cuántas deudas hay en total con estos filtros (para saber cuántas páginas hacen falta).
$sql_total = "SELECT COUNT(*)
              FROM pagos_pendientes pp
              INNER JOIN ventas v ON pp.id_venta = v.id_venta
              INNER JOIN pacientes p ON v.id_paciente = p.id_paciente
              INNER JOIN usuarios up ON p.id_usuario = up.id_usuario"
              . $where;
$stmt_total = $pdo->prepare($sql_total);
$stmt_total->execute($params);
$total_registros = (int) $stmt_total->fetchColumn();

$total_paginas = max(1, (int) ceil($total_registros / $limite));
if ($pagina > $total_paginas) {
    $pagina = $total_paginas;
}
$inicio_int = (int) (($pagina - 1) * $limite); // Desde qué fila empezar (para el LIMIT de SQL).
$limite_int = (int) $limite;

// Las deudas de esta página, con las vencidas primero y luego ordenadas por fecha límite más próxima.
$sql_lista = "SELECT pp.*, up.nombre AS paciente_nombre, up.apellido AS paciente_apellido, up.cedula
              FROM pagos_pendientes pp
              INNER JOIN ventas v ON pp.id_venta = v.id_venta
              INNER JOIN pacientes p ON v.id_paciente = p.id_paciente
              INNER JOIN usuarios up ON p.id_usuario = up.id_usuario"
              . $where .
              " ORDER BY pp.estado = 'vencido' DESC, pp.fecha_limite_pago ASC
              LIMIT $inicio_int, $limite_int";
$stmt_lista = $pdo->prepare($sql_lista);
$stmt_lista->execute($params);
$lista_deudas = $stmt_lista->fetchAll();

// Historial de abonos de las deudas listadas en esta página, para poder
// enlazar el comprobante individual de cada pago ya registrado (no solo
// el que se acaba de hacer).
$abonos_por_deuda = [];
$ids_deuda_pagina = array_column($lista_deudas, 'id_pago_pendiente');
if (count($ids_deuda_pagina) > 0) {
    // Traemos todos los abonos de todas las deudas de esta página en una sola consulta (más eficiente que una por deuda).
    $marcadores = implode(',', array_fill(0, count($ids_deuda_pagina), '?'));
    $stmt_abonos = $pdo->prepare(
        "SELECT id_abono, id_pago_pendiente, monto, metodo_pago, fecha_pago
         FROM abonos
         WHERE id_pago_pendiente IN ($marcadores)
         ORDER BY fecha_pago ASC, id_abono ASC"
    );
    $stmt_abonos->execute($ids_deuda_pagina);
    // Los agrupamos por deuda: $abonos_por_deuda[id_pago_pendiente] = [abono1, abono2, ...].
    foreach ($stmt_abonos->fetchAll() as $ab) {
        $abonos_por_deuda[(int) $ab['id_pago_pendiente']][] = $ab;
    }
}

// Total por cobrar (todas las deudas activas, sin importar el filtro aplicado).
$total_por_cobrar = (float) $pdo->query("SELECT COALESCE(SUM(saldo_pendiente), 0) FROM pagos_pendientes WHERE estado != 'pagado'")->fetchColumn();
$total_vencido     = (float) $pdo->query("SELECT COALESCE(SUM(saldo_pendiente), 0) FROM pagos_pendientes WHERE estado = 'vencido'")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagos Pendientes - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/home.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <!-- Estilos del formulario (compartidos con otras páginas) -->
    <link rel="stylesheet" href="assets/css/shared/gestionCitas.css">
    <!-- Estilos exclusivos del dashboard de pagos pendientes -->
    <link rel="stylesheet" href="assets/css/pages/pagos_pendientes.css">
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
                <h1>Pagos <span class="grad">Pendientes</span></h1>
            </div>
        </div>
    </section>

    <section class="wrap dash-wide">

        <div class="stats-pagos">
            <div class="stat-caja">
                <span class="label">Total por cobrar</span>
                <span class="valor">₡<?= number_format($total_por_cobrar, 2) ?></span>
            </div>
            <div class="stat-caja">
                <span class="label">Vencido</span>
                <span class="valor" style="color: var(--pink, #e74c3c);">₡<?= number_format($total_vencido, 2) ?></span>
            </div>
        </div>

        <!-- LAYOUT: SIDEBAR DE FILTROS + CONTENIDO -->
        <form action="pagos_pendientes.php" method="GET" class="dash-layout" id="formFiltros">

            <aside class="dash-sidebar">
                <h3>Filtros</h3>

                <div class="dash-filter-group">
                    <label for="estado">Estado</label>
                    <select name="estado" id="estado" class="form-control">
                        <option value="">Con saldo pendiente (todas)</option>
                        <option value="pendiente" <?= $estado_filtro === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                        <option value="abonado" <?= $estado_filtro === 'abonado' ? 'selected' : '' ?>>Abonado</option>
                        <option value="vencido" <?= $estado_filtro === 'vencido' ? 'selected' : '' ?>>Vencido</option>
                        <option value="pagado" <?= $estado_filtro === 'pagado' ? 'selected' : '' ?>>Pagado (saldado)</option>
                    </select>
                </div>

                <div class="dash-sidebar-actions">
                    <button type="submit" class="btn-primary">Aplicar filtros</button>
                    <?php if ($hay_filtros_activos): ?>
                        <a href="pagos_pendientes.php" class="btn-cancel">Limpiar filtros</a>
                    <?php endif; ?>
                </div>
            </aside>

            <main class="dash-main">
                <div class="dash-search">
                    <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por paciente o cédula..." class="form-control">
                    <button type="submit" class="btn-primary">Buscar</button>
                </div>

                <p class="dash-results-count">
                    <?= $total_registros ?> factura(s) encontrada(s)<?= $hay_filtros_activos ? ' con los filtros aplicados' : '' ?>.
                </p>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Factura</th>
                                <th>Paciente</th>
                                <th>Monto Total</th>
                                <th>Abonado</th>
                                <th>Saldo</th>
                                <th>Fecha Límite</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($lista_deudas) > 0): ?>
                                <?php foreach ($lista_deudas as $d): ?>
                                    <tr>
                                        <td><a href="ver_factura.php?id=<?= (int) $d['id_venta'] ?>" target="_blank" rel="noopener" style="color: var(--cyan);">#<?= str_pad($d['id_venta'], 6, '0', STR_PAD_LEFT) ?></a></td>
                                        <td>
                                            <strong><?= htmlspecialchars($d['paciente_nombre'] . ' ' . $d['paciente_apellido']) ?></strong><br>
                                            <small style="color: var(--muted);">Ced: <?= htmlspecialchars($d['cedula'] ?? 'N/A') ?></small>
                                        </td>
                                        <td>₡<?= number_format($d['monto_total'], 2) ?></td>
                                        <td>₡<?= number_format($d['monto_abonado'], 2) ?></td>
                                        <td><strong>₡<?= number_format($d['saldo_pendiente'], 2) ?></strong></td>
                                        <td><?= date('d/m/Y', strtotime($d['fecha_limite_pago'])) ?></td>
                                        <td>
                                            <span class="badge badge-<?= $d['estado'] === 'vencido' ? 'cancelada' : ($d['estado'] === 'pagado' ? 'completada' : ($d['estado'] === 'abonado' ? 'en_espera' : 'pendiente')) ?>">
                                                <?= ucfirst($d['estado']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($d['estado'] !== 'pagado'): ?>
                                                <button type="button" class="btn-action btn-edit" onclick="abrirModalAbono(<?= (int) $d['id_pago_pendiente'] ?>, '<?= htmlspecialchars(addslashes($d['paciente_nombre'] . ' ' . $d['paciente_apellido']), ENT_QUOTES) ?>', <?= (float) $d['saldo_pendiente'] ?>)">
                                                    Registrar Abono
                                                </button>
                                            <?php endif; ?>
                                            <?php $n_abonos = count($abonos_por_deuda[(int) $d['id_pago_pendiente']] ?? []); ?>
                                            <?php if ($n_abonos > 0): ?>
                                                <button type="button" class="btn-action btn-view" onclick="abrirModalHistorial(<?= (int) $d['id_pago_pendiente'] ?>, '<?= htmlspecialchars(addslashes($d['paciente_nombre'] . ' ' . $d['paciente_apellido']), ENT_QUOTES) ?>')">
                                                    Ver abonos (<?= $n_abonos ?>)
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="muted-text text-center">No hay pagos pendientes registrados.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_paginas > 1): ?>
                    <div class="pagination-container">
                        <span class="pagination-info">Página <strong><?= $pagina ?></strong> de <strong><?= $total_paginas ?></strong> (<?= $total_registros ?> facturas)</span>
                        <div class="pagination">
                            <?php if ($pagina > 1): ?>
                                <a href="pagos_pendientes.php?pagina=<?= $pagina - 1 ?><?= $query_filtros ?>" class="page-link">&laquo; Anterior</a>
                            <?php endif; ?>
                            <?php foreach (obtener_paginas_visibles($pagina, $total_paginas) as $item): ?>
                                <?php if ($item === '...'): ?>
                                    <span class="page-ellipsis">&hellip;</span>
                                <?php else: ?>
                                    <a href="pagos_pendientes.php?pagina=<?= $item ?><?= $query_filtros ?>" class="page-link <?= $item === $pagina ? 'active' : '' ?>"><?= $item ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($pagina < $total_paginas): ?>
                                <a href="pagos_pendientes.php?pagina=<?= $pagina + 1 ?><?= $query_filtros ?>" class="page-link">Siguiente &raquo;</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </form>
    </section>

    <!-- MODAL: REGISTRAR ABONO -->
    <div id="modalAbono" class="dash-modal-overlay">
        <div class="dash-modal-card">
            <div class="dash-modal-head">
                <button type="button" class="dash-modal-close" onclick="cerrarModalAbono()" aria-label="Cerrar">&times;</button>
                <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                    <span class="eyebrow">Registrar Abono</span>
                    <h2 id="modalAbonoTitulo" style="margin:4px 0 0;">&nbsp;</h2>
                </div>
            </div>

            <p class="muted-text" style="text-align:center; margin-bottom:18px;">
                Saldo pendiente: <strong id="modalAbonoSaldo" style="color: var(--text);">₡0.00</strong>
            </p>

            <?php if (!empty($mensaje) && $abrir_modal_abono): ?>
                <div class="alert alert-<?= htmlspecialchars($tipo_mensaje) ?>" style="margin-bottom:18px;">
                    <?= htmlspecialchars($mensaje) ?>
                </div>
            <?php endif; ?>

            <form action="pagos_pendientes.php" method="POST" id="formAbono">
                <input type="hidden" name="registrar_abono" value="1">
                <input type="hidden" name="id_pago_pendiente" id="abono_id_pago_pendiente" value="<?= $modal_abono_data['id_pago_pendiente'] ?? '' ?>">

                <div class="form-group">
                    <label for="abono_monto"><span class="dash-req">*</span> Monto a abonar <span class="dash-hint">(₡)</span></label>
                    <input type="number" step="0.01" min="0.01" id="abono_monto" name="monto" required class="form-control" value="<?= htmlspecialchars($_POST['monto'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="abono_metodo_pago"><span class="dash-req">*</span> Método de pago</label>
                    <select name="metodo_pago" id="abono_metodo_pago" required class="form-control">
                        <option value="efectivo" <?= ($_POST['metodo_pago'] ?? '') === 'efectivo' ? 'selected' : '' ?>>Efectivo</option>
                        <option value="tarjeta" <?= ($_POST['metodo_pago'] ?? '') === 'tarjeta' ? 'selected' : '' ?>>Tarjeta</option>
                        <option value="transferencia" <?= ($_POST['metodo_pago'] ?? '') === 'transferencia' ? 'selected' : '' ?>>Transferencia</option>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModalAbono()">Cancelar</button>
                    <button type="submit" class="btn-primary">Registrar Abono</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: HISTORIAL DE ABONOS -->
    <div id="modalHistorial" class="dash-modal-overlay">
        <div class="dash-modal-card">
            <div class="dash-modal-head">
                <button type="button" class="dash-modal-close" onclick="cerrarModalHistorial()" aria-label="Cerrar">&times;</button>
                <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                    <span class="eyebrow">Historial de Abonos</span>
                    <h2 id="modalHistorialTitulo" style="margin:4px 0 0;">&nbsp;</h2>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Monto</th>
                            <th>Método</th>
                            <th>Comprobante</th>
                        </tr>
                    </thead>
                    <tbody id="modalHistorialCuerpo"></tbody>
                </table>
            </div>
        </div>
    </div>

    <?php require_once 'assets/includes/partials/footer.php'; ?>

    <script>
        // Historial de abonos de las deudas listadas en esta página (para el modal "Ver abonos").
        const abonosPorDeuda = <?= json_encode($abonos_por_deuda) ?>;

        // MODAL: HISTORIAL DE ABONOS
        const modalHistorial = document.getElementById('modalHistorial');
        const tituloModalHistorial = document.getElementById('modalHistorialTitulo');
        const cuerpoModalHistorial = document.getElementById('modalHistorialCuerpo');

        // Llena y abre el modal con la lista de abonos ya registrados para una deuda específica.
        function abrirModalHistorial(idPagoPendiente, nombrePaciente) {
            tituloModalHistorial.textContent = nombrePaciente;

            const abonos = abonosPorDeuda[idPagoPendiente] || [];
            cuerpoModalHistorial.innerHTML = abonos.map(function (ab) {
                const fecha = new Date(ab.fecha_pago.replace(' ', 'T')).toLocaleString('es-CR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                const metodo = ab.metodo_pago.charAt(0).toUpperCase() + ab.metodo_pago.slice(1);
                return '<tr>'
                    + '<td>' + fecha + '</td>'
                    + '<td>' + formatearColones(parseFloat(ab.monto)) + '</td>'
                    + '<td>' + metodo + '</td>'
                    + '<td><a href="ver_factura.php?id_abono=' + ab.id_abono + '" target="_blank" rel="noopener" style="color: var(--cyan);">Ver comprobante</a></td>'
                    + '</tr>';
            }).join('');

            modalHistorial.classList.add('active');
        }

        function cerrarModalHistorial() {
            modalHistorial.classList.remove('active');
        }

        modalHistorial.addEventListener('click', function (e) {
            if (e.target === this) cerrarModalHistorial();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') cerrarModalHistorial();
        });

        // MODAL: REGISTRAR ABONO
        const modalAbono = document.getElementById('modalAbono');
        const tituloModalAbono = document.getElementById('modalAbonoTitulo');
        const saldoModalAbono = document.getElementById('modalAbonoSaldo');
        const campoIdPagoPendiente = document.getElementById('abono_id_pago_pendiente');
        const campoMontoAbono = document.getElementById('abono_monto');

        function formatearColones(valor) {
            return '₡' + valor.toLocaleString('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // Llena y abre el modal para registrar un abono nuevo a una deuda específica.
        function abrirModalAbono(idPagoPendiente, nombrePaciente, saldoPendiente) {
            tituloModalAbono.textContent = nombrePaciente;
            saldoModalAbono.textContent = formatearColones(saldoPendiente);
            campoIdPagoPendiente.value = idPagoPendiente;
            campoMontoAbono.max = saldoPendiente; // No se puede abonar más de lo que se debe.
            modalAbono.classList.add('active');
        }

        function cerrarModalAbono() {
            modalAbono.classList.remove('active');
        }

        modalAbono.addEventListener('click', function (e) {
            if (e.target === this) cerrarModalAbono();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') cerrarModalAbono();
        });

        <?php // Si el registro de abono falló, reabrimos el modal automáticamente con los mismos datos. ?>
        <?php if ($abrir_modal_abono && $modal_abono_data): ?>
            abrirModalAbono(
                <?= (int) $modal_abono_data['id_pago_pendiente'] ?>,
                <?= json_encode($modal_abono_data['paciente_nombre'] . ' ' . $modal_abono_data['paciente_apellido']) ?>,
                <?= (float) $modal_abono_data['saldo_pendiente'] ?>
            );
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
