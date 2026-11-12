'use client';

import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { FileText, AlertCircle, CheckCircle2, Printer, ShieldCheck } from 'lucide-react';
import { CONTACT_INFO } from '@/data/contactos';

const reclamacionSchema = z.object({
  tipo: z.enum(['RECLAMO', 'QUEJA']),
  tipoDocumento: z.enum(['DNI', 'CE', 'PASAPORTE', 'RUC']),
  numeroDocumento: z.string().min(8, 'Ingresa un número de documento válido').max(20),
  nombres: z.string().min(2, 'Ingresa tus nombres completos'),
  apellidos: z.string().min(2, 'Ingresa tus apellidos completos'),
  telefono: z.string().min(7, 'Ingresa un teléfono o celular de contacto'),
  email: z.string().email('Ingresa un correo electrónico válido'),
  domicilio: z.string().min(5, 'Ingresa tu dirección o domicilio'),
  esMenor: z.boolean().default(false),
  nombreApoderado: z.string().optional(),
  dniApoderado: z.string().optional(),
  bienContratado: z.enum(['PRODUCTO', 'SERVICIO']),
  montoReclamado: z.string().optional(),
  descripcionBien: z.string().min(5, 'Describe brevemente el producto o servicio contratado'),
  detalle: z.string().min(15, 'Explica con detalle el motivo de tu reclamo o queja'),
  pedido: z.string().min(10, 'Indica qué solución o pedido solicitas'),
  declaroVeracidad: z.boolean().refine((val) => val === true, {
    message: 'Debes declarar que los datos consignados son verdaderos.',
  }),
});

type ReclamacionFormData = z.infer<typeof reclamacionSchema>;

export function LibroForm() {
  const [tipoSeleccionado, setTipoSeleccionado] = useState<'RECLAMO' | 'QUEJA'>('RECLAMO');
  const [esMenor, setEsMenor] = useState(false);
  const [ticketEnviado, setTicketEnviado] = useState<{
    codigo: string;
    fecha: string;
    fechaLimite: string;
    datos: ReclamacionFormData;
  } | null>(null);

  const {
    register,
    handleSubmit,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<ReclamacionFormData>({
    resolver: zodResolver(reclamacionSchema),
    defaultValues: {
      tipo: 'RECLAMO',
      tipoDocumento: 'DNI',
      bienContratado: 'SERVICIO',
      esMenor: false,
    },
  });

  const handleTipoChange = (nuevoTipo: 'RECLAMO' | 'QUEJA') => {
    setTipoSeleccionado(nuevoTipo);
    setValue('tipo', nuevoTipo);
  };

  const onSubmit = (data: ReclamacionFormData) => {
    // Generate correlation ID
    const prefijo = data.tipo === 'RECLAMO' ? 'REC' : 'QJA';
    const aleatorio = Math.floor(10000 + Math.random() * 90000);
    const anio = new Date().getFullYear();
    const codigo = `${prefijo}-${anio}-${aleatorio}`;

    const fechaHoy = new Date().toLocaleDateString('es-PE', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });

    // 15 dias habiles segun Ley 29571
    const fechaLimite = new Date(Date.now() + 15 * 24 * 60 * 60 * 1000).toLocaleDateString('es-PE', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });

    setTicketEnviado({
      codigo,
      fecha: fechaHoy,
      fechaLimite,
      datos: data,
    });
  };

  if (ticketEnviado) {
    return (
      <div className="rounded-3xl border border-emerald-500/30 bg-[#0A1929]/90 backdrop-blur-2xl p-6 sm:p-10 shadow-2xl text-stone-200 animate-in fade-in zoom-in-95 duration-300">
        <div className="flex items-center gap-4 border-b border-white/10 pb-6 mb-6">
          <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
            <CheckCircle2 className="h-8 w-8" />
          </div>
          <div>
            <span className="text-xs font-bold uppercase tracking-wider text-emerald-400">
              Hoja de Reclamación Registrada
            </span>
            <h3 className="text-2xl font-extrabold text-white">
              Código de Registro: <span className="text-gold">{ticketEnviado.codigo}</span>
            </h3>
          </div>
        </div>

        <div className="grid gap-6 sm:grid-cols-2 text-sm">
          <div className="space-y-2">
            <p className="text-stone-400 text-xs uppercase font-semibold">Consumidor</p>
            <p className="font-bold text-white">
              {ticketEnviado.datos.nombres} {ticketEnviado.datos.apellidos}
            </p>
            <p className="text-stone-300">
              {ticketEnviado.datos.tipoDocumento}: {ticketEnviado.datos.numeroDocumento}
            </p>
            <p className="text-stone-300">Correo: {ticketEnviado.datos.email}</p>
            <p className="text-stone-300">Teléfono: {ticketEnviado.datos.telefono}</p>
          </div>

          <div className="space-y-2">
            <p className="text-stone-400 text-xs uppercase font-semibold">Detalles del Caso</p>
            <p className="font-bold text-white">Tipo: {ticketEnviado.datos.tipo}</p>
            <p className="text-stone-300">Bien: {ticketEnviado.datos.bienContratado}</p>
            <p className="text-stone-300">Fecha de registro: {ticketEnviado.fecha}</p>
            <p className="text-gold font-semibold">
              Plazo máximo de respuesta: {ticketEnviado.fechaLimite} (15 días hábiles)
            </p>
          </div>
        </div>

        <div className="mt-6 rounded-2xl border border-white/10 bg-white/5 p-4 text-xs leading-relaxed text-stone-300">
          <p>
            Hemos registrado tu hoja en nuestro Libro de Reclamaciones conforme al Código de Protección y Defensa del Consumidor (Ley N° 29571). Nuestro equipo legal y de atención al cliente se pondrá en contacto contigo a través del correo consignado.
          </p>
        </div>

        <div className="mt-8 flex flex-wrap items-center justify-between gap-4 pt-6 border-t border-white/10">
          <button
            type="button"
            onClick={() => window.print()}
            className="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-6 py-2.5 text-xs font-semibold text-white hover:bg-white/20 transition-all"
          >
            <Printer className="h-4 w-4 text-gold" />
            <span>Imprimir Hoja de Reclamación</span>
          </button>

          <button
            type="button"
            onClick={() => setTicketEnviado(null)}
            className="rounded-full bg-gold px-6 py-2.5 text-xs font-bold text-ink-dark shadow-gold hover:brightness-110"
          >
            Registrar otra consulta
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-10">
      {/* Selector: ¿Reclamo o Queja? */}
      <div className="grid gap-4 sm:grid-cols-2">
        <div
          onClick={() => handleTipoChange('RECLAMO')}
          className={`cursor-pointer rounded-3xl border p-6 transition-all shadow-xl ${
            tipoSeleccionado === 'RECLAMO'
              ? 'border-gold bg-gold/10 ring-2 ring-gold/30'
              : 'border-white/10 bg-[#0A1929]/70 hover:border-white/30'
          }`}
        >
          <div className="flex items-center gap-3 mb-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-gold/20 text-gold">
              <FileText className="h-5 w-5" />
            </span>
            <h3 className="text-lg font-bold text-white">RECLAMO</h3>
          </div>
          <p className="text-xs text-stone-300 leading-relaxed">
            Disconformidad relacionada con los <strong>productos o servicios</strong> adquiridos. Implica una insatisfacción directa con lo recibido o contratado.
          </p>
        </div>

        <div
          onClick={() => handleTipoChange('QUEJA')}
          className={`cursor-pointer rounded-3xl border p-6 transition-all shadow-xl ${
            tipoSeleccionado === 'QUEJA'
              ? 'border-gold bg-gold/10 ring-2 ring-gold/30'
              : 'border-white/10 bg-[#0A1929]/70 hover:border-white/30'
          }`}
        >
          <div className="flex items-center gap-3 mb-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-gold/20 text-gold">
              <AlertCircle className="h-5 w-5" />
            </span>
            <h3 className="text-lg font-bold text-white">QUEJA</h3>
          </div>
          <p className="text-xs text-stone-300 leading-relaxed">
            Malestar o descontento respecto a la <strong>atención al público</strong> recibida, sin tener relación directa con el producto o servicio contratado.
          </p>
        </div>
      </div>

      {/* Main Form */}
      <form
        onSubmit={handleSubmit(onSubmit)}
        className="rounded-3xl border border-white/10 bg-[#0A1929]/80 backdrop-blur-2xl p-6 sm:p-10 shadow-2xl space-y-8"
      >
        <input type="hidden" {...register('tipo')} value={tipoSeleccionado} />

        {/* Section 1: Identificación del Consumidor */}
        <div>
          <h4 className="text-base font-bold text-gold uppercase tracking-wider mb-4 flex items-center gap-2">
            <span className="h-2 w-2 rounded-full bg-gold"></span>
            1. Identificación del Consumidor Reclamante
          </h4>

          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
              <label className="block text-xs font-semibold text-stone-300 mb-1">
                Tipo de Documento *
              </label>
              <select
                {...register('tipoDocumento')}
                className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-2.5 text-sm text-white focus:border-gold focus:outline-none"
              >
                <option value="DNI">DNI</option>
                <option value="CE">Carné de Extranjería (CE)</option>
                <option value="PASAPORTE">Pasaporte</option>
                <option value="RUC">RUC</option>
              </select>
            </div>

            <div>
              <label className="block text-xs font-semibold text-stone-300 mb-1">
                Número de Documento *
              </label>
              <input
                type="text"
                {...register('numeroDocumento')}
                placeholder="Ej. 74859612"
                className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-2.5 text-sm text-white focus:border-gold focus:outline-none"
              />
              {errors.numeroDocumento && (
                <p className="mt-1 text-xs text-red-400">{errors.numeroDocumento.message}</p>
              )}
            </div>

            <div>
              <label className="block text-xs font-semibold text-stone-300 mb-1">
                Teléfono / Celular *
              </label>
              <input
                type="tel"
                {...register('telefono')}
                placeholder="Ej. 972021374"
                className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-2.5 text-sm text-white focus:border-gold focus:outline-none"
              />
              {errors.telefono && (
                <p className="mt-1 text-xs text-red-400">{errors.telefono.message}</p>
              )}
            </div>

            <div>
              <label className="block text-xs font-semibold text-stone-300 mb-1">
                Nombres Completos *
              </label>
              <input
                type="text"
                {...register('nombres')}
                placeholder="Tus nombres"
                className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-2.5 text-sm text-white focus:border-gold focus:outline-none"
              />
              {errors.nombres && (
                <p className="mt-1 text-xs text-red-400">{errors.nombres.message}</p>
              )}
            </div>

            <div>
              <label className="block text-xs font-semibold text-stone-300 mb-1">
                Apellidos Completos *
              </label>
              <input
                type="text"
                {...register('apellidos')}
                placeholder="Tus apellidos"
                className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-2.5 text-sm text-white focus:border-gold focus:outline-none"
              />
              {errors.apellidos && (
                <p className="mt-1 text-xs text-red-400">{errors.apellidos.message}</p>
              )}
            </div>

            <div>
              <label className="block text-xs font-semibold text-stone-300 mb-1">
                Correo Electrónico *
              </label>
              <input
                type="email"
                {...register('email')}
                placeholder="correo@ejemplo.com"
                className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-2.5 text-sm text-white focus:border-gold focus:outline-none"
              />
              {errors.email && (
                <p className="mt-1 text-xs text-red-400">{errors.email.message}</p>
              )}
            </div>

            <div className="sm:col-span-2 lg:col-span-3">
              <label className="block text-xs font-semibold text-stone-300 mb-1">
                Domicilio / Dirección *
              </label>
              <input
                type="text"
                {...register('domicilio')}
                placeholder="Dirección completa, distrito, ciudad"
                className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-2.5 text-sm text-white focus:border-gold focus:outline-none"
              />
              {errors.domicilio && (
                <p className="mt-1 text-xs text-red-400">{errors.domicilio.message}</p>
              )}
            </div>
          </div>

          {/* Menor de edad Checkbox */}
          <div className="mt-4 pt-3 border-t border-white/5">
            <label className="flex items-center gap-2 cursor-pointer text-xs text-stone-300">
              <input
                type="checkbox"
                checked={esMenor}
                onChange={(e) => {
                  setEsMenor(e.target.checked);
                  setValue('esMenor', e.target.checked);
                }}
                className="h-4 w-4 rounded border-white/20 bg-[#071321] text-gold focus:ring-gold"
              />
              <span>El reclamante es menor de edad (se requiere consignar datos del padre, madre o apoderado)</span>
            </label>

            {esMenor && (
              <div className="mt-4 grid gap-4 sm:grid-cols-2 rounded-2xl border border-white/10 bg-white/5 p-4 animate-in fade-in duration-200">
                <div>
                  <label className="block text-xs font-semibold text-stone-300 mb-1">
                    Nombre del Padre / Madre / Apoderado *
                  </label>
                  <input
                    type="text"
                    {...register('nombreApoderado')}
                    placeholder="Nombres y apellidos del apoderado"
                    className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-2 text-sm text-white focus:border-gold focus:outline-none"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-stone-300 mb-1">
                    DNI del Apoderado *
                  </label>
                  <input
                    type="text"
                    {...register('dniApoderado')}
                    placeholder="DNI del apoderado"
                    className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-2 text-sm text-white focus:border-gold focus:outline-none"
                  />
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Section 2: Identificación del Bien Contratado */}
        <div>
          <h4 className="text-base font-bold text-gold uppercase tracking-wider mb-4 flex items-center gap-2">
            <span className="h-2 w-2 rounded-full bg-gold"></span>
            2. Identificación del Bien Contratado
          </h4>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label className="block text-xs font-semibold text-stone-300 mb-1">
                Tipo de Bien *
              </label>
              <select
                {...register('bienContratado')}
                className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-2.5 text-sm text-white focus:border-gold focus:outline-none"
              >
                <option value="SERVICIO">Servicio (Cursos, Soporte, Cableado, Cámaras)</option>
                <option value="PRODUCTO">Producto (Equipos, Materiales)</option>
              </select>
            </div>

            <div>
              <label className="block text-xs font-semibold text-stone-300 mb-1">
                Monto Reclamado (Opcional)
              </label>
              <input
                type="text"
                {...register('montoReclamado')}
                placeholder="Ej. S/ 250.00"
                className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-2.5 text-sm text-white focus:border-gold focus:outline-none"
              />
            </div>

            <div className="sm:col-span-2">
              <label className="block text-xs font-semibold text-stone-300 mb-1">
                Descripción del Producto o Servicio *
              </label>
              <input
                type="text"
                {...register('descripcionBien')}
                placeholder="Ej. Curso de Instalación de Cámaras de Seguridad / Mantenimiento de laptop"
                className="w-full rounded-xl border border-white/10 bg-[#071321] px-4 py-2.5 text-sm text-white focus:border-gold focus:outline-none"
              />
              {errors.descripcionBien && (
                <p className="mt-1 text-xs text-red-400">{errors.descripcionBien.message}</p>
              )}
            </div>
          </div>
        </div>

        {/* Section 3: Detalle de la Reclamación y Pedido */}
        <div>
          <h4 className="text-base font-bold text-gold uppercase tracking-wider mb-4 flex items-center gap-2">
            <span className="h-2 w-2 rounded-full bg-gold"></span>
            3. Detalle de la Reclamación y Pedido del Consumidor
          </h4>

          <div className="space-y-4">
            <div>
              <label className="block text-xs font-semibold text-stone-300 mb-1">
                Detalle del {tipoSeleccionado} *
              </label>
              <textarea
                rows={4}
                {...register('detalle')}
                placeholder={`Describe claramente los hechos ocurridos que motivan tu ${tipoSeleccionado.toLowerCase()}...`}
                className="w-full rounded-xl border border-white/10 bg-[#071321] p-4 text-sm text-white focus:border-gold focus:outline-none"
              />
              {errors.detalle && (
                <p className="mt-1 text-xs text-red-400">{errors.detalle.message}</p>
              )}
            </div>

            <div>
              <label className="block text-xs font-semibold text-stone-300 mb-1">
                Pedido Concreto *
              </label>
              <textarea
                rows={2}
                {...register('pedido')}
                placeholder="Indica qué solución o resultado esperas de parte de Horus Group SRL..."
                className="w-full rounded-xl border border-white/10 bg-[#071321] p-4 text-sm text-white focus:border-gold focus:outline-none"
              />
              {errors.pedido && (
                <p className="mt-1 text-xs text-red-400">{errors.pedido.message}</p>
              )}
            </div>
          </div>
        </div>

        {/* Declaration and Submit */}
        <div className="space-y-4 pt-4 border-t border-white/10">
          <label className="flex items-start gap-2.5 cursor-pointer text-xs text-stone-300">
            <input
              type="checkbox"
              {...register('declaroVeracidad')}
              className="mt-0.5 h-4 w-4 rounded border-white/20 bg-[#071321] text-gold focus:ring-gold flex-shrink-0"
            />
            <span>
              Declaro ser el titular del servicio/producto (o apoderado) y que los datos consignados en esta hoja de reclamación son verídicos, conforme a la Ley N° 29571.
            </span>
          </label>
          {errors.declaroVeracidad && (
            <p className="text-xs text-red-400">{errors.declaroVeracidad.message}</p>
          )}

          <div className="flex flex-wrap items-center justify-between gap-4 pt-4">
            <span className="text-xs text-stone-400">
              Plazo de respuesta: <strong>15 días hábiles</strong>.
            </span>

            <button
              type="submit"
              disabled={isSubmitting}
              className="rounded-full bg-gradient-to-r from-gold to-gold-dark px-8 py-3.5 text-sm font-bold text-ink-dark shadow-gold hover:brightness-110 transition-all hover:scale-105 disabled:opacity-50"
            >
              {isSubmitting ? 'Registrando hoja...' : 'Enviar Hoja de Reclamación'}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}
