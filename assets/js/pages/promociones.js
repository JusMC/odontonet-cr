/**
 * promociones.js — Interacción del dashboard de Promociones y Paquetes
 * (promociones.php): mostrar/ocultar los campos según el tipo elegido, y
 * abrir/cerrar los modales de alta/edición y de confirmación de
 * inactivación.
 *
 * La fecha de hoy que se precarga al abrir "Nueva promoción" viene del
 * atributo data-fecha-hoy del propio modal (calculada por el servidor),
 * no de PHP embebido aquí. Lo mismo para si el modal debe abrirse solo.
 */

function actualizarCamposTipo() {
    const tipo = document.getElementById('tipo').value;
    const campoPaquete = document.getElementById('campo_precio_paquete');
    const campoDescuento = document.getElementById('campo_valor_descuento');
    const inputPaquete = document.getElementById('precio_paquete');
    const inputDescuento = document.getElementById('valor_descuento');
    const labelDescuento = document.getElementById('label_valor_descuento');

    campoPaquete.classList.toggle('visible', tipo === 'paquete');
    campoDescuento.classList.toggle('visible', tipo !== 'paquete');

    inputPaquete.required = tipo === 'paquete';
    inputDescuento.required = tipo !== 'paquete';

    if (tipo === 'descuento_porcentual') {
        labelDescuento.innerHTML = '<span class="dash-req">*</span> Porcentaje de descuento <span class="dash-hint">(%)</span>';
        inputDescuento.max = 100;
    } else if (tipo === 'descuento_fijo') {
        labelDescuento.innerHTML = '<span class="dash-req">*</span> Monto fijo de descuento <span class="dash-hint">(₡)</span>';
        inputDescuento.removeAttribute('max');
    }
}
document.addEventListener('DOMContentLoaded', actualizarCamposTipo);

// MODAL DE PROMOCIÓN (registrar / editar)
const modalPromocion = document.getElementById('modalPromocion');
const eyebrowModalPromocion = document.getElementById('modalPromocionEyebrow');
const tituloModalPromocion = document.getElementById('modalPromocionTitulo');
const botonGuardarPromocion = document.getElementById('btnGuardarPromocion');

function abrirModalPromocion(esNuevo) {
    if (esNuevo) {
        document.getElementById('id_promocion').value = '';
        document.getElementById('nombre').value = '';
        document.getElementById('tipo').value = 'paquete';
        document.getElementById('precio_paquete').value = '';
        document.getElementById('valor_descuento').value = '';
        document.getElementById('fecha_inicio').value = modalPromocion.dataset.fechaHoy;
        document.getElementById('fecha_fin').value = '';
        document.getElementById('descripcion').value = '';
        document.querySelectorAll('#checklistServicios input[type="checkbox"]').forEach(chk => chk.checked = false);
        actualizarCamposTipo();
        eyebrowModalPromocion.textContent = 'Nueva Promoción';
        tituloModalPromocion.textContent = 'Crear Promoción o Paquete';
        botonGuardarPromocion.textContent = 'Guardar Promoción';
    }
    modalPromocion.classList.add('active');
}

function cerrarModalPromocion() {
    modalPromocion.classList.remove('active');
}

modalPromocion.addEventListener('click', function (e) {
    if (e.target === this) cerrarModalPromocion();
});

if (modalPromocion.dataset.abrirAutomatico === '1') {
    abrirModalPromocion(false);
}

// MODAL DE CONFIRMACIÓN DE INACTIVACIÓN
function confirmarInactivacion(url, nombre) {
    var mensaje = '¿Deseas inactivar la promoción "' + nombre + '"? Podrás reactivarla en cualquier momento.';
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
        cerrarModalPromocion();
        cerrarModal();
    }
});
