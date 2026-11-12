// Contáctanos: arma el mensaje para WhatsApp y para correo con lo que escribe la persona. No envía nada al servidor.
(function () {
    'use strict';

    var form = document.getElementById('contactForm');
    if (!form) return;

    var wa = document.getElementById('enviarWhatsapp');
    var mail = document.getElementById('enviarCorreo');
    var CORREO = 'horusgroupcajamarca@gmail.com';
    var WHATSAPP = '51972021374';

    function valor(id) {
        return document.getElementById(id).value.trim();
    }

    function mensaje() {
        var asunto = valor('asunto') || 'Consulta';
        var lineas = ['Hola, soy ' + (valor('nombre') || '(nombre)') + '.', 'Asunto: ' + asunto + '.'];
        if (valor('mensaje')) lineas.push(valor('mensaje'));
        if (valor('telefono')) lineas.push('Mi teléfono: ' + valor('telefono'));
        if (valor('email')) lineas.push('Mi correo: ' + valor('email'));
        return lineas.join('\n');
    }

    function actualizar() {
        var texto = mensaje();
        var asunto = valor('asunto') || 'Consulta';
        wa.href = 'https://wa.me/' + WHATSAPP + '?text=' + encodeURIComponent(texto);
        mail.href = 'mailto:' + CORREO + '?subject=' + encodeURIComponent('Consulta: ' + asunto) + '&body=' + encodeURIComponent(texto);
    }

    form.addEventListener('input', actualizar);
    form.addEventListener('change', actualizar);
    actualizar();

    var aviso = document.createElement('div');
    aviso.className = 'ct-toast';
    aviso.setAttribute('role', 'status');
    aviso.setAttribute('aria-live', 'polite');
    document.body.appendChild(aviso);

    function decir(texto) {
        aviso.textContent = texto;
        aviso.classList.add('ct-toast-on');
        setTimeout(function () { aviso.classList.remove('ct-toast-on'); }, 1800);
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (boton) {
        boton.addEventListener('click', function () {
            var texto = boton.getAttribute('data-copy');

            function seleccionar() {
                var destino = boton.previousElementSibling && boton.previousElementSibling.querySelector('p');
                if (!destino) return;
                var rango = document.createRange();
                rango.selectNodeContents(destino);
                var seleccion = window.getSelection();
                seleccion.removeAllRanges();
                seleccion.addRange(rango);
                decir('Texto seleccionado, copia con Ctrl+C');
            }

            try {
                navigator.clipboard.writeText(texto).then(function () { decir('Copiado: ' + texto); }, seleccionar);
            } catch (e) {
                seleccionar();
            }
        });
    });
})();
