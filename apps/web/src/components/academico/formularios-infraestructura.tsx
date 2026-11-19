'use client';

import { z } from 'zod';
import { Alerta } from '@/components/shared/ui/alerta';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoSeleccion } from '@/components/shared/ui/campo';
import { useFormularioPanel } from '@/components/shared/use-formulario-panel';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import type { AulaResumen, PlataformaResumen, SedeResumen } from '@/features/academico/tipos';
import { ETIQUETAS } from '@/lib/panel/formato';

/*
 * Formularios de infraestructura académica (sedes, aulas, plataformas virtuales) con el kit de formularios del panel.
 * Zod solo ayuda a la persona; Laravel (GuardarSedeRequest, GuardarAulaRequest, GuardarPlataformaVirtualRequest) valida de
 * nuevo y sus mensajes se muestran en cada campo. En la edición el código, la sede y el identificador no se envían: no cambian.
 */

interface PropsBase<T> {
  registro?: T;
  onGuardado: (mensaje: string) => void;
  onCancelar: () => void;
  onOcupado?: (ocupado: boolean) => void;
}

const texto = (nombre: string, max: number) => z.string().trim().min(1, `${nombre} es obligatorio.`).max(max, `${nombre} admite hasta ${max} caracteres.`);

function Pie({ guardando, editando, onCancelar, etiqueta }: { guardando: boolean; editando: boolean; onCancelar: () => void; etiqueta: string }) {
  return (
    <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
      <Boton variante="secundario" onClick={onCancelar} disabled={guardando}>Cancelar</Boton>
      <Boton type="submit" cargando={guardando}>{editando ? 'Guardar cambios' : etiqueta}</Boton>
    </div>
  );
}

// ---- Sede ------------------------------------------------------------------------------------------------------------

const esquemaSede = z.object({
  codigo: z.string().trim().regex(/^[A-Za-z0-9-]{2,20}$/, 'El código admite letras, números y guiones (2 a 20 caracteres).'),
  nombre: texto('El nombre', 120),
  direccion: texto('La dirección', 200),
  ciudad: z.string().trim().max(80, 'La ciudad admite hasta 80 caracteres.'),
});

export function FormularioSede({ registro, onGuardado, onCancelar, onOcupado }: PropsBase<SedeResumen>) {
  const { ejecutar } = useOperacionPanel();
  const f = useFormularioPanel({
    esquema: esquemaSede,
    valores: { codigo: registro?.codigo ?? '', nombre: registro?.nombre ?? '', direccion: registro?.direccion ?? '', ciudad: registro?.ciudad ?? '' },
    onOcupado,
    enviar: (d) => {
      const cuerpo = { nombre: d.nombre, direccion: d.direccion, ciudad: d.ciudad === '' ? null : d.ciudad };
      return registro
        ? ejecutar<{ message: string }>(`academico/sedes/${registro.identificador}`, 'PUT', cuerpo)
        : ejecutar<{ message: string }>('academico/sedes', 'POST', { codigo: d.codigo, ...cuerpo });
    },
    onExito: (r) => onGuardado(r.message),
  });

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      {f.hayErroresDeCampo ? <Alerta tipo="error">Revisa los campos marcados.</Alerta> : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo etiqueta="Código" {...f.registro('codigo')} error={f.error('codigo')} disabled={registro !== undefined} maxLength={20} autoComplete="off" ayuda={registro ? 'El código no cambia' : 'Ej. CAJ-01'} />
        <Campo etiqueta="Nombre" {...f.registro('nombre')} error={f.error('nombre')} maxLength={120} autoComplete="off" />
        <Campo etiqueta="Dirección" {...f.registro('direccion')} error={f.error('direccion')} maxLength={200} autoComplete="off" className="sm:col-span-2" />
        <Campo etiqueta="Ciudad (opcional)" {...f.registro('ciudad')} error={f.error('ciudad')} maxLength={80} autoComplete="off" ayuda="Si se deja vacía: Cajamarca" />
      </div>
      <Pie guardando={f.guardando} editando={registro !== undefined} onCancelar={onCancelar} etiqueta="Registrar sede" />
    </form>
  );
}

// ---- Aula ------------------------------------------------------------------------------------------------------------

const esquemaAula = z.object({
  sede: z.string().min(1, 'Selecciona la sede.'),
  codigo: z.string().trim().regex(/^[A-Za-z0-9-]{1,20}$/, 'El código admite letras, números y guiones (hasta 20 caracteres).'),
  nombre: texto('El nombre', 120),
  capacidad: z.number({ invalid_type_error: 'Indica la capacidad.' }).int('La capacidad debe ser un número entero.').min(1, 'La capacidad debe ser al menos 1.').max(5000, 'La capacidad no puede superar 5000.'),
});

export function FormularioAula({ registro, sedes, onGuardado, onCancelar, onOcupado }: PropsBase<AulaResumen> & { sedes: readonly Pick<SedeResumen, 'identificador' | 'codigo' | 'nombre'>[] }) {
  const { ejecutar } = useOperacionPanel();
  const f = useFormularioPanel({
    esquema: esquemaAula,
    valores: { sede: registro?.sede?.identificador ?? '', codigo: registro?.codigo ?? '', nombre: registro?.nombre ?? '', capacidad: registro?.capacidad ?? (Number.NaN as number) },
    onOcupado,
    enviar: (d) =>
      registro
        ? ejecutar<{ message: string }>(`academico/aulas/${registro.identificador}`, 'PUT', { nombre: d.nombre, capacidad: d.capacidad })
        : ejecutar<{ message: string }>('academico/aulas', 'POST', { sede: d.sede, codigo: d.codigo, nombre: d.nombre, capacidad: d.capacidad }),
    onExito: (r) => onGuardado(r.message),
  });
  const opcionesSede: Record<string, string> = { '': 'Selecciona una sede', ...Object.fromEntries(sedes.map((s) => [s.identificador, `${s.codigo} · ${s.nombre}`])) };

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      {f.hayErroresDeCampo ? <Alerta tipo="error">Revisa los campos marcados.</Alerta> : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <CampoSeleccion etiqueta="Sede" {...f.registro('sede')} error={f.error('sede')} opciones={opcionesSede} disabled={registro !== undefined} />
        <Campo etiqueta="Código" {...f.registro('codigo')} error={f.error('codigo')} disabled={registro !== undefined} maxLength={20} autoComplete="off" ayuda={registro ? 'El código y la sede no cambian' : 'Ej. A-101'} />
        <Campo etiqueta="Nombre" {...f.registro('nombre')} error={f.error('nombre')} maxLength={120} autoComplete="off" />
        <Campo etiqueta="Capacidad" type="number" min={1} max={5000} inputMode="numeric" {...f.registro('capacidad', { valueAsNumber: true })} error={f.error('capacidad')} ayuda="Personas que entran en el aula" />
      </div>
      <Pie guardando={f.guardando} editando={registro !== undefined} onCancelar={onCancelar} etiqueta="Registrar aula" />
    </form>
  );
}

// ---- Plataforma virtual ----------------------------------------------------------------------------------------------

const esquemaPlataforma = z.object({
  nombre: texto('El nombre', 80),
  tipo: z.enum(['meet', 'zoom', 'teams', 'otra'], { errorMap: () => ({ message: 'Selecciona el tipo de plataforma.' }) }),
});

export function FormularioPlataforma({ registro, onGuardado, onCancelar, onOcupado }: PropsBase<PlataformaResumen>) {
  const { ejecutar } = useOperacionPanel();
  const f = useFormularioPanel({
    esquema: esquemaPlataforma,
    valores: { nombre: registro?.nombre ?? '', tipo: registro?.tipo ?? 'meet' },
    onOcupado,
    enviar: (d) =>
      registro
        ? ejecutar<{ message: string }>(`academico/plataformas-virtuales/${registro.identificador}`, 'PUT', d)
        : ejecutar<{ message: string }>('academico/plataformas-virtuales', 'POST', d),
    onExito: (r) => onGuardado(r.message),
  });

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      {f.hayErroresDeCampo ? <Alerta tipo="error">Revisa los campos marcados.</Alerta> : null}
      <Alerta tipo="info">Solo se registra la herramienta (Meet, Zoom…). Los enlaces de las clases son privados y no se guardan aquí.</Alerta>
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo etiqueta="Nombre" {...f.registro('nombre')} error={f.error('nombre')} maxLength={80} autoComplete="off" />
        <CampoSeleccion etiqueta="Tipo" {...f.registro('tipo')} error={f.error('tipo')} opciones={ETIQUETAS.tipoPlataforma} />
      </div>
      <Pie guardando={f.guardando} editando={registro !== undefined} onCancelar={onCancelar} etiqueta="Registrar plataforma" />
    </form>
  );
}
