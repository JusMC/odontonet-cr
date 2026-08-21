-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 18-08-2026 a las 15:46:37
-- Versión del servidor: 8.4.3
-- Versión de PHP: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `schema`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `abonos`
--

CREATE TABLE `abonos` (
  `id_abono` int NOT NULL,
  `id_pago_pendiente` int NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `metodo_pago` enum('efectivo','tarjeta','transferencia') NOT NULL,
  `id_usuario_registro` int NOT NULL,
  `fecha_pago` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `bitacora`
--

CREATE TABLE `bitacora` (
  `id_bitacora` int NOT NULL,
  `id_usuario` int DEFAULT NULL,
  `accion` enum('INSERT','UPDATE','DELETE','ERROR','LOGIN','LOGOUT','ALERTA') NOT NULL,
  `descripcion` text,
  `ip_origen` varchar(45) DEFAULT NULL,
  `fecha_hora` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `categorias`
--

CREATE TABLE `categorias` (
  `id_categoria` int NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `tipo` enum('producto','servicio') NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Volcado de datos para la tabla `categorias`
--

INSERT INTO `categorias` (`id_categoria`, `nombre`, `tipo`, `descripcion`, `activo`) VALUES
(1, 'Servicios Odontológicos', 'servicio', 'Servicios clínicos ofrecidos por la clínica dental', 1),
(2, 'Productos de cuidado oral', 'producto', 'Productos e insumos de higiene y cuidado dental', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `citas`
--

CREATE TABLE `citas` (
  `id_cita` int NOT NULL,
  `id_paciente` int NOT NULL,
  `id_doctor` int NOT NULL,
  `id_usuario_registro` int NOT NULL,
  `fecha_hora` datetime NOT NULL,
  `duracion_minutos` int NOT NULL DEFAULT '60',
  `motivo` varchar(255) DEFAULT NULL,
  `estado` enum('pendiente','en_espera','en_atencion','completada','cancelada','vencida') NOT NULL DEFAULT 'pendiente',
  `resultado` text,
  `hora_llegada` datetime DEFAULT NULL,
  `hora_inicio_atencion` datetime DEFAULT NULL,
  `es_control` tinyint(1) NOT NULL DEFAULT '0',
  `fecha_creacion` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Disparadores `citas`
--
-- Ambos usan DECLARE CONTINUE HANDLER FOR SQLEXCEPTION: si el INSERT a
-- bitácora fallara por cualquier motivo, el trigger simplemente lo absorbe
-- y no interrumpe el INSERT/UPDATE de `citas` que lo disparó — mismo
-- criterio que registrar_bitacora() ya aplica del lado de PHP (la
-- trazabilidad nunca debe bloquear la operación real). Probado forzando el
-- fallo del INSERT interno: la cita se sigue creando/actualizando con
-- normalidad, solo queda sin su registro de bitácora.
--
DELIMITER $$
CREATE TRIGGER `trg_cita_insert` AFTER INSERT ON `citas` FOR EACH ROW BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
    INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen)
    VALUES (
        NEW.id_usuario_registro,
        'INSERT',
        CONCAT('Se creó la cita número ', NEW.id_cita, ' para el paciente ', NEW.id_paciente),
        NULL
    );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_cita_update` AFTER UPDATE ON `citas` FOR EACH ROW BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
    INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen)
    VALUES (
        NEW.id_usuario_registro,
        'UPDATE',
        CONCAT(
            'Se actualizó la cita número ', NEW.id_cita,
            '. Estado anterior: ', OLD.estado,
            '. Estado nuevo: ', NEW.estado
        ),
        NULL
    );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `detalle_venta`
--

CREATE TABLE `detalle_venta` (
  `id_detalle` int NOT NULL,
  `id_venta` int NOT NULL,
  `tipo` enum('producto','servicio') NOT NULL,
  `id_referencia` int NOT NULL,
  `cantidad` int NOT NULL DEFAULT '1',
  `precio_unitario` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `doctores`
--

CREATE TABLE `doctores` (
  `id_doctor` int NOT NULL,
  `id_usuario` int NOT NULL,
  `especialidad` varchar(100) NOT NULL,
  `num_colegiado` varchar(50) NOT NULL,
  `horario_atencion` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `doctor_horarios`
--
-- Horario laboral estructurado del doctor (día de semana + bloque horario),
-- usado para validar que una cita no se agende fuera de su horario de
-- atención. `horario_atencion` en `doctores` sigue existiendo como texto
-- libre para mostrar en pantalla; esta tabla es la fuente de verdad
-- consultable. Un doctor sin filas aquí no tiene restricción de horario
-- (comportamiento previo, para no romper datos existentes).
--

CREATE TABLE `doctor_horarios` (
  `id_horario` int NOT NULL,
  `id_doctor` int NOT NULL,
  `dia_semana` tinyint NOT NULL COMMENT '0=domingo .. 6=sabado',
  `hora_inicio` time NOT NULL,
  `hora_fin` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `doctor_servicios`
--

CREATE TABLE `doctor_servicios` (
  `id_doctor` int NOT NULL,
  `id_servicio` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `examenes`
--

CREATE TABLE `examenes` (
  `id_examen` int NOT NULL,
  `id_paciente` int NOT NULL,
  `id_doctor` int NOT NULL,
  `id_cita` int DEFAULT NULL,
  `tipo_examen` varchar(100) NOT NULL,
  `fecha_solicitud` date NOT NULL,
  `estado` enum('solicitado','realizado','cancelado') NOT NULL DEFAULT 'solicitado',
  `resultado` text,
  `observaciones` text,
  `archivo` varchar(255) DEFAULT NULL,
  `fecha_creacion` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `historial_medico`
--

CREATE TABLE `historial_medico` (
  `id_historial` int NOT NULL,
  `id_paciente` int NOT NULL,
  `id_doctor` int NOT NULL,
  `id_cita` int DEFAULT NULL,
  `fecha_revision` datetime NOT NULL,
  `diagnostico` text,
  `tratamiento` text,
  `medicamentos` text,
  `observaciones` text,
  `proxima_revision` date DEFAULT NULL,
  `fecha_creacion` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `login_intentos`
--

CREATE TABLE `login_intentos` (
  `id` int UNSIGNED NOT NULL,
  `identificador` varchar(255) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `fecha` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `mfa_codigos_respaldo`
--

CREATE TABLE `mfa_codigos_respaldo` (
  `id_codigo` int UNSIGNED NOT NULL,
  `id_usuario` int NOT NULL,
  `codigo_hash` varchar(255) NOT NULL,
  `usado` tinyint(1) NOT NULL DEFAULT '0',
  `fecha_creacion` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_uso` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `mfa_totp`
--

CREATE TABLE `mfa_totp` (
  `id_mfa` int UNSIGNED NOT NULL,
  `id_usuario` int NOT NULL,
  `secreto_cifrado` varchar(255) NOT NULL,
  `app_nombre` varchar(50) DEFAULT NULL,
  `confirmado` tinyint(1) NOT NULL DEFAULT '0',
  `fecha_creacion` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_confirmacion` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pacientes`
--

CREATE TABLE `pacientes` (
  `id_paciente` int NOT NULL,
  `id_usuario` int NOT NULL,
  `antecedentes_medicos` text,
  `alergias` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Disparadores `pacientes`
--
-- Mismo criterio que los triggers de `citas`: un fallo del INSERT interno a
-- bitácora no debe impedir que el paciente se registre.
--
DELIMITER $$
CREATE TRIGGER `trg_paciente_insert` AFTER INSERT ON `pacientes` FOR EACH ROW BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
    INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen)
    VALUES (
        NEW.id_usuario,
        'INSERT',
        CONCAT('Se registró un nuevo paciente con id_usuario ', NEW.id_usuario),
        NULL
    );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pagos_pendientes`
--

CREATE TABLE `pagos_pendientes` (
  `id_pago_pendiente` int NOT NULL,
  `id_venta` int NOT NULL,
  `monto_total` decimal(10,2) NOT NULL,
  `monto_abonado` decimal(10,2) NOT NULL DEFAULT '0.00',
  `saldo_pendiente` decimal(10,2) NOT NULL,
  `fecha_limite_pago` date NOT NULL,
  `estado` enum('pendiente','abonado','pagado','vencido') NOT NULL DEFAULT 'pendiente',
  `fecha_creacion` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `password_resets`
--

CREATE TABLE `password_resets` (
  `id_reset` int UNSIGNED NOT NULL,
  `id_usuario` int NOT NULL,
  `codigo_hash` varchar(255) NOT NULL,
  `intentos` int UNSIGNED NOT NULL DEFAULT '0',
  `usado` tinyint(1) NOT NULL DEFAULT '0',
  `expira_en` datetime NOT NULL,
  `fecha_creacion` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `permisos`
--

CREATE TABLE `permisos` (
  `id_permiso` int NOT NULL,
  `modulo` varchar(100) NOT NULL,
  `pagina` varchar(100) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Volcado de datos para la tabla `permisos`
--

INSERT INTO `permisos` (`id_permiso`, `modulo`, `pagina`, `descripcion`) VALUES
(1, 'Inicio', 'inicio.php', 'Panel de inicio del paciente.'),
(2, 'Agendar citas', 'citas.php', 'Solicitar y consultar citas propias.'),
(3, 'Historial clínico', 'historial.php', 'Consultar el expediente y exámenes propios.'),
(4, 'Perfil', 'perfil.php', 'Consultar y editar los datos de la cuenta propia.'),
(5, 'Tienda', 'tienda.php', 'Comprar productos de cuidado dental.'),
(6, 'Panel Administrador', 'admin.php', 'Panel general de administración.'),
(7, 'Gestión de Usuarios', 'usuarios.php', 'Crear, editar y desactivar cuentas del sistema, con filtros y buscador.'),
(8, 'Bitácora', 'bitacora.php', 'Consultar la trazabilidad de acciones del sistema.'),
(9, 'Control de Citas', 'control_citas.php', 'Agendar, registrar llegada e iniciar atención de citas, con filtros y buscador.'),
(10, 'Expediente Odontológico', 'expediente.php', 'Documentar diagnóstico, tratamiento y control de citas.'),
(11, 'Exámenes y Radiografías', 'examenes.php', 'Solicitar y registrar resultados de exámenes.'),
(12, 'Inventario', 'inventario.php', 'Registrar productos, stock y fechas de caducidad, con filtros y buscador.'),
(13, 'Tratamientos', 'tratamientos.php', 'Catálogo de tratamientos, doctores e insumos asociados.'),
(14, 'Promociones y Paquetes', 'promociones.php', 'Crear y administrar promociones y descuentos.'),
(15, 'Facturar', 'facturar_servicios.php', 'Generar facturas de servicios/tratamientos.'),
(16, 'Odontólogos', 'odontologos.php', 'Consultar ficha de doctores: especialidad, horario, tratamientos y citas asignadas.'),
(17, 'Pagos Pendientes', 'pagos_pendientes.php', 'Registrar abonos y consultar saldos pendientes de las facturas.'),
(18, 'Permisos por Rol', 'permisos.php', 'Consultar la matriz de módulos y roles con acceso a cada uno.'),
(19, 'Pacientes', 'pacientes.php', 'Buscar pacientes existentes y actualizar sus datos de contacto y clínicos.');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `productos`
--

CREATE TABLE `productos` (
  `id_producto` int NOT NULL,
  `id_categoria` int NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `imagen` varchar(255) DEFAULT NULL,
  `precio_unitario` decimal(10,2) NOT NULL,
  `stock` int NOT NULL DEFAULT '0',
  `stock_minimo` int NOT NULL DEFAULT '5',
  `fecha_caducidad` date DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `fecha_creacion` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Volcado de datos para la tabla `productos`
--

INSERT INTO `productos` (`id_producto`, `id_categoria`, `nombre`, `descripcion`, `imagen`, `precio_unitario`, `stock`, `stock_minimo`, `fecha_caducidad`, `activo`, `fecha_creacion`) VALUES
(1, 2, 'Cepillo Dental', 'Oral B - Cross Action - 8 Unidades', 'assets/images/productos/producto_6a8202b8daa17.jpg', 5000.00, 25, 5, '2126-08-16', 1, '2026-08-16 12:34:32'),
(2, 2, 'Cepillo Dental', 'Colgate - Zig Zag - Cerdas con Infusión de Carbón - 8 Unidades', 'assets/images/productos/producto_6a821d13c5d8d.jpg', 4500.00, 15, 3, '2126-08-16', 1, '2026-08-16 14:26:59'),
(3, 2, 'Enjuague Bucal', 'Member\'s Selection - Menta Fresca - 2 Unidades - 1.5 L / 50.7 oz', 'assets/images/productos/producto_6a821f25bdddc.jpg', 5495.00, 10, 2, '2045-12-25', 1, '2026-08-16 14:35:49'),
(4, 2, 'Pasta Dental', 'Colgate - Optic White Stain Fighter con Bicarbonato - 5 Unidades - 170 g / 6 oz', 'assets/images/productos/producto_6a8220378e331.jpg', 6995.00, 18, 3, '2048-05-20', 1, '2026-08-16 14:40:23'),
(5, 2, 'Hilo Dental', 'Oral B - Glide All-in-one - 6 Unidades - 44 m', 'assets/images/productos/producto_6a82212ca8c70.jpg', 4895.00, 10, 2, '2050-12-31', 1, '2026-08-16 14:44:28'),
(6, 2, 'Pasta Dental', 'Colgate - MaxWhite Limpieza Completa - 5 Unidades - 136 mL', 'assets/images/productos/producto_6a8222b674763.jpg', 7495.00, 5, 1, '2035-08-16', 1, '2026-08-16 14:51:02');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `promociones`
--

CREATE TABLE `promociones` (
  `id_promocion` int NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `tipo` enum('paquete','descuento_porcentual','descuento_fijo') NOT NULL,
  `valor_descuento` decimal(10,2) DEFAULT NULL,
  `precio_paquete` decimal(10,2) DEFAULT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `fecha_creacion` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `promocion_servicios`
--

CREATE TABLE `promocion_servicios` (
  `id_promocion` int NOT NULL,
  `id_servicio` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

CREATE TABLE `roles` (
  `id_rol` int NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `fecha_creacion` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Volcado de datos para la tabla `roles`
--

INSERT INTO `roles` (`id_rol`, `nombre`, `descripcion`, `activo`, `fecha_creacion`) VALUES
(1, 'paciente', 'Paciente registrado en la clínica', 1, '2026-07-07 19:41:40'),
(2, 'doctor', 'Odontólogo de la clínica', 1, '2026-07-07 19:41:40'),
(3, 'recepcionista', 'Personal de recepción', 1, '2026-07-07 19:41:40'),
(4, 'administrador', 'Administrador del sistema', 1, '2026-07-07 19:41:40');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `rol_permiso`
--

CREATE TABLE `rol_permiso` (
  `id_rol` int NOT NULL,
  `id_permiso` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Volcado de datos para la tabla `rol_permiso`
--

INSERT INTO `rol_permiso` (`id_rol`, `id_permiso`) VALUES
(1, 1),
(2, 1),
(3, 1),
(4, 1),
(1, 2),
(2, 2),
(3, 2),
(4, 2),
(1, 3),
(2, 3),
(3, 3),
(4, 3),
(1, 4),
(2, 4),
(3, 4),
(4, 4),
(1, 5),
(2, 5),
(3, 5),
(4, 5),
(4, 6),
(4, 7),
(4, 8),
(2, 9),
(3, 9),
(4, 9),
(2, 10),
(3, 10),
(4, 10),
(2, 11),
(4, 11),
(3, 12),
(4, 12),
(3, 13),
(4, 13),
(3, 14),
(4, 14),
(3, 15),
(4, 15),
(3, 16),
(4, 16),
(3, 17),
(4, 17),
(4, 18),
(3, 19),
(4, 19);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `servicios`
--

CREATE TABLE `servicios` (
  `id_servicio` int NOT NULL,
  `id_categoria` int NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `precio` decimal(10,2) NOT NULL,
  `duracion_minutos` int NOT NULL DEFAULT '60',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `fecha_creacion` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Volcado de datos para la tabla `servicios`
--

INSERT INTO `servicios` (`id_servicio`, `id_categoria`, `nombre`, `descripcion`, `precio`, `duracion_minutos`, `activo`, `fecha_creacion`) VALUES
(1, 1, 'Chequeo general', 'Valoración general del estado bucal del paciente', 15000.00, 30, 1, '2026-07-31 11:56:02'),
(2, 1, 'Limpieza dental', 'Limpieza profesional y remoción de sarro', 20000.00, 45, 1, '2026-07-31 11:56:02'),
(3, 1, 'Blanqueamiento dental', 'Tratamiento estético para recuperar el brillo natural de los dientes', 45000.00, 60, 1, '2026-07-31 11:56:02'),
(4, 1, 'Ortodoncia', 'Brackets y alineadores con seguimiento personalizado', 80000.00, 60, 1, '2026-07-31 11:56:02'),
(5, 1, 'Endodoncia', 'Tratamiento de conducto con tecnología moderna', 60000.00, 90, 1, '2026-07-31 11:56:02'),
(6, 1, 'Urgencias dentales', 'Atención prioritaria para dolor, fracturas o molestias urgentes', 25000.00, 30, 1, '2026-07-31 11:56:02');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `servicio_productos`
--

CREATE TABLE `servicio_productos` (
  `id_servicio` int NOT NULL,
  `id_producto` int NOT NULL,
  `cantidad_requerida` int NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sesiones`
--
-- Almacenamiento de las sesiones de PHP (ver assets/includes/db_session_handler.php).
-- Necesaria en un entorno serverless (ej. Vercel), donde no se puede confiar en
-- que los archivos de sesión del disco local persistan entre solicitudes.
--

CREATE TABLE `sesiones` (
  `id` varchar(128) NOT NULL,
  `datos` mediumtext,
  `expira_en` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id_usuario` int NOT NULL,
  `id_rol` int NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `apellido` varchar(100) NOT NULL,
  `cedula` varchar(20) DEFAULT NULL,
  `fecha_nacimiento` date DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `correo` varchar(150) NOT NULL,
  `contrasena` varchar(255) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `foto_perfil` varchar(255) DEFAULT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT '1',
  `fecha_creacion` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ventas`
--

CREATE TABLE `ventas` (
  `id_venta` int NOT NULL,
  `id_paciente` int NOT NULL,
  `id_usuario` int NOT NULL,
  `fecha_venta` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `subtotal` decimal(10,2) NOT NULL,
  `descuento` decimal(10,2) NOT NULL DEFAULT '0.00',
  `id_promocion` int DEFAULT NULL,
  `impuesto` decimal(10,2) NOT NULL DEFAULT '0.00',
  `total` decimal(10,2) NOT NULL,
  `metodo_pago` enum('efectivo','tarjeta','transferencia') NOT NULL,
  `estado` enum('pendiente','pagada','anulada') NOT NULL DEFAULT 'pendiente'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `v_pacientes_admin`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `v_pacientes_admin` (
`apellido` varchar(100)
,`cedula` varchar(20)
,`correo` varchar(150)
,`direccion` varchar(255)
,`edad` bigint
,`estado` tinyint(1)
,`fecha_creacion` datetime
,`fecha_nacimiento` date
,`id_paciente` int
,`id_usuario` int
,`nombre` varchar(100)
,`nombre_completo` varchar(201)
,`telefono` varchar(20)
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `v_resumen_admin`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `v_resumen_admin` (
`citas_programadas` bigint
,`pacientes_registrados` bigint
,`productos_en_inventario` bigint
,`ventas_realizadas` bigint
);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `abonos`
--
ALTER TABLE `abonos`
  ADD PRIMARY KEY (`id_abono`),
  ADD KEY `fk_abono_pago_pendiente` (`id_pago_pendiente`),
  ADD KEY `fk_abono_usuario` (`id_usuario_registro`);

--
-- Indices de la tabla `bitacora`
--
ALTER TABLE `bitacora`
  ADD PRIMARY KEY (`id_bitacora`),
  ADD KEY `idx_bitacora_usuario` (`id_usuario`);

--
-- Indices de la tabla `categorias`
--
ALTER TABLE `categorias`
  ADD PRIMARY KEY (`id_categoria`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `citas`
--
ALTER TABLE `citas`
  ADD PRIMARY KEY (`id_cita`),
  ADD KEY `fk_citas_paciente` (`id_paciente`),
  ADD KEY `fk_citas_doctor` (`id_doctor`),
  ADD KEY `fk_citas_usuario` (`id_usuario_registro`);

--
-- Indices de la tabla `detalle_venta`
--
ALTER TABLE `detalle_venta`
  ADD PRIMARY KEY (`id_detalle`),
  ADD KEY `fk_detalle_venta` (`id_venta`);

--
-- Indices de la tabla `doctores`
--
ALTER TABLE `doctores`
  ADD PRIMARY KEY (`id_doctor`),
  ADD UNIQUE KEY `id_usuario` (`id_usuario`),
  ADD UNIQUE KEY `num_colegiado` (`num_colegiado`);

--
-- Indices de la tabla `doctor_horarios`
--
ALTER TABLE `doctor_horarios`
  ADD PRIMARY KEY (`id_horario`),
  ADD KEY `fk_doctor_horarios_doctor` (`id_doctor`);

--
-- Indices de la tabla `doctor_servicios`
--
ALTER TABLE `doctor_servicios`
  ADD PRIMARY KEY (`id_doctor`,`id_servicio`),
  ADD KEY `fk_docserv_servicio` (`id_servicio`);

--
-- Indices de la tabla `examenes`
--
ALTER TABLE `examenes`
  ADD PRIMARY KEY (`id_examen`),
  ADD KEY `fk_examenes_paciente` (`id_paciente`),
  ADD KEY `fk_examenes_doctor` (`id_doctor`),
  ADD KEY `fk_examenes_cita` (`id_cita`);

--
-- Indices de la tabla `historial_medico`
--
ALTER TABLE `historial_medico`
  ADD PRIMARY KEY (`id_historial`),
  ADD KEY `fk_historial_paciente` (`id_paciente`),
  ADD KEY `fk_historial_doctor` (`id_doctor`),
  ADD KEY `fk_historial_cita` (`id_cita`);

--
-- Indices de la tabla `login_intentos`
--
ALTER TABLE `login_intentos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_identificador` (`identificador`),
  ADD KEY `idx_ip` (`ip`),
  ADD KEY `idx_fecha` (`fecha`);

--
-- Indices de la tabla `mfa_codigos_respaldo`
--
ALTER TABLE `mfa_codigos_respaldo`
  ADD PRIMARY KEY (`id_codigo`),
  ADD KEY `idx_mfa_codigos_usuario` (`id_usuario`);

--
-- Indices de la tabla `mfa_totp`
--
ALTER TABLE `mfa_totp`
  ADD PRIMARY KEY (`id_mfa`),
  ADD KEY `idx_mfa_totp_usuario` (`id_usuario`);

--
-- Indices de la tabla `pacientes`
--
ALTER TABLE `pacientes`
  ADD PRIMARY KEY (`id_paciente`),
  ADD UNIQUE KEY `uq_pacientes_usuario` (`id_usuario`);

--
-- Indices de la tabla `pagos_pendientes`
--
ALTER TABLE `pagos_pendientes`
  ADD PRIMARY KEY (`id_pago_pendiente`),
  ADD UNIQUE KEY `uq_pagopendiente_venta` (`id_venta`);

--
-- Indices de la tabla `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id_reset`),
  ADD KEY `idx_password_resets_usuario` (`id_usuario`);

--
-- Indices de la tabla `permisos`
--
ALTER TABLE `permisos`
  ADD PRIMARY KEY (`id_permiso`),
  ADD UNIQUE KEY `uq_permisos_pagina` (`pagina`);

--
-- Indices de la tabla `productos`
--
ALTER TABLE `productos`
  ADD PRIMARY KEY (`id_producto`),
  ADD KEY `fk_productos_categoria` (`id_categoria`);

--
-- Indices de la tabla `promociones`
--
ALTER TABLE `promociones`
  ADD PRIMARY KEY (`id_promocion`);

--
-- Indices de la tabla `promocion_servicios`
--
ALTER TABLE `promocion_servicios`
  ADD PRIMARY KEY (`id_promocion`,`id_servicio`),
  ADD KEY `fk_promoserv_servicio` (`id_servicio`);

--
-- Indices de la tabla `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id_rol`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `rol_permiso`
--
ALTER TABLE `rol_permiso`
  ADD PRIMARY KEY (`id_rol`,`id_permiso`),
  ADD KEY `fk_rolperm_permiso` (`id_permiso`);

--
-- Indices de la tabla `servicios`
--
ALTER TABLE `servicios`
  ADD PRIMARY KEY (`id_servicio`),
  ADD KEY `fk_servicios_categoria` (`id_categoria`);

--
-- Indices de la tabla `servicio_productos`
--
ALTER TABLE `servicio_productos`
  ADD PRIMARY KEY (`id_servicio`,`id_producto`),
  ADD KEY `fk_servprod_producto` (`id_producto`);

--
-- Indices de la tabla `sesiones`
--
ALTER TABLE `sesiones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sesiones_expira` (`expira_en`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `correo` (`correo`),
  ADD UNIQUE KEY `cedula` (`cedula`),
  ADD KEY `fk_usuarios_rol` (`id_rol`);

--
-- Indices de la tabla `ventas`
--
ALTER TABLE `ventas`
  ADD PRIMARY KEY (`id_venta`),
  ADD KEY `fk_ventas_paciente` (`id_paciente`),
  ADD KEY `fk_ventas_usuario` (`id_usuario`),
  ADD KEY `fk_ventas_promocion` (`id_promocion`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `abonos`
--
ALTER TABLE `abonos`
  MODIFY `id_abono` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `bitacora`
--
ALTER TABLE `bitacora`
  MODIFY `id_bitacora` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `categorias`
--
ALTER TABLE `categorias`
  MODIFY `id_categoria` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `citas`
--
ALTER TABLE `citas`
  MODIFY `id_cita` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `detalle_venta`
--
ALTER TABLE `detalle_venta`
  MODIFY `id_detalle` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `doctor_horarios`
--
ALTER TABLE `doctor_horarios`
  MODIFY `id_horario` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `doctores`
--
ALTER TABLE `doctores`
  MODIFY `id_doctor` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `examenes`
--
ALTER TABLE `examenes`
  MODIFY `id_examen` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `historial_medico`
--
ALTER TABLE `historial_medico`
  MODIFY `id_historial` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `login_intentos`
--
ALTER TABLE `login_intentos`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `mfa_codigos_respaldo`
--
ALTER TABLE `mfa_codigos_respaldo`
  MODIFY `id_codigo` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `mfa_totp`
--
ALTER TABLE `mfa_totp`
  MODIFY `id_mfa` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `pacientes`
--
ALTER TABLE `pacientes`
  MODIFY `id_paciente` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `pagos_pendientes`
--
ALTER TABLE `pagos_pendientes`
  MODIFY `id_pago_pendiente` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id_reset` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `permisos`
--
ALTER TABLE `permisos`
  MODIFY `id_permiso` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT de la tabla `productos`
--
ALTER TABLE `productos`
  MODIFY `id_producto` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `promociones`
--
ALTER TABLE `promociones`
  MODIFY `id_promocion` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `roles`
--
ALTER TABLE `roles`
  MODIFY `id_rol` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `servicios`
--
ALTER TABLE `servicios`
  MODIFY `id_servicio` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id_usuario` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ventas`
--
ALTER TABLE `ventas`
  MODIFY `id_venta` int NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Estructura para la vista `v_pacientes_admin`
--
DROP TABLE IF EXISTS `v_pacientes_admin`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_pacientes_admin`  AS SELECT `p`.`id_paciente` AS `id_paciente`, `u`.`id_usuario` AS `id_usuario`, `u`.`cedula` AS `cedula`, `u`.`nombre` AS `nombre`, `u`.`apellido` AS `apellido`, concat(`u`.`nombre`,' ',`u`.`apellido`) AS `nombre_completo`, `u`.`correo` AS `correo`, `u`.`telefono` AS `telefono`, `u`.`fecha_nacimiento` AS `fecha_nacimiento`, timestampdiff(YEAR,`u`.`fecha_nacimiento`,curdate()) AS `edad`, `u`.`direccion` AS `direccion`, `u`.`estado` AS `estado`, `u`.`fecha_creacion` AS `fecha_creacion` FROM (`pacientes` `p` join `usuarios` `u` on((`p`.`id_usuario` = `u`.`id_usuario`))) ;

-- --------------------------------------------------------

--
-- Estructura para la vista `v_resumen_admin`
--
DROP TABLE IF EXISTS `v_resumen_admin`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_resumen_admin`  AS SELECT (select count(0) from `pacientes`) AS `pacientes_registrados`, (select count(0) from `citas` where (`citas`.`estado` in ('pendiente','en_espera','en_atencion'))) AS `citas_programadas`, (select count(0) from `productos` where (`productos`.`activo` = 1)) AS `productos_en_inventario`, (select count(0) from `ventas` where (`ventas`.`estado` = 'pagada')) AS `ventas_realizadas` ;

-- --------------------------------------------------------

--
-- Procedimientos almacenados
--
-- Ambos manejan su propia transacción completa (START TRANSACTION /
-- COMMIT) y usan `DECLARE EXIT HANDLER FOR SQLEXCEPTION` para hacer
-- ROLLBACK y relanzar el error (RESIGNAL) ante cualquier fallo, incluyendo
-- los que el propio procedimiento levanta con SIGNAL (deuda inexistente,
-- stock insuficiente, etc.). Se llaman desde PHP con `CALL ...` en vez de
-- reproducir esta lógica con `$pdo->beginTransaction()/commit()/rollBack()`.
--

DELIMITER $$

--
-- Registra un abono a una deuda de `pagos_pendientes`: valida el monto,
-- inserta el abono, recalcula saldo/estado y, si la deuda queda saldada,
-- marca la venta asociada como 'pagada'. Usado por pagos_pendientes.php.
--
CREATE PROCEDURE `sp_registrar_abono` (
    IN `p_id_pago_pendiente` INT,
    IN `p_monto` DECIMAL(10,2),
    IN `p_metodo_pago` VARCHAR(20),
    IN `p_id_usuario_registro` INT,
    OUT `p_id_abono` INT,
    OUT `p_nuevo_saldo` DECIMAL(10,2),
    OUT `p_nuevo_estado` VARCHAR(20)
)
BEGIN
    DECLARE v_monto_total DECIMAL(10,2);
    DECLARE v_monto_abonado DECIMAL(10,2);
    DECLARE v_saldo_actual DECIMAL(10,2);
    DECLARE v_id_venta INT;
    DECLARE v_mensaje_error TEXT;

    -- Ante cualquier error (incluidos los SIGNAL de validación de abajo):
    -- deshace todo lo que este procedimiento haya hecho, pero deja constancia
    -- del error en bitácora (requerimiento de profesor #4) ANTES de relanzarlo.
    -- El INSERT corre en autocommit porque ya no hay transacción activa tras
    -- el ROLLBACK, así que ese registro sobrevive aunque el resto se revierta.
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_mensaje_error = MESSAGE_TEXT;
        ROLLBACK;
        INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen)
        VALUES (
            p_id_usuario_registro,
            'ERROR',
            CONCAT('Falló sp_registrar_abono (deuda #', IFNULL(p_id_pago_pendiente, 'NULL'), ', monto ', IFNULL(p_monto, 'NULL'), '): ', v_mensaje_error),
            NULL
        );
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT monto_total, monto_abonado, saldo_pendiente, id_venta
      INTO v_monto_total, v_monto_abonado, v_saldo_actual, v_id_venta
      FROM pagos_pendientes
     WHERE id_pago_pendiente = p_id_pago_pendiente
     FOR UPDATE;

    IF v_monto_total IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La deuda indicada no existe.';
    END IF;

    IF p_monto IS NULL OR p_monto <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El monto del abono debe ser mayor a 0.';
    END IF;

    IF p_monto > v_saldo_actual THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El abono no puede ser mayor al saldo pendiente.';
    END IF;

    INSERT INTO abonos (id_pago_pendiente, monto, metodo_pago, id_usuario_registro)
    VALUES (p_id_pago_pendiente, p_monto, p_metodo_pago, p_id_usuario_registro);
    SET p_id_abono = LAST_INSERT_ID();

    SET p_nuevo_saldo = v_saldo_actual - p_monto;
    SET p_nuevo_estado = IF(p_nuevo_saldo <= 0, 'pagado', 'abonado');

    UPDATE pagos_pendientes
       SET monto_abonado = v_monto_abonado + p_monto,
           saldo_pendiente = p_nuevo_saldo,
           estado = p_nuevo_estado
     WHERE id_pago_pendiente = p_id_pago_pendiente;

    IF p_nuevo_estado = 'pagado' THEN
        UPDATE ventas SET estado = 'pagada' WHERE id_venta = v_id_venta;
    END IF;

    INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen)
    VALUES (
        p_id_usuario_registro,
        'INSERT',
        CONCAT('Se registró un abono de ₡', FORMAT(p_monto, 2), ' a la deuda #', p_id_pago_pendiente, ' (sp_registrar_abono). Saldo restante: ₡', FORMAT(p_nuevo_saldo, 2), '.'),
        NULL
    );

    COMMIT;
END$$

--
-- Registra una venta de productos (carrito de tienda.php/compra.php) en
-- una sola llamada: recibe los artículos como JSON, valida disponibilidad,
-- descuenta stock de forma atómica por artículo y crea la venta + su
-- detalle. Usado por compra.php (efectivo/transferencia) y pago_exito.php
-- (tarjeta, tras confirmar el pago con Stripe) — mismo procedimiento para
-- ambos métodos de pago.
--
-- p_items: JSON con forma [{"id_producto": 1, "cantidad": 2}, ...]
--
CREATE PROCEDURE `sp_registrar_venta_productos` (
    IN `p_id_paciente` INT,
    IN `p_id_usuario` INT,
    IN `p_metodo_pago` VARCHAR(20),
    IN `p_items` JSON,
    OUT `p_id_venta` INT,
    OUT `p_total` DECIMAL(10,2)
)
BEGIN
    DECLARE v_subtotal DECIMAL(10,2) DEFAULT 0;
    DECLARE v_impuesto DECIMAL(10,2) DEFAULT 0;
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_id_producto INT;
    DECLARE v_cantidad INT;
    DECLARE v_precio DECIMAL(10,2);
    DECLARE v_nombre VARCHAR(150);
    DECLARE v_total_items INT DEFAULT 0;
    DECLARE v_mensaje_error TEXT;

    DECLARE cur CURSOR FOR
        SELECT jt.id_producto, jt.cantidad
          FROM JSON_TABLE(
                 p_items, '$[*]'
                 COLUMNS(
                     id_producto INT PATH '$.id_producto',
                     cantidad    INT PATH '$.cantidad'
                 )
               ) AS jt;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    -- Igual que en sp_registrar_abono: deja constancia del error en
    -- bitácora (requerimiento de profesor #4) después del ROLLBACK, para
    -- que el registro del fallo sobreviva aunque se revierta la venta.
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_mensaje_error = MESSAGE_TEXT;
        ROLLBACK;
        INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen)
        VALUES (
            p_id_usuario,
            'ERROR',
            CONCAT('Falló sp_registrar_venta_productos (paciente #', IFNULL(p_id_paciente, 'NULL'), ', método ', IFNULL(p_metodo_pago, 'NULL'), '): ', v_mensaje_error),
            NULL
        );
        RESIGNAL;
    END;

    IF p_items IS NULL OR JSON_LENGTH(p_items) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El carrito está vacío.';
    END IF;

    START TRANSACTION;

    INSERT INTO ventas (id_paciente, id_usuario, subtotal, descuento, impuesto, total, metodo_pago, estado)
    VALUES (p_id_paciente, p_id_usuario, 0, 0, 0, 0, p_metodo_pago, 'pagada');
    SET p_id_venta = LAST_INSERT_ID();

    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO v_id_producto, v_cantidad;
        IF v_done THEN
            LEAVE read_loop;
        END IF;

        SET v_total_items = v_total_items + 1;

        IF v_cantidad IS NULL OR v_cantidad <= 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Uno de los productos del carrito tiene una cantidad inválida.';
        END IF;

        SELECT nombre, precio_unitario INTO v_nombre, v_precio
          FROM productos
         WHERE id_producto = v_id_producto
           AND activo = 1
           AND (fecha_caducidad IS NULL OR fecha_caducidad >= CURDATE())
         FOR UPDATE;

        IF v_precio IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Uno de los productos del carrito ya no está disponible.';
        END IF;

        UPDATE productos
           SET stock = stock - v_cantidad
         WHERE id_producto = v_id_producto
           AND stock >= v_cantidad;

        IF ROW_COUNT() = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No hay stock suficiente de uno de los productos del carrito.';
        END IF;

        INSERT INTO detalle_venta (id_venta, tipo, id_referencia, cantidad, precio_unitario, subtotal)
        VALUES (p_id_venta, 'producto', v_id_producto, v_cantidad, v_precio, v_precio * v_cantidad);

        SET v_subtotal = v_subtotal + (v_precio * v_cantidad);
    END LOOP;
    CLOSE cur;

    IF v_total_items = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El carrito está vacío.';
    END IF;

    SET v_impuesto = v_subtotal * 0.13;
    SET p_total = v_subtotal + v_impuesto;

    UPDATE ventas
       SET subtotal = v_subtotal, impuesto = v_impuesto, total = p_total
     WHERE id_venta = p_id_venta;

    INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen)
    VALUES (
        p_id_usuario,
        'INSERT',
        CONCAT('Se registró la venta #', p_id_venta, ' por un total de ₡', FORMAT(p_total, 2), ' (método de pago: ', p_metodo_pago, ', sp_registrar_venta_productos).'),
        NULL
    );

    COMMIT;
END$$

--
-- Agenda una cita: valida que no choque con otra cita del mismo doctor y
-- que caiga dentro de su horario laboral estructurado (si el doctor tiene
-- uno configurado en doctor_horarios; si no tiene ninguno, no se restringe),
-- inserta la cita y, opcionalmente, descuenta el insumo usado (recepción).
-- Usado por citas.php (paciente, sin insumo) y control_citas.php (recepción,
-- con insumo opcional). El INSERT a `citas` ya dispara trg_cita_insert, que
-- deja constancia en bitácora — este procedimiento no la duplica.
--
CREATE PROCEDURE `sp_registrar_cita` (
    IN `p_id_paciente` INT,
    IN `p_id_doctor` INT,
    IN `p_id_usuario_registro` INT,
    IN `p_fecha_hora` DATETIME,
    IN `p_duracion_minutos` INT,
    IN `p_motivo` VARCHAR(255),
    IN `p_id_producto` INT,
    IN `p_cantidad_producto` INT,
    OUT `p_id_cita` INT
)
BEGIN
    DECLARE v_traslapes INT DEFAULT 0;
    DECLARE v_horario_configurado INT DEFAULT 0;
    DECLARE v_horario_valido INT DEFAULT 0;
    DECLARE v_dia_semana TINYINT;
    DECLARE v_mensaje_error TEXT;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_mensaje_error = MESSAGE_TEXT;
        ROLLBACK;
        INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen)
        VALUES (
            p_id_usuario_registro,
            'ERROR',
            CONCAT('Falló sp_registrar_cita (paciente #', IFNULL(p_id_paciente,'NULL'), ', doctor #', IFNULL(p_id_doctor,'NULL'), ', ', IFNULL(p_fecha_hora,'NULL'), '): ', v_mensaje_error),
            NULL
        );
        RESIGNAL;
    END;

    IF p_duracion_minutos IS NULL OR p_duracion_minutos <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La duración de la cita no es válida.';
    END IF;

    START TRANSACTION;

    -- 1. Traslape: el doctor no puede tener otra cita en el mismo rango.
    SELECT COUNT(*) INTO v_traslapes
      FROM citas
     WHERE id_doctor = p_id_doctor
       AND estado NOT IN ('cancelada', 'vencida')
       AND fecha_hora < DATE_ADD(p_fecha_hora, INTERVAL p_duracion_minutos MINUTE)
       AND DATE_ADD(fecha_hora, INTERVAL duracion_minutos MINUTE) > p_fecha_hora;

    IF v_traslapes > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El doctor no está disponible en ese horario.';
    END IF;

    -- 2. Horario laboral: si el doctor tiene horario estructurado configurado,
    -- la cita debe caer completa dentro de alguno de sus bloques ese día.
    SELECT COUNT(*) INTO v_horario_configurado FROM doctor_horarios WHERE id_doctor = p_id_doctor;

    IF v_horario_configurado > 0 THEN
        SET v_dia_semana = DAYOFWEEK(p_fecha_hora) - 1; -- DAYOFWEEK: 1=domingo..7=sábado -> 0=domingo..6=sábado

        SELECT COUNT(*) INTO v_horario_valido
          FROM doctor_horarios
         WHERE id_doctor = p_id_doctor
           AND dia_semana = v_dia_semana
           AND TIME(p_fecha_hora) >= hora_inicio
           AND ADDTIME(TIME(p_fecha_hora), SEC_TO_TIME(p_duracion_minutos * 60)) <= hora_fin;

        IF v_horario_valido = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El doctor no atiende en ese horario.';
        END IF;
    END IF;

    -- 3. Insertar la cita (el trigger trg_cita_insert ya deja constancia en bitácora).
    INSERT INTO citas (id_paciente, id_doctor, id_usuario_registro, fecha_hora, duracion_minutos, motivo, estado)
    VALUES (p_id_paciente, p_id_doctor, p_id_usuario_registro, p_fecha_hora, p_duracion_minutos, p_motivo, 'pendiente');
    SET p_id_cita = LAST_INSERT_ID();

    -- 4. Insumo opcional (solo agendamiento por recepción): descuento atómico de stock.
    IF p_id_producto IS NOT NULL AND p_id_producto > 0 AND p_cantidad_producto IS NOT NULL AND p_cantidad_producto > 0 THEN
        UPDATE productos
           SET stock = stock - p_cantidad_producto
         WHERE id_producto = p_id_producto
           AND stock >= p_cantidad_producto;

        IF ROW_COUNT() = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No hay stock suficiente del insumo seleccionado.';
        END IF;
    END IF;

    COMMIT;
END$$

--
-- Registra la venta de uno o varios tratamientos/servicios facturados a un
-- paciente: inserta la venta y su detalle, descuenta de forma agregada los
-- insumos que consuman (servicio_productos — sin bajar de 0, el tratamiento
-- ya se realizó) y, si es pago parcial, crea la deuda en pagos_pendientes y
-- el primer abono. El cálculo del descuento de promoción (paquete completo,
-- porcentual o fijo — con sus reglas de elegibilidad) se sigue haciendo en
-- PHP (calcular_descuento_promocion en facturacion_servicios_helper.php) y
-- se pasa ya resuelto; este procedimiento solo persiste el resultado de
-- forma atómica. Usado por facturar_servicios.php y pago_exito_servicios.php.
--
CREATE PROCEDURE `sp_registrar_venta_servicios` (
    IN `p_id_paciente` INT,
    IN `p_id_usuario` INT,
    IN `p_id_promocion` INT,
    IN `p_nombre_promocion` VARCHAR(150),
    IN `p_descuento` DECIMAL(10,2),
    IN `p_metodo_pago` VARCHAR(20),
    IN `p_tipo_pago` VARCHAR(20),
    IN `p_monto_abonado_inicial` DECIMAL(10,2),
    IN `p_fecha_limite_pago` DATE,
    IN `p_servicios` JSON,
    OUT `p_id_venta` INT,
    OUT `p_subtotal` DECIMAL(10,2),
    OUT `p_impuesto` DECIMAL(10,2),
    OUT `p_total` DECIMAL(10,2),
    OUT `p_es_pago_parcial` TINYINT,
    OUT `p_saldo_pendiente` DECIMAL(10,2)
)
BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_id_servicio INT;
    DECLARE v_precio DECIMAL(10,2);
    DECLARE v_total_servicios INT DEFAULT 0;
    DECLARE v_estado_venta VARCHAR(20);
    DECLARE v_estado_deuda VARCHAR(20);
    DECLARE v_id_pago_pendiente INT;
    DECLARE v_detalle_bitacora TEXT DEFAULT '';
    DECLARE v_mensaje_error TEXT;

    DECLARE cur CURSOR FOR
        SELECT jt.id_servicio, jt.precio
          FROM JSON_TABLE(
                 p_servicios, '$[*]'
                 COLUMNS(
                     id_servicio INT PATH '$.id_servicio',
                     precio      DECIMAL(10,2) PATH '$.precio'
                 )
               ) AS jt;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_mensaje_error = MESSAGE_TEXT;
        ROLLBACK;
        INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen)
        VALUES (
            p_id_usuario,
            'ERROR',
            CONCAT('Falló sp_registrar_venta_servicios (paciente #', IFNULL(p_id_paciente,'NULL'), ', método ', IFNULL(p_metodo_pago,'NULL'), '): ', v_mensaje_error),
            NULL
        );
        RESIGNAL;
    END;

    IF p_servicios IS NULL OR JSON_LENGTH(p_servicios) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Debes seleccionar al menos un tratamiento a facturar.';
    END IF;

    START TRANSACTION;

    -- Subtotal: suma de los precios ya resueltos en PHP (mismos precios que
    -- ve el usuario en el formulario, tomados de servicios.precio).
    SELECT SUM(jt.precio) INTO p_subtotal
      FROM JSON_TABLE(p_servicios, '$[*]' COLUMNS(precio DECIMAL(10,2) PATH '$.precio')) AS jt;

    IF p_subtotal IS NULL OR p_subtotal <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No se pudo calcular el subtotal de los tratamientos seleccionados.';
    END IF;

    SET p_impuesto = (p_subtotal - p_descuento) * 0.13;
    SET p_total = (p_subtotal - p_descuento) + p_impuesto;

    -- Pago parcial: solo si se pidió Y el abono inicial no cubre el total.
    SET p_es_pago_parcial = IF(p_tipo_pago = 'parcial' AND p_monto_abonado_inicial < p_total, 1, 0);
    SET v_estado_venta = IF(p_es_pago_parcial = 1, 'pendiente', 'pagada');

    INSERT INTO ventas (id_paciente, id_usuario, subtotal, descuento, id_promocion, impuesto, total, metodo_pago, estado)
    VALUES (p_id_paciente, p_id_usuario, p_subtotal, p_descuento, p_id_promocion, p_impuesto, p_total, p_metodo_pago, v_estado_venta);
    SET p_id_venta = LAST_INSERT_ID();

    -- Un renglón de detalle_venta por cada tratamiento facturado.
    OPEN cur;
    servicios_loop: LOOP
        FETCH cur INTO v_id_servicio, v_precio;
        IF v_done THEN
            LEAVE servicios_loop;
        END IF;

        SET v_total_servicios = v_total_servicios + 1;

        INSERT INTO detalle_venta (id_venta, tipo, id_referencia, cantidad, precio_unitario, subtotal)
        VALUES (p_id_venta, 'servicio', v_id_servicio, 1, v_precio, v_precio);
    END LOOP;
    CLOSE cur;

    -- Insumos consumidos por los tratamientos facturados (servicio_productos):
    -- se agrega el consumo total por producto ANTES de descontar, para que un
    -- mismo insumo usado por varios tratamientos de esta venta se descuente
    -- una sola vez por su cantidad acumulada (no una vez por cada match del
    -- JOIN). El stock nunca baja de 0 -el tratamiento ya se realizó-.
    UPDATE productos p
    JOIN (
        SELECT sp.id_producto, SUM(sp.cantidad_requerida) AS total_cantidad
          FROM servicio_productos sp
          JOIN JSON_TABLE(p_servicios, '$[*]' COLUMNS(id_servicio INT PATH '$.id_servicio')) AS jt
            ON jt.id_servicio = sp.id_servicio
         GROUP BY sp.id_producto
    ) AS consumo ON consumo.id_producto = p.id_producto
    SET p.stock = GREATEST(0, p.stock - consumo.total_cantidad);

    -- Pago parcial: registra la deuda y, si hubo abono inicial, el primer abono.
    SET p_saldo_pendiente = NULL;
    IF p_es_pago_parcial = 1 THEN
        SET p_saldo_pendiente = p_total - p_monto_abonado_inicial;
        SET v_estado_deuda = IF(p_monto_abonado_inicial > 0, 'abonado', 'pendiente');

        INSERT INTO pagos_pendientes (id_venta, monto_total, monto_abonado, saldo_pendiente, fecha_limite_pago, estado)
        VALUES (p_id_venta, p_total, p_monto_abonado_inicial, p_saldo_pendiente, p_fecha_limite_pago, v_estado_deuda);
        SET v_id_pago_pendiente = LAST_INSERT_ID();

        IF p_monto_abonado_inicial > 0 THEN
            INSERT INTO abonos (id_pago_pendiente, monto, metodo_pago, id_usuario_registro)
            VALUES (v_id_pago_pendiente, p_monto_abonado_inicial, p_metodo_pago, p_id_usuario);
        END IF;

        SET v_detalle_bitacora = CONCAT(' Pago parcial: abonó ₡', FORMAT(p_monto_abonado_inicial, 2), ', saldo pendiente ₡', FORMAT(p_saldo_pendiente, 2), ' con fecha límite ', IFNULL(p_fecha_limite_pago, 'N/A'), '.');
    END IF;

    IF p_id_promocion IS NOT NULL THEN
        SET v_detalle_bitacora = CONCAT(' Promoción aplicada: ', IFNULL(p_nombre_promocion, ''), ' (-₡', FORMAT(p_descuento, 2), ').', v_detalle_bitacora);
    END IF;

    INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen)
    VALUES (
        p_id_usuario,
        'INSERT',
        CONCAT('Se registró la venta de servicios #', p_id_venta, ' por un total de ₡', FORMAT(p_total, 2), ' (método de pago: ', p_metodo_pago, ').', v_detalle_bitacora),
        NULL
    );

    COMMIT;
END$$

--
-- Crea o edita un usuario (p_id_usuario = 0 para crear; > 0 para editar) y,
-- según el rol, mantiene sincronizados sus registros dependientes: siempre
-- asegura una fila en `pacientes` (salvo la cuenta de recuperación del
-- sistema), y si el rol es doctor, hace upsert de `doctores` y reemplaza
-- por completo su horario laboral estructurado en `doctor_horarios`. Usado
-- por usuarios.php. p_id_usuario es INOUT: se pasa como variable de sesión
-- de MySQL (@id) desde PHP, no como parámetro ligado normal, y el
-- procedimiento le asigna el id del usuario recién creado si aplica.
--
CREATE PROCEDURE `sp_guardar_usuario` (
    INOUT `p_id_usuario` INT,
    IN `p_id_rol` INT,
    IN `p_nombre` VARCHAR(100),
    IN `p_apellido` VARCHAR(100),
    IN `p_cedula` VARCHAR(20),
    IN `p_fecha_nacimiento` DATE,
    IN `p_direccion` VARCHAR(255),
    IN `p_correo` VARCHAR(150),
    IN `p_contrasena_hash` VARCHAR(255),
    IN `p_telefono` VARCHAR(20),
    IN `p_antecedentes_medicos` TEXT,
    IN `p_alergias` TEXT,
    IN `p_especialidad` VARCHAR(100),
    IN `p_num_colegiado` VARCHAR(50),
    IN `p_horario_atencion` VARCHAR(255),
    IN `p_horario_estructurado` JSON,
    OUT `p_id_rol_anterior` INT
)
BEGIN
    DECLARE v_es_creacion TINYINT DEFAULT 0;
    DECLARE v_id_doctor INT;
    DECLARE v_nombre_rol_anterior VARCHAR(50);
    DECLARE v_nombre_rol_nuevo VARCHAR(50);
    DECLARE v_detalle_rol VARCHAR(255) DEFAULT '';
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_dia_semana TINYINT;
    DECLARE v_hora_inicio TIME;
    DECLARE v_hora_fin TIME;
    DECLARE v_mensaje_error TEXT;

    DECLARE cur_horario CURSOR FOR
        SELECT jt.dia_semana, jt.hora_inicio, jt.hora_fin
          FROM JSON_TABLE(
                 p_horario_estructurado, '$[*]'
                 COLUMNS(
                     dia_semana  TINYINT PATH '$.dia_semana',
                     hora_inicio TIME PATH '$.hora_inicio',
                     hora_fin    TIME PATH '$.hora_fin'
                 )
               ) AS jt;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        GET DIAGNOSTICS CONDITION 1 v_mensaje_error = MESSAGE_TEXT;
        ROLLBACK;
        INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen)
        VALUES (
            NULL,
            'ERROR',
            CONCAT('Falló sp_guardar_usuario (', IF(p_id_usuario > 0, CONCAT('editar #', p_id_usuario), 'crear nuevo'), ': ', IFNULL(p_nombre,''), ' ', IFNULL(p_apellido,''), ', ', IFNULL(p_correo,''), '): ', v_mensaje_error),
            NULL
        );
        RESIGNAL;
    END;

    SET v_es_creacion = (p_id_usuario = 0 OR p_id_usuario IS NULL);

    START TRANSACTION;

    IF v_es_creacion THEN
        INSERT INTO usuarios (id_rol, nombre, apellido, cedula, fecha_nacimiento, direccion, correo, contrasena, telefono, estado)
        VALUES (p_id_rol, p_nombre, p_apellido, p_cedula, p_fecha_nacimiento, p_direccion, p_correo, p_contrasena_hash, p_telefono, 1);
        SET p_id_usuario = LAST_INSERT_ID();
        SET p_id_rol_anterior = NULL;
    ELSE
        SELECT id_rol INTO p_id_rol_anterior FROM usuarios WHERE id_usuario = p_id_usuario;

        IF p_id_rol_anterior IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El usuario que intentas editar no existe.';
        END IF;

        UPDATE usuarios
           SET id_rol = p_id_rol, nombre = p_nombre, apellido = p_apellido, cedula = p_cedula,
               fecha_nacimiento = p_fecha_nacimiento, direccion = p_direccion, correo = p_correo, telefono = p_telefono
         WHERE id_usuario = p_id_usuario;
    END IF;

    -- Todo usuario (salvo la cuenta de recuperación del sistema) debe tener
    -- un registro de paciente, para poder acceder a Inicio/Citas/Historial/
    -- Perfil sin importar su rol actual.
    IF p_cedula IS NULL OR p_cedula <> '.\\recovery' THEN
        IF p_id_rol = 1 THEN
            INSERT INTO pacientes (id_usuario, antecedentes_medicos, alergias)
            VALUES (p_id_usuario, p_antecedentes_medicos, p_alergias)
            ON DUPLICATE KEY UPDATE antecedentes_medicos = VALUES(antecedentes_medicos), alergias = VALUES(alergias);
        ELSE
            INSERT INTO pacientes (id_usuario) VALUES (p_id_usuario)
            ON DUPLICATE KEY UPDATE id_paciente = id_paciente;
        END IF;
    END IF;

    IF p_id_rol = 2 THEN
        INSERT INTO doctores (id_usuario, especialidad, num_colegiado, horario_atencion)
        VALUES (p_id_usuario, p_especialidad, p_num_colegiado, p_horario_atencion)
        ON DUPLICATE KEY UPDATE especialidad = VALUES(especialidad), num_colegiado = VALUES(num_colegiado), horario_atencion = VALUES(horario_atencion);

        SELECT id_doctor INTO v_id_doctor FROM doctores WHERE id_usuario = p_id_usuario;

        DELETE FROM doctor_horarios WHERE id_doctor = v_id_doctor;

        OPEN cur_horario;
        horario_loop: LOOP
            FETCH cur_horario INTO v_dia_semana, v_hora_inicio, v_hora_fin;
            IF v_done THEN
                LEAVE horario_loop;
            END IF;
            INSERT INTO doctor_horarios (id_doctor, dia_semana, hora_inicio, hora_fin)
            VALUES (v_id_doctor, v_dia_semana, v_hora_inicio, v_hora_fin);
        END LOOP;
        CLOSE cur_horario;
    END IF;

    IF NOT v_es_creacion THEN
        IF p_id_rol_anterior <> p_id_rol THEN
            SELECT nombre INTO v_nombre_rol_anterior FROM roles WHERE id_rol = p_id_rol_anterior;
            SELECT nombre INTO v_nombre_rol_nuevo FROM roles WHERE id_rol = p_id_rol;
            SET v_detalle_rol = CONCAT(" Cambio de rol: '", IFNULL(v_nombre_rol_anterior,''), "' → '", IFNULL(v_nombre_rol_nuevo,''), "'.");
        END IF;

        INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen)
        VALUES (NULL, 'UPDATE', CONCAT('Se actualizó el usuario #', p_id_usuario, ' (', p_nombre, ' ', p_apellido, ').', v_detalle_rol), NULL);
    ELSE
        INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen)
        VALUES (NULL, 'INSERT', CONCAT('Se registró el usuario #', p_id_usuario, ' (', p_nombre, ' ', p_apellido, ').'), NULL);
    END IF;

    COMMIT;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `abonos`
--
ALTER TABLE `abonos`
  ADD CONSTRAINT `fk_abono_pago_pendiente` FOREIGN KEY (`id_pago_pendiente`) REFERENCES `pagos_pendientes` (`id_pago_pendiente`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_abono_usuario` FOREIGN KEY (`id_usuario_registro`) REFERENCES `usuarios` (`id_usuario`);

--
-- Filtros para la tabla `citas`
--
ALTER TABLE `citas`
  ADD CONSTRAINT `fk_citas_doctor` FOREIGN KEY (`id_doctor`) REFERENCES `doctores` (`id_doctor`),
  ADD CONSTRAINT `fk_citas_paciente` FOREIGN KEY (`id_paciente`) REFERENCES `pacientes` (`id_paciente`),
  ADD CONSTRAINT `fk_citas_usuario` FOREIGN KEY (`id_usuario_registro`) REFERENCES `usuarios` (`id_usuario`);

--
-- Filtros para la tabla `detalle_venta`
--
ALTER TABLE `detalle_venta`
  ADD CONSTRAINT `fk_detalle_venta` FOREIGN KEY (`id_venta`) REFERENCES `ventas` (`id_venta`);

--
-- Filtros para la tabla `doctor_horarios`
--
ALTER TABLE `doctor_horarios`
  ADD CONSTRAINT `fk_doctor_horarios_doctor` FOREIGN KEY (`id_doctor`) REFERENCES `doctores` (`id_doctor`) ON DELETE CASCADE;

--
-- Filtros para la tabla `doctores`
--
ALTER TABLE `doctores`
  ADD CONSTRAINT `fk_doctores_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`);

--
-- Filtros para la tabla `doctor_servicios`
--
ALTER TABLE `doctor_servicios`
  ADD CONSTRAINT `fk_docserv_doctor` FOREIGN KEY (`id_doctor`) REFERENCES `doctores` (`id_doctor`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_docserv_servicio` FOREIGN KEY (`id_servicio`) REFERENCES `servicios` (`id_servicio`) ON DELETE CASCADE;

--
-- Filtros para la tabla `examenes`
--
ALTER TABLE `examenes`
  ADD CONSTRAINT `fk_examenes_cita` FOREIGN KEY (`id_cita`) REFERENCES `citas` (`id_cita`),
  ADD CONSTRAINT `fk_examenes_doctor` FOREIGN KEY (`id_doctor`) REFERENCES `doctores` (`id_doctor`),
  ADD CONSTRAINT `fk_examenes_paciente` FOREIGN KEY (`id_paciente`) REFERENCES `pacientes` (`id_paciente`);

--
-- Filtros para la tabla `historial_medico`
--
ALTER TABLE `historial_medico`
  ADD CONSTRAINT `fk_historial_cita` FOREIGN KEY (`id_cita`) REFERENCES `citas` (`id_cita`),
  ADD CONSTRAINT `fk_historial_doctor` FOREIGN KEY (`id_doctor`) REFERENCES `doctores` (`id_doctor`),
  ADD CONSTRAINT `fk_historial_paciente` FOREIGN KEY (`id_paciente`) REFERENCES `pacientes` (`id_paciente`);

--
-- Filtros para la tabla `mfa_codigos_respaldo`
--
ALTER TABLE `mfa_codigos_respaldo`
  ADD CONSTRAINT `fk_mfa_codigos_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `mfa_totp`
--
ALTER TABLE `mfa_totp`
  ADD CONSTRAINT `fk_mfa_totp_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `pacientes`
--
ALTER TABLE `pacientes`
  ADD CONSTRAINT `fk_pacientes_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`);

--
-- Filtros para la tabla `pagos_pendientes`
--
ALTER TABLE `pagos_pendientes`
  ADD CONSTRAINT `fk_pagopendiente_venta` FOREIGN KEY (`id_venta`) REFERENCES `ventas` (`id_venta`);

--
-- Filtros para la tabla `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_password_resets_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE;

--
-- Filtros para la tabla `productos`
--
ALTER TABLE `productos`
  ADD CONSTRAINT `fk_productos_categoria` FOREIGN KEY (`id_categoria`) REFERENCES `categorias` (`id_categoria`);

--
-- Filtros para la tabla `promocion_servicios`
--
ALTER TABLE `promocion_servicios`
  ADD CONSTRAINT `fk_promoserv_promocion` FOREIGN KEY (`id_promocion`) REFERENCES `promociones` (`id_promocion`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_promoserv_servicio` FOREIGN KEY (`id_servicio`) REFERENCES `servicios` (`id_servicio`);

--
-- Filtros para la tabla `rol_permiso`
--
ALTER TABLE `rol_permiso`
  ADD CONSTRAINT `fk_rolperm_permiso` FOREIGN KEY (`id_permiso`) REFERENCES `permisos` (`id_permiso`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rolperm_rol` FOREIGN KEY (`id_rol`) REFERENCES `roles` (`id_rol`) ON DELETE CASCADE;

--
-- Filtros para la tabla `servicios`
--
ALTER TABLE `servicios`
  ADD CONSTRAINT `fk_servicios_categoria` FOREIGN KEY (`id_categoria`) REFERENCES `categorias` (`id_categoria`);

--
-- Filtros para la tabla `servicio_productos`
--
ALTER TABLE `servicio_productos`
  ADD CONSTRAINT `fk_servprod_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`),
  ADD CONSTRAINT `fk_servprod_servicio` FOREIGN KEY (`id_servicio`) REFERENCES `servicios` (`id_servicio`) ON DELETE CASCADE;

--
-- Filtros para la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD CONSTRAINT `fk_usuarios_rol` FOREIGN KEY (`id_rol`) REFERENCES `roles` (`id_rol`);

--
-- Filtros para la tabla `ventas`
--
ALTER TABLE `ventas`
  ADD CONSTRAINT `fk_ventas_paciente` FOREIGN KEY (`id_paciente`) REFERENCES `pacientes` (`id_paciente`),
  ADD CONSTRAINT `fk_ventas_promocion` FOREIGN KEY (`id_promocion`) REFERENCES `promociones` (`id_promocion`),
  ADD CONSTRAINT `fk_ventas_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
