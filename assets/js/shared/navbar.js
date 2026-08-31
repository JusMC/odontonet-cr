/**
 * navbar.js — Comportamiento del navbar: botón hamburguesa (menú móvil) y
 * desplegables por rol / perfil (Administrador, Doctor, Recepcionista,
 * ajustes de cuenta). En escritorio los desplegables son popups; en móvil,
 * dentro del panel del burger, se comportan como acordeón (mismo marcado,
 * solo cambia el CSS — ver assets/css/shared/layout.css).
 */
(function () {
    var burger = document.querySelector('.burger');
    var navMenu = document.getElementById('nav-menu');
    var dropdowns = document.querySelectorAll('.nav-dropdown');

    // Cierra todos los desplegables, menos el que se le pase como "excepto" (si aplica).
    function cerrarDropdowns(excepto) {
        dropdowns.forEach(function (dropdown) {
            if (dropdown !== excepto) {
                dropdown.classList.remove('open');
                var toggle = dropdown.querySelector('.nav-dropdown-toggle');
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // Cierra el panel móvil (y cualquier acordeón abierto dentro).
    function cerrarMenuMovil() {
        if (!burger || !navMenu) return;
        navMenu.classList.remove('open');
        burger.classList.remove('open');
        burger.setAttribute('aria-expanded', 'false');
        cerrarDropdowns(null);
    }

    // Botón hamburguesa: abre/cierra el panel de navegación móvil.
    if (burger && navMenu) {
        burger.addEventListener('click', function (e) {
            e.stopPropagation();
            var abierto = navMenu.classList.contains('open');
            navMenu.classList.toggle('open', !abierto);
            burger.classList.toggle('open', !abierto);
            burger.setAttribute('aria-expanded', String(!abierto));
            if (abierto) cerrarDropdowns(null);
        });
    }

    // A cada desplegable le agregamos el clic en su botón para abrirlo/cerrarlo.
    dropdowns.forEach(function (dropdown) {
        var boton = dropdown.querySelector('.nav-dropdown-toggle');
        if (!boton) return;
        boton.addEventListener('click', function (e) {
            e.stopPropagation(); // Evita que el clic "suba" y dispare el cierre general de abajo.
            var abierto = dropdown.classList.contains('open');
            cerrarDropdowns(dropdown); // Cierra los demás antes de abrir este.
            dropdown.classList.toggle('open', !abierto);
            boton.setAttribute('aria-expanded', String(!abierto));
        });
    });

    // Clic en un link del panel móvil: navega, así que cerramos el panel.
    if (navMenu) {
        navMenu.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', cerrarMenuMovil);
        });
    }

    // Clic fuera del navbar: cierra desplegables y, si estaba abierto, el panel móvil.
    document.addEventListener('click', function (e) {
        cerrarDropdowns(null);
        if (navMenu && burger && !navMenu.contains(e.target) && !burger.contains(e.target)) {
            cerrarMenuMovil();
        }
    });

    // Escape: cierra todo.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            cerrarMenuMovil();
        }
    });
})();
