<?php
/**
 * facturar_servicios_model.php — Acceso a datos para facturar_servicios.php:
 * catálogo de servicios/pacientes/promociones para el formulario, y el
 * registro de la venta en sí (delegado al stored procedure
 * sp_registrar_venta_servicios, ver database/schema.sql).
 */

/** Servicios activos que coincidan con los IDs dados (para revalidar precios reales antes de facturar). */
function facturar_servicios_buscar_por_ids(PDO $pdo, array $ids): array
{
    // Armamos tantos "?" como ids tengamos, para poder usarlos todos de forma segura en el IN(...).
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id_servicio, nombre, precio FROM servicios WHERE id_servicio IN ($marcadores) AND activo = 1");
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

/** Promoción vigente hoy por id, o false si no existe/ya no aplica. */
function facturar_servicios_obtener_promocion_vigente(PDO $pdo, int $id_promocion, string $fecha_hoy)
{
    // "Vigente hoy" significa: activa, ya empezó, y todavía no terminó (o no tiene fecha de fin).
    $stmt = $pdo->prepare(
        "SELECT * FROM promociones
         WHERE id_promocion = :id AND activo = 1
           AND fecha_inicio <= :hoy AND (fecha_fin IS NULL OR fecha_fin >= :hoy2)"
    );
    $stmt->execute([':id' => $id_promocion, ':hoy' => $fecha_hoy, ':hoy2' => $fecha_hoy]);
    return $stmt->fetch();
}

/** Servicios incluidos en una promoción (id + nombre). */
function facturar_servicios_listar_servicios_de_promocion(PDO $pdo, int $id_promocion): array
{
    $stmt = $pdo->prepare("SELECT s.id_servicio, s.nombre FROM promocion_servicios ps INNER JOIN servicios s ON ps.id_servicio = s.id_servicio WHERE ps.id_promocion = :id");
    $stmt->execute([':id' => $id_promocion]);
    return $stmt->fetchAll();
}

/**
 * Inserta la venta de servicios (con descuento, insumos e pago parcial si
 * aplica) llamando a sp_registrar_venta_servicios. $servicios_ids y
 * $precios_por_servicio ya deben venir revalidados contra la BD. Devuelve
 * el id de la venta creada; deja que cualquier PDOException se propague
 * (incluye las señaladas por el procedimiento, SQLSTATE 45000).
 */
function facturar_servicios_registrar_venta(PDO $pdo, array $datos): int
{
    // Armamos la lista de servicios (id + precio) en formato JSON, que es lo que espera el procedimiento almacenado.
    $items_json = json_encode(array_map(
        fn($id) => ['id_servicio' => (int) $id, 'precio' => (float) $datos['precios_por_servicio'][$id]],
        $datos['servicios_ids']
    ));

    // Llamamos al procedimiento que hace todo el trabajo pesado: calcular totales, descontar
    // inventario si aplica, y guardar la venta — todo dentro de una sola transacción con rollback.
    $stmt_call = $pdo->prepare(
        'CALL sp_registrar_venta_servicios(:pac, :usr, :promo, :nombre_promo, :desc, :metodo, :tipo, :abono, :fecha_limite, :serv,
            @id_venta, @subtotal, @impuesto, @total, @parcial, @saldo)'
    );
    $stmt_call->execute([
        ':pac'          => $datos['id_paciente'],
        ':usr'          => $datos['id_usuario'],
        ':promo'        => $datos['id_promocion'],
        ':nombre_promo' => $datos['nombre_promocion'],
        ':desc'         => $datos['descuento'],
        ':metodo'       => $datos['metodo_pago'],
        ':tipo'         => $datos['tipo_pago'],
        ':abono'        => $datos['monto_abonado_inicial'],
        ':fecha_limite' => $datos['tipo_pago'] === 'parcial' ? $datos['fecha_limite_pago'] : null,
        ':serv'         => $items_json,
    ]);
    $stmt_call->closeCursor();

    // El procedimiento deja el id de la venta creada en una variable de MySQL (@id_venta); lo recuperamos aquí.
    $salida = $pdo->query('SELECT @id_venta AS id_venta')->fetch();
    return (int) $salida['id_venta'];
}

/** Pacientes activos, para el <select> del paso 1. */
function facturar_servicios_listar_pacientes_activos(PDO $pdo): array
{
    return $pdo->query(
        "SELECT p.id_paciente, u.nombre, u.apellido, u.cedula
         FROM pacientes p
         INNER JOIN usuarios u ON p.id_usuario = u.id_usuario
         WHERE u.estado = 1 ORDER BY u.nombre ASC"
    )->fetchAll();
}

/** Servicios activos, para el checklist del paso 2. */
function facturar_servicios_listar_servicios_activos(PDO $pdo): array
{
    return $pdo->query("SELECT id_servicio, nombre, precio FROM servicios WHERE activo = 1 ORDER BY nombre ASC")->fetchAll();
}

/** Promociones vigentes hoy, para el <select> del paso 3. */
function facturar_servicios_listar_promociones_vigentes(PDO $pdo, string $fecha_hoy): array
{
    $stmt = $pdo->prepare(
        "SELECT id_promocion, nombre, tipo, valor_descuento, precio_paquete
         FROM promociones
         WHERE activo = 1 AND fecha_inicio <= :hoy AND (fecha_fin IS NULL OR fecha_fin >= :hoy2)
         ORDER BY nombre ASC"
    );
    $stmt->execute([':hoy' => $fecha_hoy, ':hoy2' => $fecha_hoy]);
    return $stmt->fetchAll();
}

/** [id_promocion => [id_servicio, ...]] para las promociones dadas (lógica de vigencia/paquete en el JS del formulario). */
function facturar_servicios_mapa_promocion_servicios(PDO $pdo, array $ids_promos): array
{
    if (count($ids_promos) === 0) {
        return []; // Sin promociones que revisar, no hace falta ni consultar la base de datos.
    }
    $marcadores = implode(',', array_fill(0, count($ids_promos), '?'));
    $stmt = $pdo->prepare("SELECT id_promocion, id_servicio FROM promocion_servicios WHERE id_promocion IN ($marcadores)");
    $stmt->execute($ids_promos);

    // Agrupamos los resultados en un array [id_promocion => [id_servicio, id_servicio, ...]].
    $mapa = [];
    foreach ($stmt->fetchAll() as $fila) {
        $mapa[(int) $fila['id_promocion']][] = (int) $fila['id_servicio'];
    }
    return $mapa;
}
