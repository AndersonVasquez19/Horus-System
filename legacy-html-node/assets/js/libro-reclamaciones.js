
document.addEventListener('DOMContentLoaded', () => {

    let pasoActual = 1;
    const totalPasos = 3;
    const form = document.getElementById('libroForm');
    const exitoContainer = document.getElementById('exito-container');
    const btnReclamo = document.getElementById('btn-reclamo');
    const btnQueja   = document.getElementById('btn-queja');
    const selectTipo = document.getElementById('tipo-registro');
    const detalleTextarea = document.getElementById('detalle-reclamo');
    const contador        = document.getElementById('contador');
    const fechaInput = document.getElementById('fecha-incidente');
    const seccionTipo = document.querySelector('.diferencia-section');
    const seccionFormulario = document.querySelector('.formulario-section');
    const btnEnviar = document.getElementById('btn-enviar');
    const htmlBtnEnviar = btnEnviar.innerHTML;
    let tipoActual = '';

    if (fechaInput) {
        fechaInput.max = new Date().toISOString().split('T')[0];
    }

    // Los textos que cambian según el tipo llevan data-reclamo y data-queja.
    function aplicarTextosTipo(tipo) {
        document.querySelectorAll('[data-reclamo][data-queja]').forEach(el => {
            el.textContent = tipo === 'queja' ? el.dataset.queja : el.dataset.reclamo;
        });
    }

    function mostrarFormulario() {
        seccionFormulario.hidden = false;
        seccionFormulario.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function seleccionarTipo(tipo, mostrar = false) {
        tipoActual = tipo;
        btnReclamo.classList.toggle('seleccionado', tipo === 'reclamo');
        btnQueja.classList.toggle('seleccionado',   tipo === 'queja');
        btnReclamo.setAttribute('aria-pressed', tipo === 'reclamo');
        btnQueja.setAttribute('aria-pressed',   tipo === 'queja');
        if (selectTipo) selectTipo.value = tipo;
        form.dataset.tipo = tipo;
        aplicarTextosTipo(tipo);
        if (mostrar) mostrarFormulario();
    }

    function alActivarTarjeta(tipo) {
        return e => {
            if (e.type === 'keydown') {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
            }
            seleccionarTipo(tipo, true);
        };
    }

    [[btnReclamo, 'reclamo'], [btnQueja, 'queja']].forEach(([tarjeta, tipo]) => {
        tarjeta.addEventListener('click',   alActivarTarjeta(tipo));
        tarjeta.addEventListener('keydown', alActivarTarjeta(tipo));
    });

    if (selectTipo) {
        selectTipo.addEventListener('change', () => {
            if (selectTipo.value) seleccionarTipo(selectTipo.value);
        });
    }

    if (detalleTextarea && contador) {
        detalleTextarea.addEventListener('input', () => {
            const len = detalleTextarea.value.length;
            contador.textContent = len;
            contador.style.color = len > 900 ? '#e53e3e' : len > 700 ? '#d69e2e' : 'var(--text-secondary)';
            if (len > 1000) detalleTextarea.value = detalleTextarea.value.slice(0, 1000);
        });
    }

    function validarCampo(campo) {
        const errorEl = document.getElementById('error-' + campo.id);
        let valido = true;
        let mensaje = '';

        campo.classList.remove('error', 'valido');

        if (campo.required && !campo.value.trim()) {
            valido = false;
            mensaje = 'Este campo es obligatorio.';
        } else if (campo.type === 'email' && campo.value) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!re.test(campo.value)) { valido = false; mensaje = 'Ingresa un correo válido.'; }
        } else if (campo.type === 'tel' && campo.value) {
            const re = /^[0-9+\s\-()]{7,15}$/;
            if (!re.test(campo.value)) { valido = false; mensaje = 'Ingresa un teléfono válido.'; }
        } else if (campo.id === 'num-doc' && campo.value) {
            if (campo.value.length < 7) { valido = false; mensaje = 'El número de documento es muy corto.'; }
        } else if (campo.id === 'detalle-reclamo' && campo.value) {
            if (campo.value.trim().length < 20) { valido = false; mensaje = 'Por favor describe con más detalle (mínimo 20 caracteres).'; }
        }

        if (errorEl) errorEl.textContent = mensaje;
        if (!valido) campo.classList.add('error');
        else if (campo.value) campo.classList.add('valido');
        return valido;
    }

    document.querySelectorAll('input, select, textarea').forEach(campo => {
        campo.addEventListener('blur', () => validarCampo(campo));
        campo.addEventListener('input', () => {
            if (campo.classList.contains('error')) validarCampo(campo);
        });
    });

    function validarPaso(numeroPaso) {
        const pasoEl = document.getElementById('paso-' + numeroPaso);
        const campos = pasoEl.querySelectorAll('input[required], select[required], textarea[required]');
        let todoValido = true;
        campos.forEach(campo => {
            if (!validarCampo(campo)) todoValido = false;
        });
        return todoValido;
    }

    function irAPaso(nuevo) {
        const pasoActualEl = document.getElementById('paso-' + pasoActual);
        const pasoNuevoEl  = document.getElementById('paso-' + nuevo);
        const indicadores  = document.querySelectorAll('.paso');
        const lineas       = document.querySelectorAll('.paso-linea');

        pasoActualEl.classList.remove('activo');
        pasoNuevoEl.classList.add('activo');

        indicadores.forEach((ind, i) => {
            const num = i + 1;
            ind.classList.remove('activo', 'completado');
            if (num === nuevo) ind.classList.add('activo');
            else if (num < nuevo) ind.classList.add('completado');
        });

        lineas.forEach((linea, i) => {
            linea.classList.toggle('completada', i + 1 < nuevo);
        });

        pasoActual = nuevo;

        if (nuevo === 3) construirResumen();

        document.querySelector('.formulario-section').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    document.getElementById('btn-paso1-siguiente').addEventListener('click', () => {
        if (validarPaso(1)) irAPaso(2);
    });

    document.getElementById('btn-paso2-anterior').addEventListener('click', () => irAPaso(1));
    document.getElementById('btn-paso2-siguiente').addEventListener('click', () => {
        if (validarPaso(2)) irAPaso(3);
    });

    document.getElementById('btn-paso3-anterior').addEventListener('click', () => irAPaso(2));

    function construirResumen() {
        const resumenPersonal  = document.getElementById('resumen-personal');
        const resumenReclamoEl = document.getElementById('resumen-reclamo');

        const val = id => (document.getElementById(id)?.value || '').trim() || '—';
        const txt = id => document.getElementById(id)?.options[document.getElementById(id)?.selectedIndex]?.text || '—';

        resumenPersonal.innerHTML = `
            ${item('Nombres', val('nombres'))}
            ${item('Apellidos', val('apellidos'))}
            ${item('Tipo de Documento', txt('tipo-doc'))}
            ${item('N° Documento', val('num-doc'))}
            ${item('Correo', val('email'))}
            ${item('Teléfono', val('telefono'))}
            ${item('Dirección', val('direccion'), true)}
        `;

        resumenReclamoEl.innerHTML = `
            ${item('Tipo de Registro', txt('tipo-registro'))}
            ${item('Área / Servicio', txt('area'))}
            ${item('Fecha del Incidente', formatearFecha(val('fecha-incidente')))}
            ${item('Bien / Servicio', val('descripcion-bien'), true)}
            ${item('Detalle', val('detalle-reclamo'), true)}
        `;
    }

    function item(label, valor, full = false) {
        return `<div class="resumen-item${full ? ' full' : ''}">
            <span class="resumen-label">${label}</span>
            <span class="resumen-valor">${escapeHtml(valor)}</span>
        </div>`;
    }

    function formatearFecha(fecha) {
        if (!fecha || fecha === '—') return '—';
        const [y, m, d] = fecha.split('-');
        return `${d}/${m}/${y}`;
    }

    function escapeHtml(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // Sin data-endpoint la página histórica usa el servidor Node; data-endpoint="" activa el envío simulado.
    const ENDPOINT = form.dataset.endpoint ?? 'http://localhost:3000/api/reclamaciones';

    function numeroSimulado() {
        const fecha = new Date().toISOString().slice(0, 10).replace(/-/g, '');
        return `SIM-${fecha}-${Math.floor(Math.random() * 9000) + 1000}`;
    }

    async function enviarRegistro(payload) {
        if (!ENDPOINT) {
            console.warn('Libro de reclamaciones: no hay endpoint configurado, el envío es una simulación.');
            await new Promise(resolve => setTimeout(resolve, 600));
            return { ok: true, numero_reclamo: numeroSimulado(), simulado: true };
        }
        const res = await fetch(ENDPOINT, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        return res.json();
    }

    function restaurarBotonEnviar() {
        btnEnviar.disabled = false;
        btnEnviar.innerHTML = htmlBtnEnviar;
        aplicarTextosTipo(tipoActual);
    }

    function mostrarExito(numero, simulado) {
        document.getElementById('numero-reclamo').textContent = numero;
        if (simulado) {
            document.getElementById('exito-simulado').hidden = false;
            document.getElementById('exito-mensaje').hidden = true;
            document.getElementById('exito-info').hidden = true;
        }
        form.hidden = true;
        document.querySelector('.pasos-indicador').hidden = true;
        if (seccionTipo) seccionTipo.hidden = true;
        exitoContainer.hidden = false;
        exitoContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    form.addEventListener('submit', async e => {
        e.preventDefault();

        // Enter en los pasos 1 y 2 avanza de paso en lugar de enviar.
        if (pasoActual < totalPasos) {
            document.getElementById(`btn-paso${pasoActual}-siguiente`).click();
            return;
        }

        const aceptaTerminos = document.getElementById('acepta-terminos');
        const errorTerminos  = document.getElementById('error-terminos');

        if (!aceptaTerminos.checked) {
            errorTerminos.textContent = 'Debes aceptar los términos para continuar.';
            aceptaTerminos.closest('.terminos-label').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        errorTerminos.textContent = '';

        btnEnviar.disabled = true;
        btnEnviar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';

        const val = id => (document.getElementById(id)?.value || '').trim();

        const payload = {
            nombres:               val('nombres'),
            apellidos:             val('apellidos'),
            tipo_doc:              val('tipo-doc'),
            num_doc:               val('num-doc'),
            email:                 val('email'),
            telefono:              val('telefono'),
            direccion:             val('direccion'),
            tipo_registro:         val('tipo-registro'),
            area:                  val('area'),
            fecha_incidente:       val('fecha-incidente'),
            descripcion_bien:      val('descripcion-bien'),
            detalle_reclamo:       val('detalle-reclamo'),
            acepta_comunicaciones: document.getElementById('acepta-comunicaciones')?.checked || false,
        };

        try {
            const data = await enviarRegistro(payload);

            if (data.ok) {
                mostrarExito(data.numero_reclamo, !!data.simulado);
            } else {
                errorTerminos.textContent = data.mensaje || 'Error al registrar el reclamo. Intenta nuevamente.';
                restaurarBotonEnviar();
            }
        } catch (err) {
            console.error(err);
            errorTerminos.textContent = 'No se pudo conectar con el servidor. Intenta más tarde.';
            restaurarBotonEnviar();
        }
    });

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });

    document.querySelectorAll('.diferencia-cards .diferencia-card').forEach((el, i) => {
        el.style.transitionDelay = `${i * 0.12}s`;
        el.classList.add('fade-in');
        observer.observe(el);
    });

    document.querySelectorAll('.aviso-legal, .formulario-contenedor, .hero-stat').forEach(el => {
        el.classList.add('fade-in');
        observer.observe(el);
    });

    document.querySelectorAll('.fade-in').forEach(el => {
        el.addEventListener('transitionend', () => {
            el.style.transitionDelay = '';
        }, { once: true });
    });

    document.querySelectorAll('.input-wrapper input, .input-wrapper select, .input-wrapper textarea').forEach(campo => {
        campo.addEventListener('focus', () => {
            campo.closest('.form-grupo')?.classList.add('enfocado');
        });
        campo.addEventListener('blur', () => {
            campo.closest('.form-grupo')?.classList.remove('enfocado');
        });
    });

});
