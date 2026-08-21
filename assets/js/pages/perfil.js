/**
 * perfil.js — Interacción de perfil.php: recorte/zoom de la foto de perfil
 * sobre un lienzo circular, modales de MFA (activar/eliminar/renombrar
 * método, eliminar foto) y el dibujo del QR de enrolamiento TOTP.
 *
 * El texto de "iniciales" que reemplaza la foto al eliminarla viene del
 * atributo data-iniciales del contenedor .perfil-avatar (lo pone el
 * servidor), no de PHP embebido aquí, para que este archivo sea JS puro.
 */

(function () {
    const inputFoto = document.getElementById('foto_perfil');
    const checkboxQuitar = document.getElementById('quitarFotoCheckbox');
    const modal = document.getElementById('modalRecorte');
    const canvas = document.getElementById('recorteCanvas');
    const ctx = canvas.getContext('2d');
    const controlZoom = document.getElementById('recorteZoom');
    const TAM = canvas.width; // 300 — lienzo cuadrado, se muestra recortado en círculo vía CSS

    let img = null;
    let baseScale = 1;
    let escala = 1;
    let offsetX = 0;
    let offsetY = 0;
    let arrastrando = false;
    let inicioX = 0;
    let inicioY = 0;
    let inicioOffsetX = 0;
    let inicioOffsetY = 0;

    function limitarOffsets() {
        const anchoImg = img.width * escala;
        const altoImg = img.height * escala;
        const minX = Math.min(0, TAM - anchoImg);
        const minY = Math.min(0, TAM - altoImg);
        offsetX = Math.min(0, Math.max(minX, offsetX));
        offsetY = Math.min(0, Math.max(minY, offsetY));
    }

    function dibujar() {
        ctx.clearRect(0, 0, TAM, TAM);
        ctx.drawImage(img, offsetX, offsetY, img.width * escala, img.height * escala);
    }

    function abrirRecorte(archivo) {
        const lector = new FileReader();
        lector.onload = function (ev) {
            const imagen = new Image();
            imagen.onload = function () {
                img = imagen;
                baseScale = Math.max(TAM / img.width, TAM / img.height);
                escala = baseScale;
                offsetX = (TAM - img.width * escala) / 2;
                offsetY = (TAM - img.height * escala) / 2;
                controlZoom.value = 1;
                dibujar();
                modal.classList.add('active');
            };
            imagen.src = ev.target.result;
        };
        lector.readAsDataURL(archivo);
    }

    inputFoto.addEventListener('change', function (e) {
        const archivo = e.target.files[0];
        if (archivo) abrirRecorte(archivo);
    });

    canvas.addEventListener('pointerdown', function (e) {
        arrastrando = true;
        canvas.setPointerCapture(e.pointerId);
        inicioX = e.clientX;
        inicioY = e.clientY;
        inicioOffsetX = offsetX;
        inicioOffsetY = offsetY;
    });
    canvas.addEventListener('pointermove', function (e) {
        if (!arrastrando) return;
        offsetX = inicioOffsetX + (e.clientX - inicioX);
        offsetY = inicioOffsetY + (e.clientY - inicioY);
        limitarOffsets();
        dibujar();
    });
    ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (ev) {
        canvas.addEventListener(ev, function () { arrastrando = false; });
    });

    controlZoom.addEventListener('input', function () {
        // El centro del recorte se mantiene fijo mientras se hace zoom.
        const centroX = (TAM / 2 - offsetX) / escala;
        const centroY = (TAM / 2 - offsetY) / escala;
        escala = baseScale * parseFloat(controlZoom.value);
        offsetX = TAM / 2 - centroX * escala;
        offsetY = TAM / 2 - centroY * escala;
        limitarOffsets();
        dibujar();
    });

    window.cancelarRecorte = function () {
        modal.classList.remove('active');
        inputFoto.value = '';
    };

    window.aplicarRecorte = function () {
        canvas.toBlob(function (blob) {
            const archivoRecortado = new File([blob], 'foto_perfil.png', { type: 'image/png' });
            const paquete = new DataTransfer();
            paquete.items.add(archivoRecortado);
            inputFoto.files = paquete.files;

            let previewImg = document.getElementById('perfilAvatarImg');
            if (!previewImg) {
                const avatar = document.querySelector('.perfil-avatar');
                avatar.innerHTML = '';
                previewImg = document.createElement('img');
                previewImg.id = 'perfilAvatarImg';
                previewImg.alt = 'Foto de perfil';
                avatar.appendChild(previewImg);
            }
            previewImg.src = canvas.toDataURL('image/png');
            if (checkboxQuitar) checkboxQuitar.value = '';

            modal.classList.remove('active');
        }, 'image/png');
    };

    modal.addEventListener('click', function (e) {
        if (e.target === this) cancelarRecorte();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('active')) cancelarRecorte();
    });

    // Modal de confirmación para "Eliminar foto de perfil": el
    // input oculto quitar_foto solo se marca con "1" si la persona
    // confirma; la eliminación real ocurre al presionar "Guardar cambios".
    const modalEliminar = document.getElementById('modalEliminarFoto');
    const avatarContenedor = document.querySelector('.perfil-avatar');

    window.abrirModalEliminarFoto = function () {
        modalEliminar.classList.add('active');
    };

    window.cerrarModalEliminarFoto = function () {
        modalEliminar.classList.remove('active');
    };

    window.confirmarEliminarFoto = function () {
        checkboxQuitar.value = '1';
        inputFoto.value = '';
        const iniciales = avatarContenedor.dataset.iniciales || '';
        avatarContenedor.innerHTML = '<span id="perfilAvatarIniciales"></span>';
        avatarContenedor.querySelector('#perfilAvatarIniciales').textContent = iniciales;
        modalEliminar.classList.remove('active');
    };

    modalEliminar.addEventListener('click', function (e) {
        if (e.target === this) cerrarModalEliminarFoto();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modalEliminar.classList.contains('active')) cerrarModalEliminarFoto();
    });

    // Cierre por clic afuera / Escape para los modales de MFA
    // (modalEliminarMetodoMfa/modalRenombrarMfa solo existen si hay
    // al menos una app activa).
    ['modalMfa', 'modalEliminarMetodoMfa', 'modalRenombrarMfa'].forEach(function (id) {
        const modalMfaEl = document.getElementById(id);
        if (!modalMfaEl) return;
        modalMfaEl.addEventListener('click', function (e) {
            if (e.target === this) this.classList.remove('active');
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modalMfaEl.classList.contains('active')) modalMfaEl.classList.remove('active');
        });
    });

    // Abre el modal compartido de "eliminar método" ya con el id_mfa
    // y el nombre de la app correspondientes a la fila que se clickeó.
    window.abrirModalEliminarMetodoMfa = function (idMfa, nombreApp) {
        document.getElementById('idMfaEliminar').value = idMfa;
        document.getElementById('tituloEliminarMetodoMfa').textContent = '¿Eliminar ' + nombreApp + '?';
        document.getElementById('modalEliminarMetodoMfa').classList.add('active');
    };

    // Abre el modal de renombrar con el id_mfa y el nombre actual
    // ya cargados en el campo de texto.
    window.abrirModalRenombrarMfa = function (idMfa, nombreActual) {
        document.getElementById('idMfaRenombrar').value = idMfa;
        document.getElementById('nuevo_nombre_mfa').value = nombreActual;
        document.getElementById('modalRenombrarMfa').classList.add('active');
    };
})();

// Dibuja el QR de enrolamiento TOTP en el navegador (la URI otpauth:// ya
// viene en el atributo data-uri, generada del lado del servidor). El
// contenedor #mfaQr solo existe cuando hay un enrolamiento pendiente de
// confirmar — si no existe, no hay nada que dibujar.
(function () {
    const contenedor = document.getElementById('mfaQr');
    if (contenedor) {
        new QRCode(contenedor, {
            text: contenedor.dataset.uri,
            width: 220,
            height: 220,
        });
    }
})();
