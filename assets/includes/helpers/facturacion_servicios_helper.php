<?php
/**
 * facturacion_servicios_helper.php — Lógica compartida de facturación de
 * servicios/tratamientos, usada tanto por facturar_servicios.php (pago en
 * efectivo/transferencia, o tarjeta cuando no hace falta pasar por Stripe)
 * como por pago_exito_servicios.php (confirmación de un pago con tarjeta
 * ya verificado en Stripe). Vive en un solo lugar para que ambos flujos
 * calculen y guarden la venta exactamente igual.
 */

/**
 * Calcula el descuento de una promoción sobre el conjunto de servicios
 * seleccionados. Misma lógica que se usa en el JS del formulario para la
 * vista previa, pero esta es la que realmente cuenta al guardar.
 *
 * Devuelve ['descuento' => float, 'error' => string|null].
 */
function calcular_descuento_promocion(array $promo, array $ids_servicios_incluidos, array $precios_por_servicio): array
{
    // Pasamos todos los ids a número entero, por si llegaron como texto.
    $seleccionados = array_map('intval', $ids_servicios_incluidos);
    $elegibles = array_map('intval', $promo['servicios_ids']);

    // Caso 1: promoción de tipo "paquete" (varios servicios juntos a un precio fijo).
    if ($promo['tipo'] === 'paquete') {
        // Buscamos si falta algún servicio del paquete entre los que el usuario seleccionó.
        $faltantes = array_diff($elegibles, $seleccionados);
        if (count($faltantes) > 0) {
            // Si falta al menos uno, no se puede aplicar el paquete: avisamos cuáles hacen falta.
            return ['descuento' => 0.0, 'error' => 'Para aplicar el paquete "' . $promo['nombre'] . '" debes incluir todos sus servicios: ' . implode(', ', $promo['servicios_nombres']) . '.'];
        }
        // Sumamos el precio normal de todos los servicios del paquete.
        $subtotal_elegible = array_sum(array_map(fn($id) => $precios_por_servicio[$id] ?? 0, $elegibles));
        // El descuento es la diferencia entre lo que costaría normal y el precio especial del paquete.
        $descuento = max(0, $subtotal_elegible - (float) $promo['precio_paquete']);
        return ['descuento' => $descuento, 'error' => null];
    }

    // Caso 2: promoción de descuento (porcentual o de monto fijo) sobre ciertos servicios.
    // Solo cuentan los servicios que el usuario seleccionó Y que además son elegibles para la promoción.
    $incluidos_elegibles = array_intersect($seleccionados, $elegibles);
    $subtotal_elegible = array_sum(array_map(fn($id) => $precios_por_servicio[$id] ?? 0, $incluidos_elegibles));

    // Si no seleccionó ningún servicio elegible, no hay descuento que aplicar.
    if ($subtotal_elegible <= 0) {
        return ['descuento' => 0.0, 'error' => null];
    }

    if ($promo['tipo'] === 'descuento_porcentual') {
        // Ej: 20% de descuento sobre el subtotal elegible.
        return ['descuento' => round($subtotal_elegible * ((float) $promo['valor_descuento'] / 100), 2), 'error' => null];
    }

    // descuento_fijo: se resta un monto fijo, pero nunca más de lo que suman los servicios elegibles.
    return ['descuento' => min((float) $promo['valor_descuento'], $subtotal_elegible), 'error' => null];
}

// La persistencia de la venta (ventas + detalle_venta + insumos + pago
// parcial) vive ahora en el stored procedure sp_registrar_venta_servicios
// (ver database/schema.sql), llamado directamente desde facturar_servicios.php
// y pago_exito_servicios.php. Este archivo solo conserva el cálculo del
// descuento de promoción, que sigue haciéndose en PHP antes de llamarlo.
