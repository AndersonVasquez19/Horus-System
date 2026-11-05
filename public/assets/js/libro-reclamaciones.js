// Libro de Reclamaciones: asistente de 3 pasos conectado a POST /libro-reclamaciones (Laravel).
// El servidor valida y numera; este script solo guía, valida en el navegador y muestra la respuesta real.
// El formulario se muestra siempre. Con data-habilitado="0" (módulo apagado) se puede recorrer y validar,
// pero el envío queda bloqueado: no hay fetch ni POST. El servidor responde 404 de todas formas.

document.addEventListener('DOMContentLoaded', () => {
    iniciarAnimaciones();

    const form = document.getElementById('libroForm');
    if (form) iniciarFormulario(form);
});

function iniciarAnimaciones() {
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
}

function iniciarFormulario(form) {
    const TOTAL_PASOS = 3;
    let pasoActual = 1;
    let tipoActual = '';
    let enviando = false;

    const el = id => document.getElementById(id);
    const exitoContainer = el('exito-container');
    const btnReclamo = el('btn-reclamo');
    const btnQueja = el('btn-queja');
    const inputTipo = el('tipo');
    const detalle = el('detalle');
    const contador = el('contador');
    const esMenor = el('es_menor');
    const montoBien = el('monto_bien');
    const montoNoAplica = el('monto_no_aplica');
    const tipoDocumento = el('tipo_documento');
    const numeroDocumento = el('numero_documento');
    const alerta = el('form-alerta');
    const seccionTipo = document.querySelector('.diferencia-section');
    const seccionFormulario = document.querySelector('.formulario-section');
    const btnEnviar = el('btn-enviar');
    const htmlBtnEnviar = btnEnviar.innerHTML;
    // Lo decide el servidor al renderizar; sin la marca explícita se trata como apagado.
    const envioHabilitado = form.dataset.habilitado === '1';
    const avisoEnvio = el('aviso-envio-deshabilitado');
    if (!envioHabilitado) btnEnviar.disabled = true;

    const MENSAJES = {
        404: 'El registro en línea del Libro de Reclamaciones todavía no se encuentra habilitado. Inténtalo más adelante.',
        419: 'Tu sesión expiró. Recarga la página e inténtalo nuevamente.',
        429: 'Has realizado demasiados intentos. Espera unos minutos antes de volver a enviar.',
        red: 'No se pudo conectar con el servidor. Revisa tu conexión e inténtalo nuevamente.',
        general: 'No se pudo completar el registro. Inténtalo nuevamente en unos minutos.',
        revisar: 'Revisa los campos marcados para continuar.',
        rechazado: 'Este dato no fue aceptado. Revísalo e inténtalo nuevamente.',
    };

    // Mismas reglas que StoreReclamacionRequest, para avisar antes de enviar.
    const DOCUMENTO = {
        dni: [/^\d{8}$/, 'El DNI debe tener 8 dígitos.'],
        ruc: [/^\d{11}$/, 'El RUC debe tener 11 dígitos.'],
        ce: [/^[A-Z0-9]{9,12}$/, 'El carné de extranjería debe tener entre 9 y 12 letras o números.'],
        pasaporte: [/^[A-Z0-9]{6,12}$/, 'El pasaporte debe tener entre 6 y 12 letras o números.'],
    };
    const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const TELEFONO = /^[+0-9() .-]{5,30}$/;
    const MONTO = /^\d{1,10}(\.\d{1,2})?$/;
    const SIN_HTML = /[<>]/;
    const MINIMO = { nombres: 2, apellidos: 2, domicilio: 5, detalle: 10, pedido: 5 };

    // --- Tipo de registro y bloques condicionales -------------------------------------------------------

    function aplicarTextosTipo(tipo) {
        document.querySelectorAll('[data-reclamo][data-queja]').forEach(nodo => {
            nodo.textContent = tipo === 'queja' ? nodo.dataset.queja : nodo.dataset.reclamo;
        });
    }

    // Un bloque oculto queda deshabilitado: no se valida ni viaja en el envío.
    function alternarBloque(condicion, visible) {
        form.querySelectorAll(`[data-condicion="${condicion}"]`).forEach(grupo => {
            grupo.hidden = !visible;
            grupo.querySelectorAll('input, select, textarea').forEach(campo => {
                campo.disabled = !visible;
                if (!visible) limpiarCampo(campo);
            });
        });
    }

    function actualizarMonto() {
        if (!montoBien || montoBien.closest('[data-condicion]')?.hidden) return;
        const noAplica = montoNoAplica.checked;
        montoBien.disabled = noAplica;
        montoBien.required = !noAplica;
        if (noAplica) {
            montoBien.value = '';
            limpiarCampo(montoBien);
        }
    }

    function seleccionarTipo(tipo, mostrar = false) {
        tipoActual = tipo;
        inputTipo.value = tipo;
        form.dataset.tipo = tipo;
        btnReclamo.classList.toggle('seleccionado', tipo === 'reclamo');
        btnQueja.classList.toggle('seleccionado', tipo === 'queja');
        btnReclamo.setAttribute('aria-pressed', tipo === 'reclamo');
        btnQueja.setAttribute('aria-pressed', tipo === 'queja');
        aplicarTextosTipo(tipo);
        alternarBloque('reclamo', tipo === 'reclamo');
        actualizarMonto();
        if (mostrar) {
            seccionFormulario.hidden = false;
            seccionFormulario.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
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
        tarjeta.addEventListener('click', alActivarTarjeta(tipo));
        tarjeta.addEventListener('keydown', alActivarTarjeta(tipo));
    });

    esMenor.addEventListener('change', () => alternarBloque('menor', esMenor.checked));
    montoNoAplica.addEventListener('change', actualizarMonto);
    tipoDocumento.addEventListener('change', () => {
        if (numeroDocumento.value) validarCampo(numeroDocumento);
    });

    detalle.addEventListener('input', () => {
        const len = detalle.value.length;
        contador.textContent = len;
        contador.style.color = len > 2700 ? '#e53e3e' : len > 2100 ? '#d69e2e' : 'var(--text-secondary)';
    });

    // --- Validación ---------------------------------------------------------------------------------------

    function mostrarError(nombre, mensaje) {
        const destino = el('error-' + nombre);
        if (destino) destino.textContent = mensaje;
    }

    function limpiarCampo(campo) {
        campo.classList.remove('error', 'valido');
        campo.removeAttribute('aria-invalid');
        if (campo.name) mostrarError(campo.name, '');
    }

    function marcarCampo(campo, mensaje) {
        campo.classList.toggle('error', !!mensaje);
        campo.classList.toggle('valido', !mensaje && !!campo.value.trim());
        if (mensaje) campo.setAttribute('aria-invalid', 'true');
        else campo.removeAttribute('aria-invalid');
        mostrarError(campo.name, mensaje);
    }

    function mensajeCampo(campo) {
        const valor = campo.value.trim();
        if (!valor) return campo.required ? 'Este campo es obligatorio.' : '';
        if (campo.type !== 'email' && SIN_HTML.test(valor)) return 'No uses los caracteres < ni >.';
        if (campo.type === 'email' && !EMAIL.test(valor)) return 'Ingresa un correo válido.';
        if (campo.type === 'tel' && !TELEFONO.test(valor)) return 'Ingresa un teléfono válido (números, espacios, +, - o paréntesis).';
        if (campo.name === 'numero_documento') {
            const regla = DOCUMENTO[tipoDocumento.value];
            if (regla && !regla[0].test(valor.replace(/\s+/g, '').toUpperCase())) return regla[1];
        }
        if (campo.name === 'monto_bien' && !MONTO.test(valor)) return 'Ingresa un monto válido, con hasta dos decimales (ej.: 150.00).';
        const minimo = MINIMO[campo.name];
        if (minimo && valor.length < minimo) return `Escribe al menos ${minimo} caracteres.`;
        return '';
    }

    function validarRadios(nombre) {
        const opciones = [...form.querySelectorAll(`input[type="radio"][name="${nombre}"]`)];
        if (opciones.every(o => o.disabled)) return true;
        const valido = opciones.some(o => o.checked);
        mostrarError(nombre, valido ? '' : 'Selecciona una opción.');
        return valido;
    }

    function validarCampo(campo) {
        if (campo.disabled || !campo.name || campo.type === 'hidden' || campo.type === 'checkbox' || 'ignorar' in campo.dataset) return true;
        if (campo.type === 'radio') return validarRadios(campo.name);
        const mensaje = mensajeCampo(campo);
        marcarCampo(campo, mensaje);
        return !mensaje;
    }

    function validarPaso(numero) {
        const campos = document.getElementById('paso-' + numero).querySelectorAll('input, select, textarea');
        let valido = true;
        let primero = null;
        const radios = new Set();
        campos.forEach(campo => {
            if (campo.type === 'radio') {
                if (radios.has(campo.name)) return;
                radios.add(campo.name);
            }
            if (!validarCampo(campo)) {
                valido = false;
                primero ??= campo;
            }
        });
        primero?.focus({ preventScroll: true });
        return valido;
    }

    form.querySelectorAll('input, select, textarea').forEach(campo => {
        if (campo.type === 'radio') {
            campo.addEventListener('change', () => validarRadios(campo.name));
            return;
        }
        campo.addEventListener('blur', () => validarCampo(campo));
        campo.addEventListener('input', () => {
            if (campo.classList.contains('error')) validarCampo(campo);
        });
    });

    // --- Navegación entre pasos ---------------------------------------------------------------------------

    function irAPaso(nuevo) {
        document.getElementById('paso-' + pasoActual).classList.remove('activo');
        document.getElementById('paso-' + nuevo).classList.add('activo');

        document.querySelectorAll('.paso').forEach((ind, i) => {
            ind.classList.remove('activo', 'completado');
            if (i + 1 === nuevo) ind.classList.add('activo');
            else if (i + 1 < nuevo) ind.classList.add('completado');
        });
        document.querySelectorAll('.paso-linea').forEach((linea, i) => {
            linea.classList.toggle('completada', i + 1 < nuevo);
        });

        pasoActual = nuevo;
        if (nuevo === 3) construirResumen();
        seccionFormulario.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    el('btn-paso1-siguiente').addEventListener('click', () => {
        ocultarAlerta();
        if (validarPaso(1)) irAPaso(2);
    });
    el('btn-paso2-anterior').addEventListener('click', () => irAPaso(1));
    el('btn-paso2-siguiente').addEventListener('click', () => {
        ocultarAlerta();
        if (validarPaso(2)) irAPaso(3);
    });
    el('btn-paso3-anterior').addEventListener('click', () => irAPaso(2));

    // --- Resumen ------------------------------------------------------------------------------------------

    function escapeHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function item(label, valor, full = false) {
        return `<div class="resumen-item${full ? ' full' : ''}">
            <span class="resumen-label">${label}</span>
            <span class="resumen-valor">${escapeHtml(valor)}</span>
        </div>`;
    }

    function construirResumen() {
        const val = id => (el(id)?.value || '').trim() || '—';
        const opcion = id => el(id)?.options[el(id).selectedIndex]?.text || '—';
        const bien = form.querySelector('input[name="bien_tipo"]:checked')?.value;

        let personal = item('Nombres', val('nombres')) + item('Apellidos', val('apellidos'))
            + item('Tipo de Documento', opcion('tipo_documento')) + item('N° Documento', val('numero_documento'))
            + item('Correo', val('email')) + item('Teléfono', val('telefono')) + item('Domicilio', val('domicilio'), true);
        if (esMenor.checked) {
            personal += item('Representante', val('representante_nombre')) + item('Teléfono del representante', val('representante_telefono'))
                + item('Correo del representante', val('representante_email')) + item('Domicilio del representante', val('representante_domicilio'), true);
        }

        let registro = item('Tipo de Registro', tipoActual === 'queja' ? 'Queja' : 'Reclamo');
        if (tipoActual === 'reclamo') {
            registro += item('Producto o servicio', bien === 'producto' ? 'Producto' : bien === 'servicio' ? 'Servicio' : '—')
                + item('Monto', montoNoAplica.checked ? 'No aplica' : `S/ ${val('monto_bien')}`)
                + item('Descripción', val('bien_descripcion'), true);
        }
        registro += item('Detalle', val('detalle'), true) + item('Lo que solicitas', val('pedido'), true);

        el('resumen-personal').innerHTML = personal;
        el('resumen-reclamo').innerHTML = registro;
    }

    // --- Envío --------------------------------------------------------------------------------------------

    function mostrarAlerta(mensaje) {
        alerta.textContent = mensaje;
        alerta.hidden = false;
        alerta.focus({ preventScroll: true });
        alerta.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function ocultarAlerta() {
        alerta.hidden = true;
        alerta.textContent = '';
    }

    function restaurarBotonEnviar() {
        enviando = false;
        btnEnviar.disabled = false;
        btnEnviar.removeAttribute('aria-busy');
        btnEnviar.innerHTML = htmlBtnEnviar;
        aplicarTextosTipo(tipoActual);
    }

    // Errores 422: cada uno junto a su campo, con texto en español del propio formulario; el formulario vuelve
    // al primer paso con errores. Los errores sin campo visible (marca, token, campo trampa) van a la alerta.
    function mostrarErroresServidor(errores) {
        let primerPaso = null;
        let primerCampo = null;
        const generales = [];

        Object.entries(errores).forEach(([nombre, mensajes]) => {
            const campo = form.querySelector(`[name="${nombre}"]:not([type="hidden"]):not([data-ignorar])`);
            if (!campo || !el('error-' + nombre)) {
                generales.push(nombre === 'marca' ? mensajes[0] : MENSAJES.general);
                return;
            }
            const mensaje = campo.type === 'radio' ? 'Selecciona una opción.' : (mensajeCampo(campo) || MENSAJES.rechazado);
            if (campo.type === 'radio') mostrarError(nombre, mensaje);
            else marcarCampo(campo, mensaje);
            const paso = Number(campo.closest('.form-paso')?.id.replace('paso-', ''));
            if (primerPaso === null || paso < primerPaso) {
                primerPaso = paso;
                primerCampo = campo;
            }
        });

        if (primerPaso !== null) {
            irAPaso(primerPaso);
            mostrarAlerta([MENSAJES.revisar, ...new Set(generales)].join(' '));
            primerCampo.focus({ preventScroll: true });
        } else {
            mostrarAlerta([...new Set(generales)].join(' ') || MENSAJES.general);
        }
    }

    function formatearFecha(iso) {
        const [y, m, d] = String(iso).split('-');
        return d && m && y ? `${d}/${m}/${y}` : String(iso);
    }

    function mostrarExito(datos) {
        aplicarTextosTipo(datos.tipo);
        el('numero-reclamo').textContent = datos.numero;
        el('exito-tipo').textContent = datos.tipo === 'queja' ? 'Queja' : 'Reclamo';
        el('exito-fecha-limite').textContent = formatearFecha(datos.fecha_limite);
        form.hidden = true;
        document.querySelector('.pasos-indicador').hidden = true;
        if (seccionTipo) seccionTipo.hidden = true;
        exitoContainer.hidden = false;
        exitoContainer.focus({ preventScroll: true });
        exitoContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    form.addEventListener('submit', async e => {
        e.preventDefault();

        // Enter en los pasos 1 y 2 avanza de paso en lugar de enviar.
        if (pasoActual < TOTAL_PASOS) {
            el(`btn-paso${pasoActual}-siguiente`).click();
            return;
        }
        // Módulo apagado: nunca se envía, aunque el botón se reactive desde las herramientas del navegador.
        if (!envioHabilitado) {
            btnEnviar.disabled = true;
            avisoEnvio?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        // Un solo envío a la vez: el doble clic no genera otra petición.
        if (enviando) return;

        const aceptaTerminos = el('acepta-terminos');
        if (!aceptaTerminos.checked) {
            el('error-terminos').textContent = 'Debes aceptar la declaración para continuar.';
            aceptaTerminos.closest('.terminos-label').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        el('error-terminos').textContent = '';

        for (const paso of [1, 2]) {
            if (!validarPaso(paso)) {
                irAPaso(paso);
                mostrarAlerta(MENSAJES.revisar);
                return;
            }
        }

        enviando = true;
        btnEnviar.disabled = true;
        btnEnviar.setAttribute('aria-busy', 'true');
        btnEnviar.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Enviando...';
        ocultarAlerta();

        // FormData incluye _token (CSRF), marca, token_envio, tipo y el campo trampa; omite los deshabilitados.
        // El token_envio no cambia entre reintentos: si una petición llegó, repetirla devuelve el mismo número.
        let respuesta;
        try {
            respuesta = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
        } catch {
            mostrarAlerta(MENSAJES.red);
            restaurarBotonEnviar();
            return;
        }

        const datos = await respuesta.json().catch(() => null);
        if ((respuesta.status === 201 || respuesta.status === 200) && datos?.numero) {
            mostrarExito(datos);
            return;
        }
        if (respuesta.status === 422 && datos?.errors) mostrarErroresServidor(datos.errors);
        else mostrarAlerta(MENSAJES[respuesta.status] || MENSAJES.general);
        restaurarBotonEnviar();
    });

    document.querySelectorAll('.input-wrapper input, .input-wrapper select, .input-wrapper textarea').forEach(campo => {
        campo.addEventListener('focus', () => campo.closest('.form-grupo')?.classList.add('enfocado'));
        campo.addEventListener('blur', () => campo.closest('.form-grupo')?.classList.remove('enfocado'));
    });
}
