/**
 * citas.js — Interacción de citas.php ("Mis citas"): modal de agendar
 * cita (con límite dinámico de hora mínima si la fecha elegida es hoy),
 * atajos para aplicar un horario sugerido, y modal de confirmación de
 * cancelación.
 *
 * El modal de agendar se abre solo si el servidor lo pide (falló la
 * validación del formulario): esa señal llega vía el atributo
 * data-abrir-automatico del propio modal, no con PHP embebido aquí.
 */

// MODAL: AGENDAR CITA
const modalCita = document.getElementById('modalCita');
const fechaInput = document.getElementById('fecha');
const horaInput = document.getElementById('hora');

function abrirModalCita() {
    modalCita.classList.add('active');
}

function cerrarModalCita() {
    modalCita.classList.remove('active');
}

modalCita.addEventListener('click', function (e) {
    if (e.target === this) cerrarModalCita();
});

if (modalCita.dataset.abrirAutomatico === '1') {
    abrirModalCita();
}

function seleccionarSugerencia(fecha, hora) {
    fechaInput.value = fecha;
    horaInput.value = hora;
    actualizarLimites();
}

function pad(value) {
    return String(value).padStart(2, '0');
}

function formatDate(date) {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function getCurrentTimeLimit(date) {
    const minutes = date.getMinutes();
    const roundedMinutes = Math.ceil(minutes / 15) * 15;
    let hours = date.getHours();
    let calcMinutes = roundedMinutes;

    if (calcMinutes === 60) {
        hours += 1;
        calcMinutes = 0;
    }

    if (hours === 24) {
        hours = 0;
    }

    return `${pad(hours)}:${pad(calcMinutes)}`;
}

function actualizarLimites() {
    const ahora = new Date();
    const fechaHoy = formatDate(ahora);

    const fechaSeleccionada = fechaInput.value;
    if (fechaSeleccionada && fechaSeleccionada === fechaHoy) {
        const minHora = getCurrentTimeLimit(ahora);
        horaInput.min = minHora;

        if (horaInput.value && horaInput.value < minHora) {
            horaInput.value = minHora;
        }
    } else {
        horaInput.min = '00:00';
    }
}

fechaInput.addEventListener('change', actualizarLimites);
actualizarLimites();
setInterval(actualizarLimites, 30000);

// MODAL DE CONFIRMACIÓN DE CANCELACIÓN
function confirmarCancelacion(url) {
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
        cerrarModalCita();
        cerrarModal();
    }
});
