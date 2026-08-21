-- ==========================================
-- VIEWS Y TRIGGERS DEL SISTEMA ODONTONET
-- Proyecto Programación IV
-- Autor: Diego Ramírez
-- ==========================================

USE `schema`;
-- ==========================================
-- VIEWS DEL SISTEMA ODONTONET
-- ==========================================

-- Este view muestra los pacientes con sus datos unidos desde usuarios + pacientes
DROP VIEW IF EXISTS v_pacientes_admin;

CREATE VIEW `v_pacientes_admin` AS
SELECT
    p.id_paciente,
    u.id_usuario,
    u.cedula,
    u.nombre,
    u.apellido,
    CONCAT(u.nombre, ' ', u.apellido) AS nombre_completo,
    u.correo,
    u.telefono,
    u.fecha_nacimiento,
    TIMESTAMPDIFF(YEAR, u.fecha_nacimiento, CURDATE()) AS edad,
    u.direccion,
    u.estado,
    u.fecha_creacion
FROM pacientes p
INNER JOIN usuarios u ON p.id_usuario = u.id_usuario;


-- Este view sirve para las tarjetas del panel administrador
DROP VIEW IF EXISTS v_resumen_admin;

CREATE VIEW v_resumen_admin AS
SELECT
    (SELECT COUNT(*) FROM pacientes) AS pacientes_registrados,
    (SELECT COUNT(*) FROM citas WHERE estado IN ('pendiente', 'en_espera', 'en_atencion')) AS citas_programadas,
    (SELECT COUNT(*) FROM productos WHERE activo = 1) AS productos_en_inventario,
    (SELECT COUNT(*) FROM ventas WHERE estado = 'pagada') AS ventas_realizadas;

    -- ==========================================
-- TRIGGERS DEL SISTEMA ODONTONET
-- ==========================================

DELIMITER $$

DROP TRIGGER IF EXISTS trg_paciente_insert $$
CREATE TRIGGER trg_paciente_insert
AFTER INSERT ON pacientes
FOR EACH ROW
BEGIN
    INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen)
    VALUES (
        NEW.id_usuario,
        'INSERT',
        CONCAT('Se registró un nuevo paciente con id_usuario ', NEW.id_usuario),
        NULL
    );
END $$

DROP TRIGGER IF EXISTS trg_cita_insert $$
CREATE TRIGGER trg_cita_insert
AFTER INSERT ON citas
FOR EACH ROW
BEGIN
    INSERT INTO bitacora (id_usuario, accion, descripcion, ip_origen)
    VALUES (
        NEW.id_usuario_registro,
        'INSERT',
        CONCAT('Se creó la cita número ', NEW.id_cita, ' para el paciente ', NEW.id_paciente),
        NULL
    );
END $$

DROP TRIGGER IF EXISTS trg_cita_update $$
CREATE TRIGGER trg_cita_update
AFTER UPDATE ON citas
FOR EACH ROW
BEGIN
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
END $$

DELIMITER ;