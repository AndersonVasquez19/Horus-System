'use client';

import { useState } from 'react';
import {
  Phone,
  Mail,
  MapPin,
  Clock,
  Copy,
  Check,
  Send,
  ExternalLink,
  MessageCircle,
} from 'lucide-react';
import { CONTACT_INFO } from '@/data/contactos';

export default function ContactosPage() {
  const [copiedField, setCopiedField] = useState<string | null>(null);

  const [formValues, setFormValues] = useState({
    nombre: '',
    telefono: '',
    email: '',
    asunto: '',
    mensaje: '',
    sitio_web: '', // trampa para robots: una persona nunca lo ve ni lo llena
  });
  const [estado, setEstado] = useState<'inactivo' | 'enviando' | 'enviado' | 'error'>('inactivo');
  const [errores, setErrores] = useState<Record<string, string>>({});
  const [aviso, setAviso] = useState<string | null>(null);

  const enviarMensaje = async (e: React.FormEvent) => {
    e.preventDefault();
    setEstado('enviando');
    setErrores({});
    setAviso(null);
    try {
      const respuesta = await fetch('/api/contacto', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          nombre: formValues.nombre,
          telefono: formValues.telefono,
          correo: formValues.email,
          asunto: formValues.asunto,
          mensaje: formValues.mensaje,
          sitio_web: formValues.sitio_web,
        }),
      });
      if (respuesta.status === 201) {
        setEstado('enviado');
        setFormValues({ nombre: '', telefono: '', email: '', asunto: '', mensaje: '', sitio_web: '' });
        return;
      }
      const cuerpo = (await respuesta.json().catch(() => null)) as { message?: string; errors?: Record<string, string[]> } | null;
      if (respuesta.status === 422 && cuerpo?.errors) {
        const porCampo: Record<string, string> = {};
        for (const [campo, mensajes] of Object.entries(cuerpo.errors)) porCampo[campo === 'correo' ? 'email' : campo] = mensajes[0] ?? '';
        setErrores(porCampo);
        setAviso('Revisa los campos marcados.');
      } else {
        setAviso(cuerpo?.message ?? 'No pudimos enviar tu mensaje. Inténtalo más tarde o escríbenos por WhatsApp.');
      }
      setEstado('error');
    } catch {
      setAviso('No pudimos enviar tu mensaje. Revisa tu conexión e inténtalo de nuevo.');
      setEstado('error');
    }
  };

  const copyToClipboard = (text: string, field: string) => {
    navigator.clipboard.writeText(text);
    setCopiedField(field);
    setTimeout(() => setCopiedField(null), 2000);
  };

  const handleChange = (
    e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>
  ) => {
    const { name, value } = e.target;
    setFormValues((prev) => ({ ...prev, [name]: value }));
  };

  const buildMessage = () => {
    const lines = [
      formValues.nombre ? `Nombre: ${formValues.nombre}` : '',
      formValues.telefono ? `Teléfono: ${formValues.telefono}` : '',
      formValues.email ? `Correo: ${formValues.email}` : '',
      formValues.asunto ? `Asunto: ${formValues.asunto}` : '',
      formValues.mensaje ? `\nMensaje:\n${formValues.mensaje}` : '',
    ].filter(Boolean);

    return lines.join('\n');
  };

  const getWhatsAppUrl = () => {
    const text = buildMessage() || 'Hola Horus Group, deseo comunicarme con ustedes.';
    return `https://wa.me/${CONTACT_INFO.phoneClean}?text=${encodeURIComponent(text)}`;
  };

  return (
    <div className="pt-20">
      {/* Hero Contactos 1:1 con contactos.blade.php */}
      <section className="relative overflow-hidden py-16 sm:py-24 bg-gradient-to-br from-[#0A2540] via-[#102d49] to-[#1a2f45] text-white">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row md:items-center justify-between gap-10">
          <div className="max-w-xl">
            <h1 className="text-3xl sm:text-5xl font-bold tracking-tight text-white leading-tight">
              Contáctanos
            </h1>
            <p className="mt-4 text-base sm:text-lg text-white/90 leading-relaxed">
              Estamos aquí para ayudarte. Envíanos tu consulta y te responderemos a la brevedad.
            </p>
          </div>

          {/* 3 Quick Action Pills de Blade */}
          <div className="flex flex-col sm:flex-row md:flex-col gap-3 shrink-0">
            <a
              href={`https://wa.me/${CONTACT_INFO.phoneClean}`}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-3.5 px-5 py-3 rounded-2xl bg-emerald-500 hover:bg-emerald-600 text-white font-semibold transition-all shadow-md group"
            >
              <MessageCircle className="h-6 w-6 text-white shrink-0" />
              <div>
                <span className="block text-sm leading-tight">Escribir por WhatsApp</span>
                <small className="text-xs text-white/80 font-normal">972 021 374</small>
              </div>
            </a>

            <a
              href="tel:+51972021374"
              className="flex items-center gap-3.5 px-5 py-3 rounded-2xl bg-white/10 hover:bg-white/20 border border-white/20 text-white font-semibold transition-all"
            >
              <Phone className="h-5 w-5 text-gold shrink-0" />
              <div>
                <span className="block text-sm leading-tight">Llamar ahora</span>
                <small className="text-xs text-white/70 font-normal">Lunes a sábado</small>
              </div>
            </a>

            <a
              href="#formulario"
              className="flex items-center gap-3.5 px-5 py-3 rounded-2xl bg-white/10 hover:bg-white/20 border border-white/20 text-white font-semibold transition-all"
            >
              <Mail className="h-5 w-5 text-gold shrink-0" />
              <div>
                <span className="block text-sm leading-tight">Enviar un mensaje</span>
                <small className="text-xs text-white/70 font-normal">Completa el formulario</small>
              </div>
            </a>
          </div>
        </div>
      </section>

      {/* Main Section: Data + Form + Map */}
      <section className="bg-[#F7F9FC] py-16 sm:py-24">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-12">
          <div className="grid gap-10 lg:grid-cols-2">
            {/* Información de Contacto */}
            <div className="rounded-3xl border border-stone-200 bg-white p-7 sm:p-9 shadow-sm">
              <h2 className="text-2xl sm:text-3xl font-bold text-[#0A2540]">
                Información de contacto
              </h2>
              <p className="mt-2 text-sm text-slate-600 mb-8">
                Puedes comunicarte con nosotros a través de los siguientes medios.
              </p>

              <div className="space-y-6">
                {/* Teléfono */}
                <div className="flex items-center justify-between p-4 rounded-2xl border border-stone-200 bg-stone-50">
                  <div className="flex items-center gap-4">
                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gold/15 text-gold shrink-0">
                      <Phone className="h-5 w-5" />
                    </div>
                    <div>
                      <h3 className="text-xs font-bold text-slate-500 uppercase tracking-wider">
                        Teléfono y WhatsApp
                      </h3>
                      <p className="text-base font-semibold text-[#0A2540]">{CONTACT_INFO.phone}</p>
                    </div>
                  </div>
                  <button
                    type="button"
                    onClick={() => copyToClipboard(CONTACT_INFO.phone, 'tel')}
                    className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-stone-300 text-xs font-semibold text-slate-700 hover:bg-stone-200 transition-colors"
                  >
                    {copiedField === 'tel' ? (
                      <>
                        <Check className="h-3.5 w-3.5 text-emerald-600" />
                        <span className="text-emerald-600">Copiado</span>
                      </>
                    ) : (
                      <>
                        <Copy className="h-3.5 w-3.5" />
                        <span>Copiar</span>
                      </>
                    )}
                  </button>
                </div>

                {/* Correo */}
                <div className="flex items-center justify-between p-4 rounded-2xl border border-stone-200 bg-stone-50">
                  <div className="flex items-center gap-4">
                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gold/15 text-gold shrink-0">
                      <Mail className="h-5 w-5" />
                    </div>
                    <div>
                      <h3 className="text-xs font-bold text-slate-500 uppercase tracking-wider">
                        Correo
                      </h3>
                      <p className="text-sm sm:text-base font-semibold text-[#0A2540] break-all">
                        {CONTACT_INFO.email}
                      </p>
                    </div>
                  </div>
                  <button
                    type="button"
                    onClick={() => copyToClipboard(CONTACT_INFO.email, 'mail')}
                    className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-stone-300 text-xs font-semibold text-slate-700 hover:bg-stone-200 transition-colors"
                  >
                    {copiedField === 'mail' ? (
                      <>
                        <Check className="h-3.5 w-3.5 text-emerald-600" />
                        <span className="text-emerald-600">Copiado</span>
                      </>
                    ) : (
                      <>
                        <Copy className="h-3.5 w-3.5" />
                        <span>Copiar</span>
                      </>
                    )}
                  </button>
                </div>

                {/* Ubicación */}
                <div className="flex items-center justify-between p-4 rounded-2xl border border-stone-200 bg-stone-50">
                  <div className="flex items-center gap-4">
                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gold/15 text-gold shrink-0">
                      <MapPin className="h-5 w-5" />
                    </div>
                    <div>
                      <h3 className="text-xs font-bold text-slate-500 uppercase tracking-wider">
                        Ubicación
                      </h3>
                      <p className="text-base font-semibold text-[#0A2540]">
                        Jr. José Gálvez #322 <span className="text-slate-500 font-normal">Cajamarca, Perú</span>
                      </p>
                    </div>
                  </div>
                  <a
                    href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent('Jr. José Gálvez 322 Cajamarca')}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-stone-300 text-xs font-semibold text-slate-700 hover:bg-stone-200 transition-colors"
                  >
                    <span>Cómo llegar</span>
                    <ExternalLink className="h-3.5 w-3.5" />
                  </a>
                </div>

                {/* Horario */}
                <div className="flex items-center justify-between p-4 rounded-2xl border border-stone-200 bg-stone-50">
                  <div className="flex items-center gap-4">
                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gold/15 text-gold shrink-0">
                      <Clock className="h-5 w-5" />
                    </div>
                    <div>
                      <h3 className="text-xs font-bold text-slate-500 uppercase tracking-wider">
                        Horario de atención
                      </h3>
                      <p className="text-sm font-semibold text-[#0A2540]">
                        Lunes - Viernes: 9:00 AM - 1:00 PM / 3:00 PM - 6:00 PM <span className="block text-slate-500 font-normal">Sábados: 9:00 AM - 1:00 PM</span>
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            {/* Formulario de Mensaje */}
            <div id="formulario" className="rounded-3xl border border-stone-200 bg-white p-7 sm:p-9 shadow-sm scroll-mt-6">
              <h2 className="text-2xl sm:text-3xl font-bold text-[#0A2540]">
                Envíanos un mensaje
              </h2>
              <p className="mt-2 text-sm text-slate-600 mb-6">
                Escríbenos y te responderemos por teléfono o correo. También puedes enviarlo por WhatsApp.
              </p>

              <form onSubmit={enviarMensaje} className="space-y-4" noValidate>
                <div aria-hidden="true" className="hidden">
                  <label htmlFor="sitio_web">No completar</label>
                  <input type="text" id="sitio_web" name="sitio_web" tabIndex={-1} autoComplete="off" value={formValues.sitio_web} onChange={handleChange} />
                </div>

                <div>
                  <label htmlFor="nombre" className="block text-xs font-bold text-slate-700 uppercase mb-1">
                    Nombre completo
                  </label>
                  <input
                    type="text"
                    id="nombre"
                    name="nombre"
                    value={formValues.nombre}
                    onChange={handleChange}
                    placeholder="Ej. María Pérez"
                    required
                    maxLength={120}
                    className="w-full rounded-xl border border-stone-300 bg-stone-50 px-4 py-2.5 text-sm text-slate-800 focus:bg-white focus:border-[#0A2540] focus:ring-2 focus:ring-[#0A2540]/10 outline-none"
                  />
                  {errores.nombre ? <p role="alert" className="mt-1 text-xs text-red-700">{errores.nombre}</p> : null}
                </div>

                <div className="grid sm:grid-cols-2 gap-4">
                  <div>
                    <label htmlFor="telefono" className="block text-xs font-bold text-slate-700 uppercase mb-1">
                      Teléfono <span className="text-slate-400 font-normal">(opcional)</span>
                    </label>
                    <input
                      type="tel"
                      id="telefono"
                      name="telefono"
                      value={formValues.telefono}
                      onChange={handleChange}
                      placeholder="Ej. 927 000 000"
                      maxLength={30}
                      className="w-full rounded-xl border border-stone-300 bg-stone-50 px-4 py-2.5 text-sm text-slate-800 focus:bg-white focus:border-[#0A2540] focus:ring-2 focus:ring-[#0A2540]/10 outline-none"
                    />
                    {errores.telefono ? <p role="alert" className="mt-1 text-xs text-red-700">{errores.telefono}</p> : null}
                  </div>

                  <div>
                    <label htmlFor="email" className="block text-xs font-bold text-slate-700 uppercase mb-1">
                      Correo <span className="text-slate-400 font-normal">(opcional)</span>
                    </label>
                    <input
                      type="email"
                      id="email"
                      name="email"
                      value={formValues.email}
                      onChange={handleChange}
                      placeholder="nombre@correo.com"
                      maxLength={160}
                      className="w-full rounded-xl border border-stone-300 bg-stone-50 px-4 py-2.5 text-sm text-slate-800 focus:bg-white focus:border-[#0A2540] focus:ring-2 focus:ring-[#0A2540]/10 outline-none"
                    />
                    {errores.email ? <p role="alert" className="mt-1 text-xs text-red-700">{errores.email}</p> : null}
                  </div>
                </div>

                <div>
                  <label htmlFor="asunto" className="block text-xs font-bold text-slate-700 uppercase mb-1">
                    Asunto
                  </label>
                  <select
                    id="asunto"
                    name="asunto"
                    value={formValues.asunto}
                    onChange={handleChange}
                    className="w-full rounded-xl border border-stone-300 bg-stone-50 px-4 py-2.5 text-sm text-slate-800 focus:bg-white focus:border-[#0A2540] focus:ring-2 focus:ring-[#0A2540]/10 outline-none"
                  >
                    <option value="">Selecciona un asunto</option>
                    <option value="Tecnologías">Tecnologías</option>
                    <option value="Educación">Educación</option>
                    <option value="Asesoramiento">Asesoramiento</option>
                    <option value="Capacitaciones">Capacitaciones</option>
                    <option value="Cursos">Cursos</option>
                    <option value="Otro">Otro</option>
                  </select>
                  {errores.asunto ? <p role="alert" className="mt-1 text-xs text-red-700">{errores.asunto}</p> : null}
                </div>

                <div>
                  <label htmlFor="mensaje" className="block text-xs font-bold text-slate-700 uppercase mb-1">
                    Mensaje
                  </label>
                  <textarea
                    id="mensaje"
                    name="mensaje"
                    rows={4}
                    value={formValues.mensaje}
                    onChange={handleChange}
                    placeholder="Cuéntanos qué necesitas"
                    maxLength={1500}
                    className="w-full rounded-xl border border-stone-300 bg-stone-50 px-4 py-2.5 text-sm text-slate-800 focus:bg-white focus:border-[#0A2540] focus:ring-2 focus:ring-[#0A2540]/10 outline-none resize-none"
                  ></textarea>
                  {errores.mensaje ? <p role="alert" className="mt-1 text-xs text-red-700">{errores.mensaje}</p> : null}
                </div>

                {estado === 'enviado' ? (
                  <p role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                    ¡Gracias! Recibimos tu mensaje y te responderemos a la brevedad.
                  </p>
                ) : null}
                {estado === 'error' && aviso ? (
                  <p role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{aviso}</p>
                ) : null}

                <div className="grid sm:grid-cols-2 gap-3 pt-2">
                  <button
                    type="submit"
                    disabled={estado === 'enviando'}
                    className="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-[#0A2540] hover:bg-slate-800 disabled:opacity-60 text-white font-semibold text-sm transition-colors shadow-sm"
                  >
                    <Send className="h-4 w-4" />
                    <span>{estado === 'enviando' ? 'Enviando…' : 'Enviar mensaje'}</span>
                  </button>

                  <a
                    href={getWhatsAppUrl()}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-sm transition-colors shadow-sm"
                  >
                    <MessageCircle className="h-4 w-4" />
                    <span>Enviar por WhatsApp</span>
                  </a>
                </div>

                <p className="text-xs text-slate-500 pt-2 text-center sm:text-left">
                  Indica al menos un teléfono o un correo para poder responderte. Si prefieres WhatsApp, se abre con el mensaje listo.
                </p>
              </form>
            </div>
          </div>

          {/* Full-width Map con Label de Blade */}
          <div className="rounded-3xl border border-stone-200 bg-white p-4 sm:p-6 shadow-sm overflow-hidden">
            <div className="overflow-hidden rounded-2xl border border-stone-200 bg-stone-100">
              <iframe
                title="Mapa de la ubicación de Horus Group en Cajamarca"
                src="https://www.google.com/maps/embed?pb=!1m14!1m8!1m3!1d989.6682159535815!2d-78.5107197!3d-7.1637652!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x91b25b809bf9b7e3%3A0x70d5a58b14aa7eff!2sPlazuela%20Bolognesi!5e0!3m2!1ses-419!2spe!4v1772667902105!5m2!1ses-419!2spe"
                className="h-[340px] w-full border-0 block"
                loading="lazy"
                allowFullScreen
                referrerPolicy="no-referrer-when-downgrade"
              ></iframe>
            </div>

            <div className="mt-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-2">
              <p className="text-sm text-slate-700">
                <strong className="text-[#0A2540]">Horus Group SRL: </strong>
                Jr. José Gálvez N° 322, Cajamarca
              </p>
              <a
                href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent('Jr. José Gálvez 322 Cajamarca')}`}
                target="_blank"
                rel="noopener noreferrer"
                className="text-xs font-bold text-gold-dark hover:underline inline-flex items-center gap-1"
              >
                <span>Abrir en Google Maps</span>
                <ExternalLink className="h-3.5 w-3.5" />
              </a>
            </div>
          </div>
        </div>
      </section>
    </div>
  );
}
