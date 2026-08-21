// usuarios.js — Lógica de UI del formulario de gestión de usuarios.

/**
 * Muestra u oculta las secciones de campos extra según el rol seleccionado.
 * Rol 1 (paciente): muestra datos clínicos del paciente.
 * Rol 2 (doctor): muestra perfil profesional.
 * Cualquier otro rol: oculta ambas secciones.
 *
 * @param {string} rolVal - Valor del select de rol (id_rol como string).
 */
function toggleCamposEspeciales(rolVal) {
    const secPaciente = document.getElementById('seccion_paciente');
    const secDoctor   = document.getElementById('seccion_doctor');

    // Especialidad, colegiado y horario son obligatorios solo cuando el
    // rol elegido es Doctor (ver validación equivalente en el servidor).
    const camposDoctor = ['especialidad', 'num_colegiado', 'horario_atencion']
        .map(function (id) { return document.getElementById(id); })
        .filter(Boolean);

    if (rolVal === '1') {
        secPaciente.style.display = 'block';
        secDoctor.style.display   = 'none';
        camposDoctor.forEach(function (campo) { campo.required = false; });
    } else if (rolVal === '2') {
        secPaciente.style.display = 'none';
        secDoctor.style.display   = 'block';
        camposDoctor.forEach(function (campo) { campo.required = true; });
    } else {
        secPaciente.style.display = 'none';
        secDoctor.style.display   = 'none';
        camposDoctor.forEach(function (campo) { campo.required = false; });
    }
}

// Al cargar la página, si ya hay un rol preseleccionado (modo edición),
// aplicar la visibilidad correcta de inmediato.
document.addEventListener('DOMContentLoaded', function () {
    const rolSelect = document.getElementById('id_rol');
    if (rolSelect && rolSelect.value) {
        toggleCamposEspeciales(rolSelect.value);
    }
});