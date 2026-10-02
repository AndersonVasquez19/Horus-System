
document.body.style.overflow = "hidden";

window.addEventListener("load", function () {
    setTimeout(function () {
        document.getElementById("loader").style.opacity = "0";
        setTimeout(function () {
            document.getElementById("loader").style.display = "none";
            document.body.style.overflow = "";
        }, 500);
    }, 3000);
});


document.addEventListener('DOMContentLoaded', function () {
    const tarjetas = document.querySelectorAll('.convenios-estrategicos .tarjeta');
    const centro = document.getElementById('centro-detalle');
    const btnCerrar = document.getElementById('btn-cerrar');
    const detalleInfo = document.getElementById('detalle-info');
    const logoCentro = document.getElementById('logo-centro');

    const modalMovil = document.createElement('div');
    modalMovil.id = 'convenio-modal-movil';
    modalMovil.innerHTML = `
        <div class="convenio-modal-overlay"></div>
        <div class="convenio-modal-content">
            <button class="convenio-modal-cerrar"><i class="fas fa-times"></i></button>
            <div class="convenio-modal-body"></div>
        </div>
    `;
    document.body.appendChild(modalMovil);

    const modalOverlay = modalMovil.querySelector('.convenio-modal-overlay');
    const modalBody = modalMovil.querySelector('.convenio-modal-body');
    const modalCerrar = modalMovil.querySelector('.convenio-modal-cerrar');

    function cerrarModal() {
        modalMovil.classList.remove('activo');
        document.body.style.overflow = '';
    }

    modalOverlay.addEventListener('click', cerrarModal);
    modalCerrar.addEventListener('click', cerrarModal);

    tarjetas.forEach(tarjeta => {
        tarjeta.addEventListener('click', function () {
            const nombre = this.dataset.nombre;
            const descripcion = this.dataset.descripcion;
            const imagen = this.dataset.imagen;

            const isMobile = window.innerWidth <= 640;

            if (isMobile) {
                modalBody.innerHTML = `
                    <img src="${imagen}" alt="${nombre}">
                    <h3>${nombre}</h3>
                    <p>${descripcion}</p>
                `;
                modalMovil.classList.add('activo');
                document.body.style.overflow = 'hidden';
            } else {
                logoCentro.style.display = 'none';
                detalleInfo.style.display = 'block';
                detalleInfo.innerHTML = `
                    <img src="${imagen}" alt="${nombre}" style="width: 100%; max-width: 250px; height: auto; object-fit: cover; border-radius: 15px; margin: 0 auto 1.5rem; display: block;">
                    <h3 style="color: var(--accent-gold); margin-bottom: 1rem; font-size: 1.3rem; line-height: 1.4;">${nombre}</h3>
                    <p style="color: var(--white); line-height: 1.8; text-align: justify; font-size: 0.95rem; padding: 0 1rem;">${descripcion}</p>
                `;
                centro.classList.add('activo');
            }
        });
    });

    btnCerrar.addEventListener('click', function () {
        centro.classList.remove('activo');
        setTimeout(() => {
            logoCentro.style.display = 'block';
            detalleInfo.style.display = 'none';
        }, 300);
    });
});