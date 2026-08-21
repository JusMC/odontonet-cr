<?php
/*
    inventario.php — Control de inventario (dashboard).

    Alta/edición de producto con foto, inactivar/activar y alertas de
    stock/caducidad. Filtros en una barra lateral (categoría, rango de
    precio, estado, caducidad y stock), buscador, botón flotante para
    agregar producto y formulario de alta/edición en un modal.
*/

require_once __DIR__ . '/assets/includes/session_boot.php';
require_once './assets/includes/config/config.php';
require_once './assets/includes/auth_check.php';
require_once './assets/includes/helpers/bitacora_helper.php';
require_once './assets/includes/helpers/paginacion_helper.php';
require_once './assets/includes/helpers/archivo_helper.php';

// Guard: solo recepcionista (id_rol = 3) o administrador (id_rol = 4) pueden acceder.
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php?redirect=inventario.php');
    exit;
}

$id_rol = (int) ($_SESSION['id_rol'] ?? 0);
if (!in_array($id_rol, [3, 4], true)) {
    $destino = match ($id_rol) {
        1 => 'perfil.php',
        2 => 'control_citas.php',
        default => 'login.php',
    };
    header('Location: ' . $destino);
    exit;
}

$mensaje = "";
$tipo_mensaje = "";

// INACTIVAR PRODUCTO (soft-delete: activo = 0, nunca DELETE físico)
if (isset($_GET['accion']) && $_GET['accion'] === 'inactivar' && isset($_GET['id'])) {
    $id_inactivar = intval($_GET['id']);
    try {
        $stmt_inactivar = $pdo->prepare("UPDATE productos SET activo = 0 WHERE id_producto = :id");
        $stmt_inactivar->execute([':id' => $id_inactivar]);
        registrar_bitacora($pdo, 'DELETE', "Se inactivó el producto #$id_inactivar (soft-delete).");

        header("Location: inventario.php?msg=inactivado");
        exit;
    } catch (\PDOException $e) {
        registrar_bitacora($pdo, 'ERROR', "Falló inactivar el producto #$id_inactivar: " . $e->getMessage());
        $mensaje = "Error al inactivar el producto: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}

// REACTIVAR PRODUCTO
if (isset($_GET['accion']) && $_GET['accion'] === 'activar' && isset($_GET['id'])) {
    $id_activar = intval($_GET['id']);
    try {
        $stmt_activar = $pdo->prepare("UPDATE productos SET activo = 1 WHERE id_producto = :id");
        $stmt_activar->execute([':id' => $id_activar]);
        registrar_bitacora($pdo, 'UPDATE', "Se reactivó el producto #$id_activar.");

        header("Location: inventario.php?msg=activado");
        exit;
    } catch (\PDOException $e) {
        registrar_bitacora($pdo, 'ERROR', "Falló reactivar el producto #$id_activar: " . $e->getMessage());
        $mensaje = "Error al reactivar el producto: " . $e->getMessage();
        $tipo_mensaje = "error";
    }
}

// MENSAJES DE FEEDBACK MEDIANTE GET
if (isset($_GET['msg'])) {
    $mensajes_ok = [
        'inactivado'  => 'Producto inactivado. Sus datos se conservan en el sistema.',
        'activado'    => 'Producto reactivado con éxito.',
        'guardado'    => 'Producto registrado con éxito.',
        'actualizado' => 'Producto actualizado correctamente.',
    ];
    if (isset($mensajes_ok[$_GET['msg']])) {
        $mensaje = $mensajes_ok[$_GET['msg']];
        $tipo_mensaje = "exito";
    }
}

// MODO EDICIÓN (carga los datos del producto para precargar el modal)
$modo_edicion = false;
$producto_edit = [
    'id_producto'     => '',
    'id_categoria'    => '',
    'nombre'          => '',
    'descripcion'     => '',
    'imagen'          => null,
    'precio_unitario' => '',
    'stock'           => '',
    'stock_minimo'    => '5',
    'fecha_caducidad' => ''
];

if (isset($_GET['accion']) && $_GET['accion'] === 'editar' && isset($_GET['id'])) {
    $id_editar = intval($_GET['id']);

    $stmt_edit = $pdo->prepare("SELECT * FROM productos WHERE id_producto = :id");
    $stmt_edit->execute([':id' => $id_editar]);
    $datos = $stmt_edit->fetch();

    if ($datos) {
        $modo_edicion = true;
        $producto_edit = $datos;
    }
}

// PROCESAR FORMULARIO (POST) — alta o edición de producto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_producto'])) {
    $id_producto     = isset($_POST['id_producto']) ? intval($_POST['id_producto']) : 0;
    $id_categoria    = intval($_POST['id_categoria']);
    $nombre          = trim($_POST['nombre']);
    $descripcion     = trim($_POST['descripcion']);
    // Se guardan primero como texto, sin convertir todavía a número, para poder distinguir
    // "no es un número" de "es un número negativo o cero": floatval('abc') y
    // floatval('') dan 0.0 en silencio, lo que antes dejaba pasar basura como
    // si fuera un precio válido de ₡0. La validación de abajo revisa el texto
    // crudo con is_numeric()/ctype_digit() antes de castear.
    $precio_unitario = trim($_POST['precio_unitario'] ?? '');
    $stock           = trim($_POST['stock'] ?? '');
    $stock_minimo    = isset($_POST['stock_minimo']) && trim($_POST['stock_minimo']) !== '' ? trim($_POST['stock_minimo']) : '5';
    $fecha_caducidad = !empty($_POST['fecha_caducidad']) ? $_POST['fecha_caducidad'] : null;

    $fecha_actual = date('Y-m-d');
    $fecha_maxima = date('Y-m-d', strtotime('+100 years'));
    $limite_maximo_numerico = 1000000;

    // Para reabrir el modal con lo que la persona ya había escrito si algo falla.
    $modo_edicion = $id_producto > 0;
    $imagen_actual = null;
    if ($modo_edicion) {
        $stmt_imagen_actual = $pdo->prepare("SELECT imagen FROM productos WHERE id_producto = :id");
        $stmt_imagen_actual->execute([':id' => $id_producto]);
        $imagen_actual = $stmt_imagen_actual->fetchColumn() ?: null;
    }
    $producto_edit = [
        'id_producto'     => $id_producto,
        'id_categoria'    => $id_categoria,
        'nombre'          => $nombre,
        'descripcion'     => $descripcion,
        'imagen'          => $imagen_actual,
        'precio_unitario' => $precio_unitario,
        'stock'           => $stock,
        'stock_minimo'    => $stock_minimo,
        'fecha_caducidad' => $fecha_caducidad,
    ];

    // VALIDACIÓN DE LA FOTO DEL PRODUCTO (solo JPG/JPEG/PNG).
    // Igual que en exámenes: no confiamos solo en la extensión, usamos getimagesize() para confirmar
    // que el archivo realmente es una imagen válida.
    $imagen_error = '';
    $imagen_subida_valida = false;
    $extension_imagen = '';
    $extensiones_imagen_validas = ['jpg', 'jpeg', 'png'];
    $mimes_imagen_validos = ['image/jpeg', 'image/png'];

    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['imagen']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['imagen']['error'] === UPLOAD_ERR_FORM_SIZE) {
            $imagen_error = "La imagen es demasiado grande.";
        } elseif ($_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
            $imagen_error = "Ocurrió un error al subir la imagen. Intenta de nuevo.";
        } else {
            $extension_imagen = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));

            if (!in_array($extension_imagen, $extensiones_imagen_validas, true)) {
                $imagen_error = "La foto del producto debe ser JPG, JPEG o PNG.";
            } elseif ($_FILES['imagen']['size'] > 3 * 1024 * 1024) {
                $imagen_error = "La foto del producto no puede superar los 3 MB.";
            } else {
                $info_imagen = @getimagesize($_FILES['imagen']['tmp_name']);
                if ($info_imagen === false || !in_array($info_imagen['mime'], $mimes_imagen_validos, true)) {
                    $imagen_error = "El archivo no es una imagen JPG/PNG válida.";
                } else {
                    $imagen_subida_valida = true;
                }
            }
        }
    }

    // VALIDACIÓN DE LÍMITES DE CARACTERES, NÚMEROS Y FECHAS
    // Validaciones en cadena: la primera que falle define el mensaje de error.
    if (mb_strlen($nombre) > 20) {
        $mensaje = "El nombre del producto no puede exceder los 20 caracteres.";
        $tipo_mensaje = "error";
    } elseif (mb_strlen($descripcion) > 100) {
        $mensaje = "La descripción no puede exceder los 100 caracteres.";
        $tipo_mensaje = "error";
    } elseif (!is_numeric($precio_unitario) || (float) $precio_unitario < 0) {
        $mensaje = "Indica un precio unitario válido (0 o mayor, solo números).";
        $tipo_mensaje = "error";
    } elseif ((float) $precio_unitario > $limite_maximo_numerico) {
        $mensaje = "El precio unitario no puede superar los ₡1,000,000.";
        $tipo_mensaje = "error";
    } elseif (!ctype_digit($stock)) {
        $mensaje = "El stock actual debe ser un número entero válido (0 o mayor).";
        $tipo_mensaje = "error";
    } elseif ((int) $stock > $limite_maximo_numerico) {
        $mensaje = "El stock actual no puede superar 1,000,000 de unidades.";
        $tipo_mensaje = "error";
    } elseif (!ctype_digit($stock_minimo)) {
        $mensaje = "El stock mínimo debe ser un número entero válido (0 o mayor).";
        $tipo_mensaje = "error";
    } elseif ((int) $stock_minimo > $limite_maximo_numerico) {
        $mensaje = "El stock mínimo no puede superar 1,000,000.";
        $tipo_mensaje = "error";
    } elseif (!empty($fecha_caducidad) && ($fecha_caducidad < $fecha_actual || $fecha_caducidad > $fecha_maxima)) {
        $mensaje = "La fecha de caducidad debe ser entre hoy y máximo 100 años a futuro.";
        $tipo_mensaje = "error";
    } elseif ($imagen_error !== '') {
        $mensaje = $imagen_error;
        $tipo_mensaje = "error";
    } elseif (!empty($nombre) && $id_categoria > 0) {
        $precio_unitario = (float) $precio_unitario;
        $stock = (int) $stock;
        $stock_minimo = (int) $stock_minimo;
        try {
            // Solo ahora que el resto del formulario es válido, guardamos la
            // imagen en disco (si se subió una) con un nombre único.
            $ruta_imagen_nueva = null;
            if ($imagen_subida_valida) {
                $nombre_archivo_imagen = 'producto_' . uniqid() . '.' . $extension_imagen;
                $ruta_imagen_nueva = guardar_archivo_subido($_FILES['imagen']['tmp_name'], 'assets/images/productos', $nombre_archivo_imagen, $info_imagen['mime']);
                if ($ruta_imagen_nueva === null) {
                    throw new RuntimeException('No se pudo guardar la imagen en el servidor.');
                }
            }

            if ($id_producto > 0) {
                // ACTUALIZAR REGISTRO (ya existe)
                $sql = "UPDATE productos
                        SET id_categoria = :id_categoria, nombre = :nombre, descripcion = :descripcion,
                            precio_unitario = :precio_unitario, stock = :stock, stock_minimo = :stock_minimo,
                            fecha_caducidad = :fecha_caducidad" . ($ruta_imagen_nueva !== null ? ", imagen = :imagen" : "") . "
                        WHERE id_producto = :id";
                $stmt = $pdo->prepare($sql);
                $params_producto = [
                    ':id_categoria'    => $id_categoria,
                    ':nombre'          => $nombre,
                    ':descripcion'     => $descripcion,
                    ':precio_unitario' => $precio_unitario,
                    ':stock'           => $stock,
                    ':stock_minimo'    => $stock_minimo,
                    ':fecha_caducidad' => $fecha_caducidad,
                    ':id'              => $id_producto
                ];
                if ($ruta_imagen_nueva !== null) {
                    $params_producto[':imagen'] = $ruta_imagen_nueva;

                    // Borramos la foto anterior del disco para no acumular archivos huérfanos.
                    $stmt_imagen_anterior = $pdo->prepare("SELECT imagen FROM productos WHERE id_producto = :id");
                    $stmt_imagen_anterior->execute([':id' => $id_producto]);
                    $imagen_anterior = $stmt_imagen_anterior->fetchColumn();
                    eliminar_archivo_guardado($imagen_anterior ?: null);
                }
                $stmt->execute($params_producto);

                registrar_bitacora($pdo, 'UPDATE', "Se actualizó el producto #$id_producto ($nombre).");

                header("Location: inventario.php?msg=actualizado");
                exit;
            } else {
                // CREAR REGISTRO (es nuevo)
                $sql = "INSERT INTO productos (id_categoria, nombre, descripcion, imagen, precio_unitario, stock, stock_minimo, fecha_caducidad, activo)
                        VALUES (:id_categoria, :nombre, :descripcion, :imagen, :precio_unitario, :stock, :stock_minimo, :fecha_caducidad, 1)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':id_categoria'    => $id_categoria,
                    ':nombre'          => $nombre,
                    ':descripcion'     => $descripcion,
                    ':imagen'          => $ruta_imagen_nueva,
                    ':precio_unitario' => $precio_unitario,
                    ':stock'           => $stock,
                    ':stock_minimo'    => $stock_minimo,
                    ':fecha_caducidad' => $fecha_caducidad
                ]);

                $id_nuevo_producto = $pdo->lastInsertId();
                registrar_bitacora($pdo, 'INSERT', "Se registró el producto #$id_nuevo_producto ($nombre).");

                header("Location: inventario.php?msg=guardado");
                exit;
            }
        } catch (\Throwable $e) {
            registrar_bitacora($pdo, 'ERROR', "Falló " . ($id_producto > 0 ? "actualizar el producto #$id_producto" : "registrar un producto nuevo") . " ($nombre): " . $e->getMessage());
            $mensaje = $e instanceof RuntimeException ? $e->getMessage() : "Error en la operación: " . $e->getMessage();
            $tipo_mensaje = "error";
        }
    } else {
        $mensaje = "Por favor complete todos los campos obligatorios con valores válidos.";
        $tipo_mensaje = "error";
    }
}

// ¿El modal de agregar/editar debe abrirse solo al cargar la página?
$abrir_modal_formulario = $modo_edicion || ($tipo_mensaje === 'error' && isset($_POST['guardar_producto']));

// CONSULTAR CATEGORÍAS (solo las de tipo "producto")
$categorias = [];
try {
    $categorias = $pdo->query("SELECT id_categoria, nombre FROM categorias WHERE activo = 1 AND tipo = 'producto' ORDER BY nombre ASC")->fetchAll();
} catch (\PDOException $e) {
    $categorias = [];
}

// BANNER DE ALERTAS GENERALES
// Contamos, en una sola consulta, cuántos productos tienen cada tipo de problema.
$sql_alertas = "SELECT
    SUM(CASE WHEN stock <= stock_minimo THEN 1 ELSE 0 END) AS criticos,
    SUM(CASE WHEN fecha_caducidad IS NOT NULL AND fecha_caducidad < CURRENT_DATE() THEN 1 ELSE 0 END) AS vencidos,
    SUM(CASE WHEN fecha_caducidad IS NOT NULL AND fecha_caducidad >= CURRENT_DATE() AND fecha_caducidad <= DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS por_vencer
FROM productos";

$alertas = $pdo->query($sql_alertas)->fetch();
$productos_criticos   = (int) ($alertas['criticos'] ?? 0);
$productos_vencidos   = (int) ($alertas['vencidos'] ?? 0);
$productos_por_vencer = (int) ($alertas['por_vencer'] ?? 0);
$fecha_hoy = new DateTime(date('Y-m-d'));

// Deja constancia en bitácora de las condiciones de inventario que requieren
// atención (registrar_alerta_bitacora ya evita duplicar la misma alerta más
// de una vez por día, ver bitacora_helper.php).
if ($productos_criticos > 0) {
    registrar_alerta_bitacora($pdo, '[STOCK_BAJO]', "Hay $productos_criticos producto(s) con stock igual o menor al mínimo.");
}
if ($productos_vencidos > 0) {
    registrar_alerta_bitacora($pdo, '[VENCIDO]', "Hay $productos_vencidos producto(s) vencido(s) en inventario.");
}
if ($productos_por_vencer > 0) {
    registrar_alerta_bitacora($pdo, '[POR_VENCER]', "Hay $productos_por_vencer producto(s) a punto de vencer (próximos 30 días).");
}

// LÓGICA DE BÚSQUEDA, FILTROS (barra lateral) Y PAGINACIÓN
$busqueda         = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$categoria_filtro = isset($_GET['categoria']) ? intval($_GET['categoria']) : 0;
$estado_filtro    = isset($_GET['estado']) && in_array($_GET['estado'], ['1', '0'], true) ? $_GET['estado'] : '';
$caducidad_filtro = isset($_GET['caducidad']) && in_array($_GET['caducidad'], ['vigente', 'por_vencer', 'vencido', 'sin_fecha'], true) ? $_GET['caducidad'] : '';
$stock_filtro     = isset($_GET['stock_estado']) && in_array($_GET['stock_estado'], ['bajo', 'normal'], true) ? $_GET['stock_estado'] : '';
$precio_min       = isset($_GET['precio_min']) && $_GET['precio_min'] !== '' ? (float) $_GET['precio_min'] : null;
$precio_max       = isset($_GET['precio_max']) && $_GET['precio_max'] !== '' ? (float) $_GET['precio_max'] : null;
$limite = 5;
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;

// Armamos la consulta SQL en pedazos ("condiciones"), agregando solo los filtros que el usuario realmente usó.
$condiciones = [];
$params = [];

if ($busqueda !== '') {
    $condiciones[] = "(p.nombre LIKE :b1 OR c.nombre LIKE :b2 OR p.descripcion LIKE :b3)";
    $param_value = '%' . $busqueda . '%';
    $params[':b1'] = $param_value;
    $params[':b2'] = $param_value;
    $params[':b3'] = $param_value;
}

if ($categoria_filtro > 0) {
    $condiciones[] = "p.id_categoria = :categoria";
    $params[':categoria'] = $categoria_filtro;
}

if ($estado_filtro !== '') {
    $condiciones[] = "p.activo = :estado";
    $params[':estado'] = (int) $estado_filtro;
}

if ($precio_min !== null) {
    $condiciones[] = "p.precio_unitario >= :precio_min";
    $params[':precio_min'] = $precio_min;
}

if ($precio_max !== null) {
    $condiciones[] = "p.precio_unitario <= :precio_max";
    $params[':precio_max'] = $precio_max;
}

// "Vigente" aquí significa que le quedan más de 30 días, para no traslaparse con "por vencer".
if ($caducidad_filtro === 'vigente') {
    $condiciones[] = "p.fecha_caducidad IS NOT NULL AND p.fecha_caducidad > DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)";
} elseif ($caducidad_filtro === 'por_vencer') {
    $condiciones[] = "p.fecha_caducidad IS NOT NULL AND p.fecha_caducidad >= CURRENT_DATE() AND p.fecha_caducidad <= DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)";
} elseif ($caducidad_filtro === 'vencido') {
    $condiciones[] = "p.fecha_caducidad IS NOT NULL AND p.fecha_caducidad < CURRENT_DATE()";
} elseif ($caducidad_filtro === 'sin_fecha') {
    $condiciones[] = "p.fecha_caducidad IS NULL";
}

if ($stock_filtro === 'bajo') {
    $condiciones[] = "p.stock <= p.stock_minimo";
} elseif ($stock_filtro === 'normal') {
    $condiciones[] = "p.stock > p.stock_minimo";
}

// Unimos todas las condiciones activas con "AND" para formar el WHERE final.
$where_clause = count($condiciones) > 0 ? ' WHERE ' . implode(' AND ', $condiciones) . ' ' : '';

// Parámetros de filtro para conservarlos en los enlaces de paginación.
$query_filtros = '';
if ($busqueda !== '') $query_filtros .= '&buscar=' . urlencode($busqueda);
if ($categoria_filtro > 0) $query_filtros .= '&categoria=' . $categoria_filtro;
if ($estado_filtro !== '') $query_filtros .= '&estado=' . urlencode($estado_filtro);
if ($caducidad_filtro !== '') $query_filtros .= '&caducidad=' . urlencode($caducidad_filtro);
if ($stock_filtro !== '') $query_filtros .= '&stock_estado=' . urlencode($stock_filtro);
if ($precio_min !== null) $query_filtros .= '&precio_min=' . $precio_min;
if ($precio_max !== null) $query_filtros .= '&precio_max=' . $precio_max;

$hay_filtros_activos = $busqueda !== '' || $categoria_filtro > 0 || $estado_filtro !== '' || $caducidad_filtro !== '' || $stock_filtro !== '' || $precio_min !== null || $precio_max !== null;

// Conteo total para la paginación
$sql_total = "SELECT COUNT(*) FROM productos p LEFT JOIN categorias c ON p.id_categoria = c.id_categoria " . $where_clause;
$stmt_total = $pdo->prepare($sql_total);
$stmt_total->execute($params);
$total_registros = (int) $stmt_total->fetchColumn();

$total_paginas = max(1, (int) ceil($total_registros / $limite));
if ($pagina > $total_paginas) {
    $pagina = $total_paginas;
}

$inicio_int = (int) (($pagina - 1) * $limite); // Desde qué fila empezar (para el LIMIT de SQL).
$limite_int = (int) $limite;

// Consulta de productos paginada
$sql_productos = "SELECT p.*, c.nombre AS categoria_nombre
                  FROM productos p
                  LEFT JOIN categorias c ON p.id_categoria = c.id_categoria "
                  . $where_clause .
                  "ORDER BY p.id_producto DESC
                  LIMIT $inicio_int, $limite_int";

$stmt_productos = $pdo->prepare($sql_productos);
$stmt_productos->execute($params);
$lista_productos = $stmt_productos->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Inventario - OdontoNet</title>
    <link rel="icon" href="assets/images/logos/logo-favicon.svg" type="image/svg+xml">

    <link rel="stylesheet" href="assets/css/shared/variables.css">
    <link rel="stylesheet" href="assets/css/shared/global.css">
    <link rel="stylesheet" href="assets/css/shared/home.css">
    <link rel="stylesheet" href="assets/css/shared/layout.css">
    <link rel="stylesheet" href="assets/css/shared/componentes.css">
    <link rel="stylesheet" href="assets/css/usuarios.css">
    <link rel="stylesheet" href="assets/css/shared/inventario.css">
    <link rel="stylesheet" href="assets/css/pages/inventario.css">
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
                <h1>Gestión de <span class="grad">Inventario</span></h1>
            </div>
        </div>
    </section>

    <section class="wrap dash-wide">


        <!-- BANNER GLOBAL DE ALERTA -->
        <?php if ($productos_criticos > 0 || $productos_vencidos > 0 || $productos_por_vencer > 0): ?>
            <div class="dash-alerts">
                <?php if ($productos_vencidos > 0): ?>
                    <div class="dash-alert-item">
                        ⛔ <span><strong>Urgente:</strong> hay <strong><?= $productos_vencidos ?></strong> producto(s) <strong>vencidos</strong>.</span>
                    </div>
                <?php endif; ?>
                <?php if ($productos_por_vencer > 0): ?>
                    <div class="dash-alert-item warn">
                        ⚠️ <span><strong>Atención:</strong> hay <strong><?= $productos_por_vencer ?></strong> producto(s) a punto de vencer (próximos 30 días).</span>
                    </div>
                <?php endif; ?>
                <?php if ($productos_criticos > 0): ?>
                    <div class="dash-alert-item info">
                        📦 <span><strong>Stock bajo:</strong> hay <strong><?= $productos_criticos ?></strong> producto(s) en o por debajo del stock mínimo.</span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- LAYOUT: SIDEBAR DE FILTROS + CONTENIDO -->
        <form action="inventario.php" method="GET" class="dash-layout" id="formFiltros">

            <aside class="dash-sidebar">
                <h3>Filtros</h3>

                <div class="dash-filter-group">
                    <label for="categoria">Tipo de producto (categoría)</label>
                    <select name="categoria" id="categoria" class="form-control">
                        <option value="">Todas las categorías</option>
                        <?php foreach ($categorias as $cat): ?>
                            <option value="<?= (int) $cat['id_categoria'] ?>" <?= $categoria_filtro === (int) $cat['id_categoria'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dash-filter-group">
                    <label>Rango de precio (₡)</label>
                    <div class="dash-price-range">
                        <input type="number" min="0" step="0.01" name="precio_min" placeholder="Mín." class="form-control" value="<?= $precio_min !== null ? htmlspecialchars((string) $precio_min) : '' ?>">
                        <span class="muted-text">—</span>
                        <input type="number" min="0" step="0.01" name="precio_max" placeholder="Máx." class="form-control" value="<?= $precio_max !== null ? htmlspecialchars((string) $precio_max) : '' ?>">
                    </div>
                </div>

                <div class="dash-filter-group">
                    <label for="estado">Estado</label>
                    <select name="estado" id="estado" class="form-control">
                        <option value="">Todos</option>
                        <option value="1" <?= $estado_filtro === '1' ? 'selected' : '' ?>>Activo</option>
                        <option value="0" <?= $estado_filtro === '0' ? 'selected' : '' ?>>Inactivo</option>
                    </select>
                </div>

                <div class="dash-filter-group">
                    <label for="caducidad">Caducidad</label>
                    <select name="caducidad" id="caducidad" class="form-control">
                        <option value="">Todas</option>
                        <option value="vigente" <?= $caducidad_filtro === 'vigente' ? 'selected' : '' ?>>Vigente</option>
                        <option value="por_vencer" <?= $caducidad_filtro === 'por_vencer' ? 'selected' : '' ?>>Por vencer (30 días)</option>
                        <option value="vencido" <?= $caducidad_filtro === 'vencido' ? 'selected' : '' ?>>Vencido</option>
                        <option value="sin_fecha" <?= $caducidad_filtro === 'sin_fecha' ? 'selected' : '' ?>>Sin fecha de caducidad</option>
                    </select>
                </div>

                <div class="dash-filter-group">
                    <label for="stock_estado">Stock</label>
                    <select name="stock_estado" id="stock_estado" class="form-control">
                        <option value="">Todos</option>
                        <option value="bajo" <?= $stock_filtro === 'bajo' ? 'selected' : '' ?>>Stock bajo (≤ mínimo)</option>
                        <option value="normal" <?= $stock_filtro === 'normal' ? 'selected' : '' ?>>Stock normal</option>
                    </select>
                </div>

                <div class="dash-sidebar-actions">
                    <button type="submit" class="btn-primary">Aplicar filtros</button>
                    <?php if ($hay_filtros_activos): ?>
                        <a href="inventario.php" class="btn-cancel">Limpiar filtros</a>
                    <?php endif; ?>
                </div>
            </aside>

            <main class="dash-main">
                <div class="dash-search">
                    <button type="button" class="dash-fab-text" onclick="abrirModalProducto(true)">
                        <span class="dash-fab-text-plus" aria-hidden="true">
                            <span class="dash-fab-text-plus-icon"></span>
                        </span>
                        Registrar Producto
                    </button>
                    <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Buscar por producto, categoría o descripción..." class="form-control">
                    <button type="submit" class="btn-primary">Buscar</button>
                </div>

                <p class="dash-results-count">
                    <?= $total_registros ?> producto(s) encontrado(s)<?= $hay_filtros_activos ? ' con los filtros aplicados' : '' ?>.
                </p>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Categoría</th>
                                <th>Precio</th>
                                <th>Stock</th>
                                <th>Caducidad</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($lista_productos) > 0): ?>
                                <?php foreach ($lista_productos as $prod): ?>
                                    <?php
                                        // Calculamos, para cada producto, su estado de stock y caducidad.
                                        $es_stock_bajo = ((int) $prod['stock'] <= (int) $prod['stock_minimo']);
                                        $estado_caducidad = 'ok';
                                        $dias_restantes = null;

                                        if (!empty($prod['fecha_caducidad'])) {
                                            $fecha_cad = new DateTime($prod['fecha_caducidad']);
                                            $diff = $fecha_hoy->diff($fecha_cad);
                                            $dias_restantes = (int) $diff->format("%r%a"); // Negativo si ya pasó.

                                            if ($dias_restantes < 0) {
                                                $estado_caducidad = 'vencido';
                                            } elseif ($dias_restantes <= 30) {
                                                $estado_caducidad = 'por_vencer';
                                            }
                                        }

                                        // Le damos color de fondo a la fila según qué tan urgente sea el problema.
                                        $clase_fila = '';
                                        if ($estado_caducidad === 'vencido') {
                                            $clase_fila = 'dash-row-expired';
                                        } elseif ($estado_caducidad === 'por_vencer') {
                                            $clase_fila = 'dash-row-expiring';
                                        } elseif ($es_stock_bajo) {
                                            $clase_fila = 'dash-row-lowstock';
                                        }
                                    ?>
                                    <tr class="<?= $clase_fila ?>">
                                        <td>
                                            <div class="dash-product-cell">
                                                <?php if (!empty($prod['imagen'])): ?>
                                                    <img src="<?= htmlspecialchars($prod['imagen']) ?>" alt="<?= htmlspecialchars($prod['nombre']) ?>" class="dash-product-thumb">
                                                <?php else: ?>
                                                    <span class="dash-product-thumb-placeholder">N/A</span>
                                                <?php endif; ?>
                                                <strong><?= htmlspecialchars($prod['nombre']) ?></strong>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-paciente">
                                                <?= htmlspecialchars($prod['categoria_nombre'] ?? 'Sin categoría') ?>
                                            </span>
                                        </td>
                                        <td>₡<?= number_format($prod['precio_unitario'], 2) ?></td>
                                        <td>
                                            <strong class="<?= $es_stock_bajo ? 'stock-critico' : '' ?>"><?= htmlspecialchars($prod['stock']) ?></strong>
                                            <small class="muted-text">(mín: <?= htmlspecialchars($prod['stock_minimo']) ?>)</small>
                                        </td>
                                        <td>
                                            <?php if ($estado_caducidad === 'vencido'): ?>
                                                <span class="badge badge-cancelada">⛔ Vencido</span>
                                            <?php elseif ($estado_caducidad === 'por_vencer'): ?>
                                                <span class="badge badge-pendiente">⚠️ <?= $dias_restantes ?> días</span>
                                            <?php elseif (!empty($prod['fecha_caducidad'])): ?>
                                                <span class="badge badge-success">✓ Vigente</span>
                                            <?php else: ?>
                                                <span class="muted-text">No aplica</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?= (int) $prod['activo'] === 1 ? 'success' : 'cancelada' ?>">
                                                <?= (int) $prod['activo'] === 1 ? 'Activo' : 'Inactivo' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="inventario.php?accion=editar&id=<?= $prod['id_producto'] ?>" class="btn-action btn-edit">Editar</a>
                                            <?php if ((int) $prod['activo'] === 1): ?>
                                                <a href="#" class="btn-action btn-delete" onclick="confirmarInactivacion('inventario.php?accion=inactivar&id=<?= $prod['id_producto'] ?>', '<?= htmlspecialchars(addslashes($prod['nombre'])) ?>'); return false;">Inactivar</a>
                                            <?php else: ?>
                                                <a href="inventario.php?accion=activar&id=<?= $prod['id_producto'] ?>" class="btn-action btn-edit" style="background:rgba(46,204,113,0.15); color:#2ecc71; border-color:#2ecc71;">Activar</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="muted-text text-center">No se encontraron productos con estos filtros.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_paginas > 1): ?>
                    <div class="pagination-container">
                        <span class="pagination-info">Página <strong><?= $pagina ?></strong> de <strong><?= $total_paginas ?></strong> (<?= $total_registros ?> productos)</span>
                        <div class="pagination">
                            <?php if ($pagina > 1): ?>
                                <a href="inventario.php?pagina=<?= $pagina - 1 ?><?= $query_filtros ?>" class="page-link">&laquo; Anterior</a>
                            <?php endif; ?>
                            <?php foreach (obtener_paginas_visibles($pagina, $total_paginas) as $item): ?>
                                <?php if ($item === '...'): ?>
                                    <span class="page-ellipsis">&hellip;</span>
                                <?php else: ?>
                                    <a href="inventario.php?pagina=<?= $item ?><?= $query_filtros ?>" class="page-link <?= $item === $pagina ? 'active' : '' ?>"><?= $item ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($pagina < $total_paginas): ?>
                                <a href="inventario.php?pagina=<?= $pagina + 1 ?><?= $query_filtros ?>" class="page-link">Siguiente &raquo;</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </form>
    </section>

    <!-- MODAL: AGREGAR / EDITAR PRODUCTO -->
    <div id="modalProducto" class="dash-modal-overlay">
        <div class="dash-modal-card">
            <div class="dash-modal-head">
                <button type="button" class="dash-modal-close" onclick="cerrarModalProducto()" aria-label="Cerrar">&times;</button>
                <div class="sec-head" style="text-align:center; margin-bottom:0; width:100%;">
                    <span class="eyebrow" id="modalProductoEyebrow"><?= $modo_edicion ? 'Modificando Registro' : 'Nuevo Producto' ?></span>
                    <h2 id="modalProductoTitulo" style="margin:4px 0 0;"><?= $modo_edicion ? 'Editar Producto' : 'Nuevo producto' ?></h2>
                </div>
            </div>

            <form action="inventario.php" method="POST" id="formProducto" enctype="multipart/form-data">
                <input type="hidden" name="guardar_producto" value="1">
                <input type="hidden" name="id_producto" id="id_producto" value="<?= htmlspecialchars($producto_edit['id_producto']) ?>">

                <div class="form-grid">
                    <div class="form-group col-full">
                        <label for="nombre"><span class="dash-req">*</span> Nombre del Producto <span class="dash-hint">(máx. 20 caracteres)</span></label>
                        <input type="text" id="nombre" name="nombre" maxlength="20" value="<?= htmlspecialchars($producto_edit['nombre']) ?>" placeholder="Ej: Anestesia Cartuca 2%" required class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="id_categoria"><span class="dash-req">*</span> Categoría</label>
                        <?php if (count($categorias) > 0): ?>
                            <select name="id_categoria" id="id_categoria" required class="form-control">
                                <option value="">-- Seleccione Categoría --</option>
                                <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat['id_categoria']) ?>" <?= (int) $producto_edit['id_categoria'] === (int) $cat['id_categoria'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="number" id="id_categoria" name="id_categoria" value="<?= htmlspecialchars($producto_edit['id_categoria'] ?: '1') ?>" placeholder="ID Categoría (Ej: 1)" required class="form-control">
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="precio_unitario"><span class="dash-req">*</span> Precio Unitario (₡) <span class="dash-hint">(máx. 1,000,000)</span></label>
                        <input type="number" step="0.01" min="0" max="1000000" id="precio_unitario" name="precio_unitario" value="<?= htmlspecialchars($producto_edit['precio_unitario']) ?>" placeholder="0.00" required class="form-control input-limite-max">
                    </div>

                    <div class="form-group">
                        <label for="stock"><span class="dash-req">*</span> Stock Actual <span class="dash-hint">(máx. 1,000,000)</span></label>
                        <input type="number" id="stock" name="stock" min="0" max="1000000" value="<?= htmlspecialchars($producto_edit['stock']) ?>" placeholder="Ej: 50" required class="form-control input-limite-max">
                    </div>

                    <div class="form-group">
                        <label for="stock_minimo"><span class="dash-req">*</span> Stock Mínimo <span class="dash-hint">(máx. 1,000,000)</span></label>
                        <input type="number" id="stock_minimo" name="stock_minimo" min="0" max="1000000" value="<?= htmlspecialchars($producto_edit['stock_minimo']) ?>" placeholder="5" required class="form-control input-limite-max">
                    </div>

                    <div class="form-group col-full">
                        <label for="fecha_caducidad">Fecha de Caducidad <span class="dash-hint">(máx. 100 años a futuro)</span></label>
                        <input type="date" id="fecha_caducidad" name="fecha_caducidad" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+100 years')) ?>" value="<?= htmlspecialchars($producto_edit['fecha_caducidad'] ?? '') ?>" class="form-control">
                    </div>

                    <div class="form-group col-full">
                        <label for="descripcion">Descripción / Detalle <span class="dash-hint">(máx. 100 caracteres)</span></label>
                        <input type="text" id="descripcion" name="descripcion" maxlength="100" value="<?= htmlspecialchars($producto_edit['descripcion'] ?? '') ?>" placeholder="Especificaciones de uso, marca o proveedor..." class="form-control">
                    </div>

                    <div class="form-group col-full">
                        <label for="imagen">Foto del Producto <span class="dash-hint">(JPG, JPEG o PNG, máx. 3 MB)</span></label>
                        <input type="file" id="imagen" name="imagen" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="form-control">
                        <?php if (!empty($producto_edit['imagen'])): ?>
                            <div id="previewImagenActual" style="margin-top:10px; display:flex; align-items:center; gap:10px;">
                                <img src="<?= htmlspecialchars($producto_edit['imagen']) ?>" alt="Foto actual del producto" style="width:60px; height:60px; object-fit:cover; border-radius:8px; border:1px solid rgba(255,255,255,0.15);">
                                <small class="muted-text">Foto actual. Sube una nueva imagen para reemplazarla.</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModalProducto()">Cancelar</button>
                    <button type="submit" class="btn-primary btn-submit" id="btnGuardarProducto">
                        <?= $modo_edicion ? 'Actualizar Producto' : 'Guardar Producto' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: CONFIRMAR INACTIVACIÓN -->
    <div id="modalConfirmacion" class="custom-modal-overlay">
        <div class="custom-modal-card">
            <h3>¿Inactivar producto?</h3>
            <p id="modalMensajeTexto">¿Estás seguro de que deseas inactivar este producto? Podrás reactivarlo después.</p>
            <div class="custom-modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="cerrarModal()">Cancelar</button>
                <a id="btnConfirmarModal" href="#" class="btn-modal-confirm">Inactivar</a>
            </div>
        </div>
    </div>

    <?php require_once 'assets/includes/partials/footer.php'; ?>

    <style>
        /* Modal de confirmación de inactivación (mismo patrón visual usado en otros módulos). */
        .custom-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(10, 14, 23, 0.85);
            backdrop-filter: blur(6px);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        .custom-modal-overlay.active { display: flex; }
        .custom-modal-card {
            background: #121826;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 28px;
            max-width: 480px;
            width: 90%;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
            text-align: center;
        }
        .custom-modal-card h3 { color: #ffffff; font-size: 1.15rem; margin-bottom: 12px; }
        .custom-modal-card p { color: #94a3b8; font-size: 0.85rem; line-height: 1.5; margin-bottom: 24px; }
        .custom-modal-actions { display: flex; gap: 12px; justify-content: center; }
        .btn-modal-cancel {
            background: rgba(255, 255, 255, 0.08); color: #e2e8f0; border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 500; transition: all 0.2s;
        }
        .btn-modal-cancel:hover { background: rgba(255, 255, 255, 0.15); }
        .btn-modal-confirm {
            background: #e11d48; color: #ffffff; border: none; padding: 10px 20px; border-radius: 8px;
            text-decoration: none; font-weight: 600; transition: all 0.2s; display: inline-block;
        }
        .btn-modal-confirm:hover { background: #f43f5e; box-shadow: 0 0 12px rgba(225, 29, 72, 0.4); }
        select.form-control option { background-color: #121826; color: #ffffff; padding: 8px; }
        td { word-break: break-word; max-width: 220px; }
    </style>

    <script>
        // RESTRICCIÓN EN TIEMPO REAL AL ESCRIBIR MÁS DE 1 000 000
        // Si el usuario escribe un número mayor al límite, lo recorta automáticamente mientras escribe.
        document.querySelectorAll('.input-limite-max').forEach(input => {
            input.addEventListener('input', function () {
                const val = parseFloat(this.value);
                if (val > 1000000) this.value = 1000000;
            });
        });

        // MODAL DE PRODUCTO (agregar / editar)
        const modalProducto = document.getElementById('modalProducto');
        const formProducto = document.getElementById('formProducto');
        const campoIdProducto = document.getElementById('id_producto');
        const eyebrowModal = document.getElementById('modalProductoEyebrow');
        const tituloModal = document.getElementById('modalProductoTitulo');
        const botonGuardar = document.getElementById('btnGuardarProducto');

        // Abre el modal. Si "esNuevo" es true, limpia todo primero (para no arrastrar datos de una edición anterior).
        function abrirModalProducto(esNuevo) {
            if (esNuevo) {
                // No usamos formProducto.reset(): si el modal ya se había
                // renderizado precargado (modo edición), reset() volvería a
                // esos valores en vez de dejar el formulario en blanco.
                campoIdProducto.value = '';
                document.getElementById('nombre').value = '';
                document.getElementById('id_categoria').value = '';
                document.getElementById('precio_unitario').value = '';
                document.getElementById('stock').value = '';
                document.getElementById('stock_minimo').value = '5';
                document.getElementById('fecha_caducidad').value = '';
                document.getElementById('descripcion').value = '';
                document.getElementById('imagen').value = '';
                const previewImagenActual = document.getElementById('previewImagenActual');
                if (previewImagenActual) previewImagenActual.style.display = 'none';
                eyebrowModal.textContent = 'Registrar en el inventario';
                tituloModal.textContent = 'Nuevo producto';
                botonGuardar.textContent = 'Guardar Producto';
            }
            modalProducto.classList.add('active');
        }

        function cerrarModalProducto() {
            modalProducto.classList.remove('active');
        }

        modalProducto.addEventListener('click', function (e) {
            if (e.target === this) cerrarModalProducto();
        });

        // Si veníamos de "Editar" o de un error de validación al guardar, abrir el modal automáticamente.
        <?php if ($abrir_modal_formulario): ?>
            abrirModalProducto(false);
        <?php endif; ?>

        // MODAL DE CONFIRMACIÓN DE INACTIVACIÓN
        function confirmarInactivacion(url, nombre) {
            var mensaje = '¿Deseas inactivar el producto "' + nombre + '"? Podrás reactivarlo en cualquier momento.';
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
                cerrarModalProducto();
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
