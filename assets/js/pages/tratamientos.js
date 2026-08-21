/**
 * tratamientos.js — Interacción del dashboard de Gestión de Tratamientos
 * (tratamientos.php): abrir/cerrar el modal de alta/edición y el modal de
 * confirmación de inactivación.
 *
 * El modal de alta/edición se abre solo si el servidor lo pide (venimos de
 * "Editar" o hubo un error de validación al guardar): esa señal llega vía
 * el atributo data-abrir-automatico del propio modal, no con PHP embebido
 * aquí, para que este archivo sea JS puro y cacheable por el navegador.
 */

const modalTratamiento = document.getElementById('modalTratamiento');
const eyebrowModalTratamiento = document.getElementById('modalTratamientoEyebrow');
const tituloModalTratamiento = document.getElementById('modalTratamientoTitulo');
const botonGuardarTratamiento = document.getElementById('btnGuardarTratamiento');

function abrirModalTratamiento(esNuevo) {
    if (esNuevo) {
        document.getElementById('id_servicio').value = '';
        document.getElementById('nombre').value = '';
        document.getElementById('id_categoria').value = '';
        document.getElementById('precio').value = '';
        document.getElementById('duracion_minutos').value = '60';
        document.getElementById('descripcion').value = '';
        document.querySelectorAll('#checklistDoctores input[type="checkbox"]').forEach(chk => chk.checked = false);
        document.querySelectorAll('#checklistProductos input[type="number"]').forEach(inp => inp.value = 0);
        eyebrowModalTratamiento.textContent = 'Nuevo Tratamiento';
        tituloModalTratamiento.textContent = 'Registrar Tratamiento';
        botonGuardarTratamiento.textContent = 'Guardar Tratamiento';
    }
    modalTratamiento.classList.add('active');
}

function cerrarModalTratamiento() {
    modalTratamiento.classList.remove('active');
}

modalTratamiento.addEventListener('click', function (e) {
    if (e.target === this) cerrarModalTratamiento();
});

if (modalTratamiento.dataset.abrirAutomatico === '1') {
    abrirModalTratamiento(false);
}

// MODAL DE CONFIRMACIÓN DE INACTIVACIÓN
function confirmarInactivacion(url, nombre) {
    var mensaje = '¿Deseas inactivar el tratamiento "' + nombre + '"? Podrás reactivarlo en cualquier momento.';
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
        cerrarModalTratamiento();
        cerrarModal();
    }
});
