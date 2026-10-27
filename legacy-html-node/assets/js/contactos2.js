const API_URL = 'http://localhost:3000';

document.addEventListener('DOMContentLoaded', function () {
    const contactForm = document.getElementById('contactForm');

    if (contactForm) {
        contactForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const formData = {
                nombre:   document.getElementById('nombre').value.trim(),
                email:    document.getElementById('email').value.trim(),
                telefono: document.getElementById('telefono').value.trim(),
                asunto:   document.getElementById('asunto').value.trim(),
                mensaje:  document.getElementById('mensaje').value.trim(),
            };

            if (!formData.nombre || !formData.email || !formData.telefono || !formData.asunto || !formData.mensaje) {
                mostrarMensaje('Por favor, completa todos los campos', 'error');
                return;
            }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(formData.email)) {
                mostrarMensaje('Por favor, ingresa un email válido', 'error');
                return;
            }

            const btnEnviar = contactForm.querySelector('.btn-enviar');
            btnEnviar.disabled = true;
            btnEnviar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';

            try {
                const res = await fetch(`${API_URL}/api/contacto`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData),
                });

                const data = await res.json();

                if (data.ok) {
                    mostrarMensaje('¡Mensaje enviado correctamente! Te responderemos a la brevedad.', 'success');
                    contactForm.reset();
                } else {
                    mostrarMensaje(data.mensaje || 'Error al enviar el mensaje. Intenta nuevamente.', 'error');
                }
            } catch (err) {
                console.error(err);
                mostrarMensaje('No se pudo conectar con el servidor. Intenta más tarde.', 'error');
            } finally {
                btnEnviar.disabled = false;
                btnEnviar.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Mensaje';
            }
        });
    }

    function mostrarMensaje(mensaje, tipo) {
        const mensajeDiv = document.createElement('div');
        mensajeDiv.className = `mensaje-alerta mensaje-${tipo}`;
        mensajeDiv.textContent = mensaje;

        mensajeDiv.style.cssText = `
            position: fixed;
            top: 120px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            z-index: 9999;
            animation: slideIn 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            max-width: 360px;
        `;

        mensajeDiv.style.background = tipo === 'success' ? '#28a745' : '#dc3545';
        mensajeDiv.style.color = 'white';

        document.body.appendChild(mensajeDiv);

        setTimeout(() => {
            mensajeDiv.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => document.body.removeChild(mensajeDiv), 300);
        }, 4000);
    }

    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn  { from { transform: translateX(400px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(400px); opacity: 0; } }
    `;
    document.head.appendChild(style);
});
