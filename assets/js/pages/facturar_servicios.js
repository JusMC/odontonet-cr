/**
 * facturar_servicios.js — Interacción del formulario de facturación de
 * servicios (facturar_servicios.php): cálculo en vivo del resumen
 * (subtotal, descuento de promoción, IVA, total), el asistente de 4 pasos
 * y el bloque de pago parcial.
 */

const checkboxes = Array.from(document.querySelectorAll('.chk-servicio'));
const selectPromo = document.getElementById('id_promocion');
const avisoPaquete = document.getElementById('aviso_paquete');

function formatearColones(valor) {
    return '₡' + valor.toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function calcularYRenderizar() {
    const seleccionados = checkboxes.filter(c => c.checked);
    const subtotal = seleccionados.reduce((acc, c) => acc + parseFloat(c.dataset.precio), 0);

    let descuento = 0;
    avisoPaquete.style.display = 'none';

    const opcion = selectPromo.options[selectPromo.selectedIndex];
    if (opcion && opcion.value !== '') {
        const tipo = opcion.dataset.tipo;
        const elegibles = opcion.dataset.servicios ? opcion.dataset.servicios.split(',').map(Number) : [];
        const idsSeleccionados = seleccionados.map(c => parseInt(c.value, 10));

        if (tipo === 'paquete') {
            const faltan = elegibles.filter(id => !idsSeleccionados.includes(id));
            if (faltan.length === 0 && elegibles.length > 0) {
                const subtotalElegible = seleccionados
                    .filter(c => elegibles.includes(parseInt(c.value, 10)))
                    .reduce((acc, c) => acc + parseFloat(c.dataset.precio), 0);
                descuento = Math.max(0, subtotalElegible - parseFloat(opcion.dataset.precioPaquete));
            } else {
                avisoPaquete.textContent = 'Marca todos los servicios del paquete para que se aplique el descuento.';
                avisoPaquete.style.display = 'block';
            }
        } else {
            const subtotalElegible = seleccionados
                .filter(c => elegibles.includes(parseInt(c.value, 10)))
                .reduce((acc, c) => acc + parseFloat(c.dataset.precio), 0);

            if (tipo === 'descuento_porcentual') {
                descuento = subtotalElegible * (parseFloat(opcion.dataset.valor) / 100);
            } else if (tipo === 'descuento_fijo') {
                descuento = Math.min(parseFloat(opcion.dataset.valor), subtotalElegible);
            }
        }
    }

    const iva = (subtotal - descuento) * 0.13;
    const total = (subtotal - descuento) + iva;

    document.getElementById('resumen_subtotal').textContent = formatearColones(subtotal);
    document.getElementById('resumen_descuento').textContent = '-' + formatearColones(descuento);
    document.getElementById('resumen_iva').textContent = formatearColones(iva);
    document.getElementById('resumen_total').textContent = formatearColones(total);
}

checkboxes.forEach(c => c.addEventListener('change', calcularYRenderizar));
selectPromo.addEventListener('change', function() {
    const opcion = selectPromo.options[selectPromo.selectedIndex];
    if (opcion && opcion.value !== '' && opcion.dataset.tipo === 'paquete') {
        const elegibles = opcion.dataset.servicios ? opcion.dataset.servicios.split(',') : [];
        checkboxes.forEach(c => {
            if (elegibles.includes(c.value)) {
                c.checked = true;
            }
        });
    }
    calcularYRenderizar();
});

calcularYRenderizar();

// Mostrar/ocultar los campos de pago parcial según la opción elegida.
const radiosPago = document.querySelectorAll('input[name="tipo_pago"]');
const bloqueParcial = document.getElementById('bloque_pago_parcial');
const inputFechaLimite = document.getElementById('fecha_limite_pago');

function actualizarBloquePago() {
    const esParcial = document.getElementById('tipo_pago_parcial').checked;
    bloqueParcial.style.display = esParcial ? 'grid' : 'none';
    inputFechaLimite.required = esParcial;
}

radiosPago.forEach(r => r.addEventListener('change', actualizarBloquePago));
actualizarBloquePago();

// ASISTENTE DE 4 PASOS: solo se muestra el panel del paso actual.
const TOTAL_PASOS = 4;
let pasoActual = 1;

const pasosEl = Array.from(document.querySelectorAll('.wizard-step'));
const lineasEl = Array.from(document.querySelectorAll('.wizard-step-line'));
const panelesEl = Array.from(document.querySelectorAll('.wizard-panel'));
const btnAtras = document.getElementById('btnAtras');
const btnSiguiente = document.getElementById('btnSiguiente');
const btnGenerarFactura = document.getElementById('btnGenerarFactura');

function validarPaso(paso) {
    if (paso === 1 && !document.getElementById('id_paciente').value) {
        mostrarToast('Selecciona un paciente para continuar.', 'error');
        return false;
    }
    if (paso === 2 && !checkboxes.some(c => c.checked)) {
        mostrarToast('Marca al menos un servicio realizado para continuar.', 'error');
        return false;
    }
    return true;
}

function renderizarPaso() {
    pasosEl.forEach((el) => {
        const numero = parseInt(el.dataset.step, 10);
        el.classList.toggle('active', numero === pasoActual);
        el.classList.toggle('completed', numero < pasoActual);
        el.querySelector('.wizard-step-circle').textContent = numero < pasoActual ? '✓' : numero;
    });
    lineasEl.forEach((el, indice) => {
        el.classList.toggle('completed', indice + 1 < pasoActual);
    });
    panelesEl.forEach((el) => {
        el.classList.toggle('active', parseInt(el.dataset.panel, 10) === pasoActual);
    });

    btnAtras.classList.toggle('invisible', pasoActual === 1);
    btnSiguiente.style.display = pasoActual < TOTAL_PASOS ? 'inline-flex' : 'none';
    btnGenerarFactura.style.display = pasoActual === TOTAL_PASOS ? 'inline-flex' : 'none';
}

btnSiguiente.addEventListener('click', function () {
    if (!validarPaso(pasoActual)) return;
    if (pasoActual < TOTAL_PASOS) {
        pasoActual++;
        renderizarPaso();
    }
});

btnAtras.addEventListener('click', function () {
    if (pasoActual > 1) {
        pasoActual--;
        renderizarPaso();
    }
});

renderizarPaso();

// El texto del botón final cambia según el método de pago: con
// tarjeta se paga ahí mismo vía Stripe, así que dice "Confirmar Compra".
const selectMetodoPago = document.getElementById('metodo_pago');

function actualizarTextoBotonFinal() {
    btnGenerarFactura.textContent = selectMetodoPago.value === 'tarjeta' ? 'Confirmar Compra' : 'Generar Factura';
}

selectMetodoPago.addEventListener('change', actualizarTextoBotonFinal);
actualizarTextoBotonFinal();
