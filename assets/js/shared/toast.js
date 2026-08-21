/**
 * toast.js — Notificación temporal reutilizable (esquina inferior derecha,
 * estilo notificación de Windows). Requiere el markup #toastNotificacion
 * (ver assets/css/shared/toast.css) presente en la página.
 *
 * Uso: mostrarToast('Mensaje...', 'exito' | 'error');
 * Se cierra sola a los 5 segundos, o al hacer clic en la X / fuera de la tarjeta.
 */

let toastTimeoutId;

function mostrarToast(mensaje, tipo) {
    const toast = document.getElementById('toastNotificacion');
    if (!toast) return;

    const esExito = tipo === 'exito';
    toast.classList.toggle('toast-exito', esExito);

    const titulo = document.getElementById('toastTitulo');
    const cuerpo = document.getElementById('toastMensaje');
    const icono = toast.querySelector('.toast-icon');

    if (titulo) titulo.textContent = esExito ? 'Listo' : 'Ocurrió un error';
    if (cuerpo) cuerpo.textContent = mensaje;
    if (icono) icono.textContent = esExito ? '✓' : '⚠';

    toast.classList.add('show');
    clearTimeout(toastTimeoutId);
    toastTimeoutId = setTimeout(cerrarToast, 5000);
}

function cerrarToast() {
    const toast = document.getElementById('toastNotificacion');
    if (!toast) return;
    toast.classList.remove('show');
    clearTimeout(toastTimeoutId);
}

document.addEventListener('DOMContentLoaded', function () {
    const toast = document.getElementById('toastNotificacion');
    if (toast) {
        toast.addEventListener('click', function (e) {
            if (e.target === this) cerrarToast();
        });

        // Dispara el toast automáticamente si la página lo dejó preparado vía
        // data-mensaje/data-tipo, en vez de que cada página tenga que traer
        // su propio <script> inline con el mensaje del servidor.
        if (toast.dataset.mensaje) {
            mostrarToast(toast.dataset.mensaje, toast.dataset.tipo || 'error');
        }
    }
});

/**
 * activarValidacionAmigable(formulario) — Reemplaza el globo de validación
 * nativo del navegador (que solo se ve al pasar el mouse encima y no dice
 * mucho: "Utiliza un formato que coincida con el solicitado") por el mismo
 * patrón usado en los errores del servidor: notificación + campo(s) en rojo.
 *
 * La validación en sí NO se desactiva (el navegador sigue bloqueando el
 * envío si algo no cumple su required/pattern/etc., vía checkValidity()),
 * solo cambia cómo se avisa. Por eso el formulario se marca "novalidate":
 * así el navegador no dispara su propio aviso automático al enviar, pero
 * la validación (:invalid, checkValidity()) sigue funcionando igual.
 *
 * El texto del aviso sale del atributo title del campo si existe (debe
 * describir qué se espera, ej: title="Solo letras y espacios."); si no hay
 * title, cae al mensaje nativo del navegador.
 */
function activarValidacionAmigable(formulario) {
    if (!formulario) return;

    formulario.setAttribute('novalidate', '');

    formulario.addEventListener('submit', function (e) {
        if (formulario.checkValidity()) return;
        e.preventDefault();

        formulario.querySelectorAll('.field-error').forEach(function (el) {
            el.classList.remove('field-error');
        });

        const invalidos = Array.from(formulario.querySelectorAll(':invalid'));
        invalidos.forEach(function (campo) {
            const contenedor = campo.closest('.field, .form-group');
            if (contenedor) contenedor.classList.add('field-error');
        });

        const primero = invalidos[0];
        if (primero) {
            const mensaje = primero.title || primero.validationMessage || 'Revisa los datos marcados en rojo.';
            mostrarToast(mensaje, 'error');
            primero.focus();
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form').forEach(activarValidacionAmigable);
});
