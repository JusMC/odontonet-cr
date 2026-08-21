<?php
session_start();
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

$id_rol_sesion = (int) ($_SESSION['id_rol'] ?? 0);
$es_staff = in_array($id_rol_sesion, [3, 4], true); // Recepcionista o administrador: pueden ver cualquier comprobante.

$id_venta  = isset($_GET['id']) ? intval($_GET['id']) : 0;
$id_abono  = isset($_GET['id_abono']) ? intval($_GET['id_abono']) : 0;
// Esta misma página muestra dos tipos de comprobante distintos, según qué parámetro venga en la URL:
// una factura completa de venta, o el recibo de un solo abono a una deuda.
$modo      = $id_abono > 0 ? 'abono' : 'venta';

if ($modo === 'abono') {
    // Comprobante de un abono individual: cada pago a una deuda genera su
    // propio comprobante, en vez de que toda la deuda comparta una sola
    // factura sin registro de cada pago.
    $sql_abono = "SELECT ab.id_abono, ab.monto, ab.metodo_pago, ab.fecha_pago, ab.id_pago_pendiente,
                         pp.id_venta, pp.monto_total AS deuda_monto_total, pp.estado AS estado_deuda,
                         v.id_paciente,
                         p.nombre_completo AS paciente_nombre, p.cedula AS paciente_cedula, p.telefono AS paciente_telefono,
                         CONCAT(reg.nombre, ' ', reg.apellido) AS registrado_por
                  FROM abonos ab
                  JOIN pagos_pendientes pp ON ab.id_pago_pendiente = pp.id_pago_pendiente
                  JOIN ventas v ON pp.id_venta = v.id_venta
                  JOIN v_pacientes_admin p ON v.id_paciente = p.id_paciente
                  JOIN usuarios reg ON ab.id_usuario_registro = reg.id_usuario
                  WHERE ab.id_abono = :id";
    $stmt = $pdo->prepare($sql_abono);
    $stmt->execute([':id' => $id_abono]);
    $abono = $stmt->fetch();

    if (!$abono) {
        // No existe ese abono: cortamos aquí con un mensaje simple (esta página no usa toasts/modales).
        die("<div style='color:white; text-align:center; padding: 50px;'>Error: Comprobante de abono no encontrado.</div>");
    }

    // Un paciente solo puede ver sus propios comprobantes; el personal
    // (recepción/administración) puede ver cualquiera.
    if (!$es_staff && ((int) $abono['id_paciente']) !== (int) ($_SESSION['paciente_id'] ?? 0)) {
        die("<div style='color:white; text-align:center; padding: 50px;'>No tienes permiso para ver este comprobante.</div>");
    }

    // Saldo justo después de ESTE abono (no el saldo actual de la deuda, que
    // puede reflejar abonos posteriores registrados más adelante).
    $stmt_cum = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) FROM abonos WHERE id_pago_pendiente = :pp AND id_abono <= :ab");
    $stmt_cum->execute([':pp' => $abono['id_pago_pendiente'], ':ab' => $abono['id_abono']]);
    $abonado_acumulado = (float) $stmt_cum->fetchColumn();
    $saldo_despues = max(0, (float) $abono['deuda_monto_total'] - $abonado_acumulado);
    $abono_salda_deuda = $saldo_despues <= 0;
} else {
    // 1. Consultar encabezado de la venta y datos del paciente
    $sql_venta = "SELECT v.*,
                         p.nombre_completo AS paciente_nombre, p.cedula AS paciente_cedula, p.correo AS paciente_correo, p.telefono AS paciente_telefono,
                         CONCAT(u.nombre, ' ', u.apellido) AS emisor_nombre,
                         promo.nombre AS promocion_nombre,
                         pp.id_pago_pendiente, pp.monto_abonado, pp.saldo_pendiente, pp.fecha_limite_pago, pp.estado AS estado_deuda
                  FROM ventas v
                  JOIN v_pacientes_admin p ON v.id_paciente = p.id_paciente
                  JOIN usuarios u ON v.id_usuario = u.id_usuario
                  LEFT JOIN promociones promo ON v.id_promocion = promo.id_promocion
                  LEFT JOIN pagos_pendientes pp ON v.id_venta = pp.id_venta
                  WHERE v.id_venta = :id";

    $stmt = $pdo->prepare($sql_venta);
    $stmt->execute([':id' => $id_venta]);
    $factura = $stmt->fetch();

    if (!$factura) {
        // No existe esa venta: cortamos aquí con un mensaje simple.
        die("<div style='color:white; text-align:center; padding: 50px;'>Error: Factura no encontrada.</div>");
    }

    // Un paciente solo puede ver sus propias facturas; el personal (recepción/
    // administración) puede ver cualquiera.
    if (!$es_staff && ((int) $factura['id_paciente']) !== (int) ($_SESSION['paciente_id'] ?? 0)) {
        die("<div style='color:white; text-align:center; padding: 50px;'>No tienes permiso para ver esta factura.</div>");
    }

    // 2. Consultar detalles (productos o servicios): cada línea de la factura puede ser un producto de la
    // tienda o un servicio clínico, por eso el JOIN busca el nombre en una tabla u otra según "tipo".
    $sql_detalle = "SELECT d.*,
                           CASE
                               WHEN d.tipo = 'producto' THEN p.nombre
                               WHEN d.tipo = 'servicio' THEN s.nombre
                           END AS item_nombre
                    FROM detalle_venta d
                    LEFT JOIN productos p ON d.tipo = 'producto' AND d.id_referencia = p.id_producto
                    LEFT JOIN servicios s ON d.tipo = 'servicio' AND d.id_referencia = s.id_servicio
                    WHERE d.id_venta = :id";

    $stmt_det = $pdo->prepare($sql_detalle);
    $stmt_det->execute([':id' => $id_venta]);
    $detalles = $stmt_det->fetchAll();

    // 3. Historial de abonos de esta venta (cada uno tiene su propio comprobante).
    // Solo tiene sentido buscar abonos si esta venta tuvo un plan de pago parcial (estado_deuda no es null).
    $abonos_venta = [];
    if ($factura['estado_deuda'] !== null) {
        $stmt_abonos_venta = $pdo->prepare(
            "SELECT id_abono, monto, metodo_pago, fecha_pago FROM abonos WHERE id_pago_pendiente = :pp ORDER BY fecha_pago ASC, id_abono ASC"
        );
        $stmt_abonos_venta->execute([':pp' => $factura['id_pago_pendiente']]);
        $abonos_venta = $stmt_abonos_venta->fetchAll();
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $modo === 'abono' ? 'Comprobante de Abono #' . str_pad($abono['id_abono'], 6, '0', STR_PAD_LEFT) : 'Factura #' . str_pad($factura['id_venta'], 6, '0', STR_PAD_LEFT) ?> - OdontoNet</title>
    <link rel="icon" href="assets/images/ODONTONET4.svg" type="image/svg+xml">

    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/home.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">

    <style>
        .factura-header-flex {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .factura-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .totales-box {
            width: 280px;
            margin: 1.5rem auto 0 auto;
        }

        .totales-row {
            display: flex;
            justify-content: space-between;
            padding: 0.4rem 0;
            color: var(--color-text-muted, #94a3b8);
        }

        .totales-row.total-final {
            border-top: 2px solid var(--color-accent, #3b82f6);
            padding-top: 0.8rem;
            margin-top: 0.4rem;
            font-size: 1.25rem;
            font-weight: bold;
            color: #fff;
        }

        @media print {
            header, footer, .no-print, .glow, .grid-overlay {
                display: none !important;
            }
            body {
                background: #fff !important;
                color: #000 !important;
            }
            .card {
                background: #fff !important;
                border: none !important;
                box-shadow: none !important;
                color: #000 !important;
            }
            td, th {
                color: #000 !important;
                border-bottom: 1px solid #ddd !important;
            }
            .totales-row, .totales-row.total-final {
                color: #000 !important;
            }
        }
    </style>
</head>

<body>

    <div class="grid-overlay"></div>
    <div class="glow glow-a"></div>
    <div class="glow glow-b"></div>

    <?php require_once 'assets/includes/partials/navbar.php'; ?>

    <section class="wrap hero no-print" style="padding-bottom: 1rem;">
        <div class="hero-content-center">
            <span class="eyebrow">Comprobante Digital</span>
            <?php if ($modo === 'abono'): ?>
                <h1>Comprobante <span class="grad">de Abono</span></h1>
                <p class="lead">Detalle del pago abonado a una deuda pendiente.</p>
            <?php else: ?>
                <h1>Factura <span class="grad">de Compra</span></h1>
                <p class="lead">Detalle del servicio o producto adquirido en la clínica.</p>
            <?php endif; ?>
        </div>
    </section>

    <section class="wrap form-container">

        <div class="no-print" style="margin-bottom: 1.5rem; display: flex; justify-content: space-between;">
            <?php if ($es_staff): ?>
                <a href="pagos_pendientes.php" class="btn-ghost">Volver</a>
            <?php else: ?>
                <a href="tienda.php" class="btn-ghost">Volver a la Tienda</a>
            <?php endif; ?>
            <button onclick="window.print()" class="btn-primary">🖨️ Imprimir / Guardar en PDF</button>
        </div>

        <?php if ($modo === 'abono'): ?>
        <div class="card form-card">

            <div class="factura-header-flex">
                <div>
                    <h2 style="margin: 0; color: var(--color-accent, #60a5fa);">OdontoNet</h2>
                    <p class="muted-text" style="margin: 0.2rem 0;">Comprobante Electrónico de Abono</p>
                </div>
                <div style="text-align: right;">
                    <h3 style="margin: 0;">Abono N° <?= str_pad($abono['id_abono'], 6, '0', STR_PAD_LEFT) ?></h3>
                    <p class="muted-text" style="margin: 0.2rem 0;">Fecha: <?= date('d/m/Y H:i', strtotime($abono['fecha_pago'])) ?></p>
                    <span class="badge badge-<?= $abono_salda_deuda ? 'completada' : 'en_espera' ?>">
                        <?= $abono_salda_deuda ? '✓ DEUDA SALDADA' : 'ABONO PARCIAL' ?>
                    </span>
                </div>
            </div>

            <div class="factura-details-grid">
                <div>
                    <span class="eyebrow">Datos del Paciente</span>
                    <p style="margin: 0.4rem 0 0.2rem 0;"><strong><?= htmlspecialchars($abono['paciente_nombre']) ?></strong></p>
                    <p class="muted-text" style="margin: 0;">Cédula: <?= htmlspecialchars($abono['paciente_cedula'] ?? 'N/A') ?></p>
                    <p class="muted-text" style="margin: 0;">Teléfono: <?= htmlspecialchars($abono['paciente_telefono'] ?? 'N/A') ?></p>
                </div>
                <div>
                    <span class="eyebrow">Detalles del Pago</span>
                    <p style="margin: 0.4rem 0 0.2rem 0;">Método: <strong><?= strtoupper($abono['metodo_pago']) ?></strong></p>
                    <p class="muted-text" style="margin: 0;">Atendido por: <?= htmlspecialchars($abono['registrado_por']) ?></p>
                </div>
            </div>

            <div class="totales-box" style="width: 100%; margin-left: 0;">
                <div class="totales-row total-final">
                    <span>Monto abonado:</span>
                    <span>₡<?= number_format($abono['monto'], 0, ',', '.') ?></span>
                </div>
            </div>

            <div class="totales-box" style="width: 100%; margin-left: 0; margin-top: 1.5rem; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 14px 18px;">
                <p class="eyebrow" style="margin-bottom: 8px;">Estado de la deuda</p>
                <div class="totales-row"><span>Monto total de la deuda:</span><span>₡<?= number_format($abono['deuda_monto_total'], 0, ',', '.') ?></span></div>
                <div class="totales-row"><span>Abonado a la fecha:</span><span>₡<?= number_format($abonado_acumulado, 0, ',', '.') ?></span></div>
                <div class="totales-row"><span>Saldo restante:</span><span>₡<?= number_format($saldo_despues, 0, ',', '.') ?></span></div>
            </div>

            <p class="muted-text no-print" style="margin-top: 1.5rem; text-align: center;">
                Este abono corresponde a la factura
                <a href="ver_factura.php?id=<?= (int) $abono['id_venta'] ?>" style="color: var(--cyan);">#<?= str_pad($abono['id_venta'], 6, '0', STR_PAD_LEFT) ?></a>.
            </p>

        </div>
        <?php else: ?>
        <div class="card form-card">

            <div class="factura-header-flex">
                <div>
                    <h2 style="margin: 0; color: var(--color-accent, #60a5fa);">OdontoNet</h2>
                    <p class="muted-text" style="margin: 0.2rem 0;">Comprobante Electrónico de Compra</p>
                </div>
                <div style="text-align: right;">
                    <h3 style="margin: 0;">Factura N° <?= str_pad($factura['id_venta'], 6, '0', STR_PAD_LEFT) ?></h3>
                    <p class="muted-text" style="margin: 0.2rem 0;">Fecha: <?= date('d/m/Y H:i', strtotime($factura['fecha_venta'])) ?></p>
                    <span class="badge badge-<?= $factura['estado'] === 'pagada' ? 'completada' : ($factura['estado'] === 'anulada' ? 'cancelada' : 'pendiente') ?>">
                        <?= $factura['estado'] === 'pagada' ? '✓ ' : '' ?><?= strtoupper($factura['estado']) ?>
                    </span>
                </div>
            </div>

            <div class="factura-details-grid">
                <div>
                    <span class="eyebrow">Datos del Paciente</span>
                    <p style="margin: 0.4rem 0 0.2rem 0;"><strong><?= htmlspecialchars($factura['paciente_nombre']) ?></strong></p>
                    <p class="muted-text" style="margin: 0;">Cédula: <?= htmlspecialchars($factura['paciente_cedula'] ?? 'N/A') ?></p>
                    <p class="muted-text" style="margin: 0;">Teléfono: <?= htmlspecialchars($factura['paciente_telefono'] ?? 'N/A') ?></p>
                </div>
                <div>
                    <span class="eyebrow">Detalles del Pago</span>
                    <p style="margin: 0.4rem 0 0.2rem 0;">Método: <strong><?= strtoupper($factura['metodo_pago']) ?></strong></p>
                    <p class="muted-text" style="margin: 0;">Atendido por: <?= htmlspecialchars($factura['emisor_nombre']) ?></p>
                </div>
            </div>

            <!-- TABLA DE DETALLES -->
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Descripción</th>
                            <th>Cant.</th>
                            <th>Precio Unit.</th>
                            <th style="text-align: right;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detalles as $item): ?>
                            <tr>
                                <td>
                                    <span class="badge <?= $item['tipo'] === 'servicio' ? 'badge-paciente' : 'badge-success' ?>">
                                        <?= strtoupper($item['tipo']) ?>
                                    </span>
                                </td>
                                <td><strong><?= htmlspecialchars($item['item_nombre'] ?? 'Producto') ?></strong></td>
                                <td><?= $item['cantidad'] ?></td>
                                <td>₡<?= number_format($item['precio_unitario'], 0, ',', '.') ?></td>
                                <td style="text-align: right;">₡<?= number_format($item['subtotal'], 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- DESGLOSE DE TOTALES E IVA (13%) -->
            <div class="totales-box">
                <div class="totales-row">
                    <span>Subtotal:</span>
                    <span>₡<?= number_format($factura['subtotal'], 0, ',', '.') ?></span>
                </div>
                <?php if ($factura['descuento'] > 0): ?>
                    <div class="totales-row">
                        <span>Descuento<?= !empty($factura['promocion_nombre']) ? ' (' . htmlspecialchars($factura['promocion_nombre']) . ')' : '' ?>:</span>
                        <span>-₡<?= number_format($factura['descuento'], 0, ',', '.') ?></span>
                    </div>
                <?php endif; ?>
                <div class="totales-row">
                    <span>IVA (13%):</span>
                    <span>₡<?= number_format($factura['impuesto'], 0, ',', '.') ?></span>
                </div>
                <div class="totales-row total-final">
                    <span>Total:</span>
                    <span>₡<?= number_format($factura['total'], 0, ',', '.') ?></span>
                </div>
            </div>

            <?php if ($factura['estado_deuda'] !== null): ?>
                <div class="totales-box" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 14px 18px;">
                    <p class="eyebrow" style="margin-bottom: 8px;">Estado del saldo</p>
                    <div class="totales-row"><span>Abonado:</span><span>₡<?= number_format($factura['monto_abonado'], 0, ',', '.') ?></span></div>
                    <div class="totales-row"><span>Saldo pendiente:</span><span>₡<?= number_format($factura['saldo_pendiente'], 0, ',', '.') ?></span></div>
                    <div class="totales-row"><span>Fecha límite:</span><span><?= date('d/m/Y', strtotime($factura['fecha_limite_pago'])) ?></span></div>
                    <div class="totales-row"><span>Estado de la deuda:</span><span><?= ucfirst($factura['estado_deuda']) ?></span></div>
                </div>

                <?php if (count($abonos_venta) > 0): ?>
                    <div class="table-responsive no-print" style="margin-top: 1.5rem;">
                        <p class="eyebrow" style="margin-bottom: 8px;">Abonos registrados</p>
                        <table>
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Monto</th>
                                    <th>Método</th>
                                    <th>Comprobante</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($abonos_venta as $ab): ?>
                                    <tr>
                                        <td><?= date('d/m/Y H:i', strtotime($ab['fecha_pago'])) ?></td>
                                        <td>₡<?= number_format($ab['monto'], 0, ',', '.') ?></td>
                                        <td><?= ucfirst($ab['metodo_pago']) ?></td>
                                        <td><a href="ver_factura.php?id_abono=<?= (int) $ab['id_abono'] ?>" target="_blank" rel="noopener" style="color: var(--cyan);">Ver comprobante</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        </div>
        <?php endif; ?>
    </section>

    <?php require_once 'assets/includes/partials/footer.php'; ?>

</body>
</html>