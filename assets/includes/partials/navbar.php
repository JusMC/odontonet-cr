<?php
/**
 * navbar.php — Navbar adaptativo según rol del usuario.
 *
 * Requiere que session_boot.php ya haya sido incluido antes de incluir este archivo.
 * Lee $_SESSION['id_rol'] para mostrar los links correspondientes.
 *
 * Roles:
 *   1 = paciente      → solo los links universales
 *   2 = doctor        → links universales + desplegable "Doctor"
 *   3 = recepcionista → links universales + desplegable "Recepcionista"
 *   4 = administrador → links universales + los TRES desplegables (Admin, Doctor, Recepcionista)
 *   sin sesión        → links públicos
 *
 * Para no mostrar una fila larga de links, las funciones exclusivas de cada
 * rol viven en un menú desplegable por rol; solo Inicio/Citas/Historial/
 * Tienda/Perfil quedan siempre visibles como links directos.
 */

$_rol_nav    = (int)($_SESSION['id_rol'] ?? 0);   // Número de rol del usuario actual (0 si no hay sesión).
$_logueado   = isset($_SESSION['usuario_id']);     // true si hay una sesión iniciada.

// Etiqueta y color del rol para el botón de perfil (mismos colores que las
// insignias de rol en usuarios.php: .badge-paciente/doctor/recepcionista/administrador).
$_rol_info = [
    1 => ['label' => 'Paciente',      'color' => '#3498db'],
    2 => ['label' => 'Doctor',        'color' => '#9b59b6'],
    3 => ['label' => 'Recepcionista', 'color' => '#f1c40f'],
    4 => ['label' => 'Administrador', 'color' => '#e74c3c'],
][$_rol_nav] ?? ['label' => '', 'color' => 'var(--muted)'];

// Nombre del archivo actual (sin query string), para resaltar en el navbar
// el link de la página en la que el usuario está parado.
$_pagina_actual = strtolower(basename(parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH) ?? ''));

// Devuelve ' class="active"' cuando $archivo corresponde a la página actual.
// Los links con ancla (index.php#seccion) nunca se marcan como activos,
// porque el servidor no puede saber qué sección está visible en pantalla.
$es_activo = function (string $archivo) use ($_pagina_actual): string {
    if (str_contains($archivo, '#')) {
        return ''; // Es un link con ancla (ej: index.php#servicios): nunca se marca activo.
    }
    // Si el nombre de archivo coincide con la página en la que estamos, devolvemos el atributo class="active".
    return strtolower($archivo) === $_pagina_actual ? ' class="active"' : '';
};

// Igual que $es_activo, pero para el botón de un desplegable: se marca activo
// si la página actual coincide con CUALQUIERA de los links que contiene.
$dropdown_activo = function (array $archivos) use ($_pagina_actual): bool {
    // Revisamos uno por uno los archivos del desplegable, a ver si alguno es la página actual.
    foreach ($archivos as $archivo) {
        if (strtolower($archivo) === $_pagina_actual) {
            return true;
        }
    }
    return false; // Ninguno coincidió.
};
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<header>
    <nav>
        <a href="index.php"
           class="logo" aria-label="Inicio">
            <img src="assets/images/logos/logo-navbar.svg" alt="OdontoNet" class="logo-img">
        </a>

        <div class="nav-links">
            <?php if (!$_logueado): ?>
                <!-- Visitante público -->
                <a href="index.php#servicios">Servicios</a>
                <a href="index.php#beneficios">Beneficios</a>
                <a href="index.php#como-funciona">Cómo funciona</a>
                <a href="tienda.php"<?= $es_activo('tienda.php') ?>>Tienda</a>
                <a href="index.php#contacto">Contacto</a>
            <?php else: ?>
                <!-- Links universales para cualquier usuario logueado -->
                <a href="inicio.php"<?= $es_activo('inicio.php') ?>>Inicio</a>
                <a href="citas.php"<?= $es_activo('citas.php') ?>>Citas</a>
                <a href="historial.php"<?= $es_activo('historial.php') ?>>Historial</a>
                <a href="tienda.php"<?= $es_activo('tienda.php') ?>>Tienda</a>

                <?php if ($_rol_nav === 4): ?>
                    <!-- Desplegable exclusivo administrador -->
                    <div class="nav-dropdown">
                        <button type="button" class="nav-dropdown-toggle<?= $dropdown_activo(['admin.php', 'usuarios.php', 'ventas.php', 'permisos.php', 'bitacora.php']) ? ' active' : '' ?>" aria-haspopup="true" aria-expanded="false">
                            Administrador
                            <svg class="caret" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <div class="nav-dropdown-menu">
                            <a href="admin.php"<?= $es_activo('admin.php') ?>>Panel Administrador</a>
                            <a href="usuarios.php"<?= $es_activo('usuarios.php') ?>>Usuarios</a>
                            <a href="ventas.php"<?= $es_activo('ventas.php') ?>>Ventas Realizadas</a>
                            <a href="permisos.php"<?= $es_activo('permisos.php') ?>>Permisos</a>
                            <a href="bitacora.php"<?= $es_activo('bitacora.php') ?>>Bitácora</a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($_rol_nav === 2 || $_rol_nav === 4): ?>
                    <!-- Desplegable de funciones de doctor (también visible para admin) -->
                    <div class="nav-dropdown">
                        <button type="button" class="nav-dropdown-toggle<?= $dropdown_activo(['control_citas.php', 'examenes.php']) ? ' active' : '' ?>" aria-haspopup="true" aria-expanded="false">
                            Doctor
                            <svg class="caret" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <div class="nav-dropdown-menu">
                            <a href="control_citas.php"<?= $es_activo('control_citas.php') ?>>Control de Citas</a>
                            <a href="examenes.php"<?= $es_activo('examenes.php') ?>>Exámenes</a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($_rol_nav === 3 || $_rol_nav === 4): ?>
                    <!-- Desplegable de funciones de recepcionista (también visible para admin) -->
                    <div class="nav-dropdown">
                        <button type="button" class="nav-dropdown-toggle<?= $dropdown_activo(['inventario.php', 'tratamientos.php', 'odontologos.php', 'pacientes.php', 'promociones.php', 'facturar_servicios.php', 'pagos_pendientes.php']) ? ' active' : '' ?>" aria-haspopup="true" aria-expanded="false">
                            Recepcionista
                            <svg class="caret" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <div class="nav-dropdown-menu">
                            <a href="inventario.php"<?= $es_activo('inventario.php') ?>>Inventario</a>
                            <a href="tratamientos.php"<?= $es_activo('tratamientos.php') ?>>Tratamientos</a>
                            <a href="odontologos.php"<?= $es_activo('odontologos.php') ?>>Odontólogos</a>
                            <a href="pacientes.php"<?= $es_activo('pacientes.php') ?>>Pacientes</a>
                            <a href="promociones.php"<?= $es_activo('promociones.php') ?>>Promociones &amp; Paquetes</a>
                            <a href="facturar_servicios.php"<?= $es_activo('facturar_servicios.php') ?>>Facturar</a>
                            <a href="pagos_pendientes.php"<?= $es_activo('pagos_pendientes.php') ?>>Pagos Pendientes</a>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($_logueado): ?>
            <div style="display:flex; align-items:center; gap:16px;">
                <div class="nav-dropdown">
                    <button type="button" class="nav-dropdown-toggle nav-settings-toggle" aria-haspopup="true" aria-expanded="false">
                        <span class="nav-settings-textos">
                            <span class="nav-settings-nombre"><?= htmlspecialchars($_SESSION['nombre'] ?? '') ?></span>
                            <span class="nav-settings-rol" style="color:<?= htmlspecialchars($_rol_info['color']) ?>;"><?= htmlspecialchars($_rol_info['label']) ?></span>
                        </span>
                        <?php if (!empty($_SESSION['foto_perfil'])): ?>
                            <img src="<?= htmlspecialchars($_SESSION['foto_perfil']) ?>" alt="" class="nav-settings-avatar">
                        <?php else: ?>
                            <i class="bi bi-person-circle"></i>
                        <?php endif; ?>
                    </button>
                    <div class="nav-dropdown-menu nav-settings-menu">
                        <?php
                        // Si el usuario no tiene correo registrado (o la sesión no lo trae),
                        // se muestra la cédula como identificador alternativo.
                        $_dato_contacto = !empty($_SESSION['correo']) ? $_SESSION['correo'] : ($_SESSION['cedula'] ?? '');
                        ?>
                        <div class="nav-settings-info">
                            <strong><?= htmlspecialchars(trim(($_SESSION['nombre'] ?? '') . ' ' . ($_SESSION['apellido'] ?? ''))) ?></strong>
                            <span><?= htmlspecialchars($_dato_contacto) ?></span>
                        </div>
                        <div class="nav-settings-divider"></div>
                        <a href="perfil.php"<?= $es_activo('perfil.php') ?>>Perfil</a>
                    </div>
                </div>
                <a href="logout.php" class="nav-cta">Cerrar sesión</a>
            </div>
        <?php else: ?>
            <div style="display:flex; align-items:center; gap:22px;">
                <a href="login.php" style="font-size:0.9rem; color:var(--muted);">Iniciar sesión</a>
                <a href="login.php?redirect=citas.php" class="nav-cta">Agendar cita</a>
            </div>
        <?php endif; ?>
    </nav>
</header>

<?php if ($_logueado): ?>
<script>
// Controla que los menús desplegables del navbar (Administrador, Doctor, Recepcionista, etc.)
// se abran y cierren al hacer clic, y que solo uno quede abierto a la vez.
(function () {
    var dropdowns = document.querySelectorAll('.nav-dropdown'); // Todos los menús desplegables del navbar.

    // Cierra todos los menús, menos el que se le pase como "excepto" (si aplica).
    function cerrarTodos(excepto) {
        dropdowns.forEach(function (d) {
            if (d !== excepto) {
                d.classList.remove('open');
                d.querySelector('.nav-dropdown-toggle').setAttribute('aria-expanded', 'false');
            }
        });
    }

    // A cada menú le agregamos el clic en su botón para abrirlo/cerrarlo.
    dropdowns.forEach(function (dropdown) {
        var boton = dropdown.querySelector('.nav-dropdown-toggle');
        boton.addEventListener('click', function (e) {
            e.stopPropagation(); // Evita que el clic "suba" y dispare el cierre general de abajo.
            var abierto = dropdown.classList.contains('open');
            cerrarTodos(dropdown); // Cierra los demás antes de abrir este.
            dropdown.classList.toggle('open', !abierto);
            boton.setAttribute('aria-expanded', String(!abierto));
        });
    });

    // Si el usuario hace clic en cualquier otra parte de la página, se cierran todos los menús.
    document.addEventListener('click', function () {
        cerrarTodos(null);
    });

    // Si presiona la tecla Escape, también se cierran todos.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            cerrarTodos(null);
        }
    });
})();
</script>
<?php endif; ?>
