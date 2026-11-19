'use client';

import { Search, UserCheck } from 'lucide-react';
import { useState } from 'react';
import { z } from 'zod';
import { Alerta } from '@/components/shared/ui/alerta';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoSeleccion } from '@/components/shared/ui/campo';
import { useFormularioPanel } from '@/components/shared/use-formulario-panel';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import type { DocenteDetalle, PersonaDni } from '@/features/academico/tipos';
import { ETIQUETAS } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

/** Errores de la consulta tras los que se permite registrar a mano (el servicio externo falló, el DNI no está mal). */
const CODIGOS_ALTA_MANUAL = new Set(['dni_servicio_no_disponible', 'dni_servicio_no_configurado', 'dni_servicio_no_autorizado', 'dni_limite_proveedor', 'dni_respuesta_incompleta']);

const opcional = (max: number) => z.string().trim().max(max, `Admite hasta ${max} caracteres.`);

const base = {
  nombres: z.string().trim().min(1, 'Los nombres son obligatorios.').max(100, 'Admite hasta 100 caracteres.'),
  apellidos: z.string().trim().min(1, 'Los apellidos son obligatorios.').max(100, 'Admite hasta 100 caracteres.'),
  email: opcional(150).refine((v) => v === '' || z.string().email().safeParse(v).success, 'Ingresa un correo electrónico válido.'),
  telefono: opcional(20).refine((v) => v === '' || /^[0-9+()\s-]{6,20}$/.test(v), 'El teléfono admite dígitos, +, paréntesis, espacios y guiones (6 a 20).'),
  especialidad: opcional(150),
  estado: z.enum(['activo', 'inactivo']),
};
const esquemaAlta = z.object({ dni: z.string().regex(/^\d{8}$/, 'El DNI debe tener exactamente 8 dígitos.'), ...base });
const esquemaEdicion = z.object({ dni: z.string(), ...base });

interface Props {
  /** Sin docente: alta con consulta de DNI. Con docente: edición (el DNI no cambia). */
  docente?: DocenteDetalle;
  onGuardado: (docente: DocenteDetalle, mensaje: string) => void;
  onCancelar: () => void;
  onOcupado?: (ocupado: boolean) => void;
}

/**
 * Alta y edición de docentes. Identificación solo por DNI peruano: al registrar, se consulta el DNI (JSON.pe desde Laravel;
 * el token jamás llega al navegador), se muestran los datos para confirmarlos y recién entonces se registra. Si el servicio
 * externo no está disponible, se permite ingresar los datos a mano; nunca se inventan ni se completan datos.
 */
export function FormularioDocente({ docente, onGuardado, onCancelar, onOcupado }: Props) {
  const { ejecutar } = useOperacionPanel();
  const editando = docente !== undefined;
  const [persona, setPersona] = useState<PersonaDni | null>(null);
  const [manual, setManual] = useState(false);
  const [consultando, setConsultando] = useState(false);
  const [errorDni, setErrorDni] = useState<ErrorPanel | null>(null);

  const f = useFormularioPanel({
    esquema: editando ? esquemaEdicion : esquemaAlta,
    valores: {
      dni: docente?.dni ?? '',
      nombres: docente?.nombres ?? '',
      apellidos: docente?.apellidos ?? '',
      email: docente?.email ?? '',
      telefono: docente?.telefono ?? '',
      especialidad: docente?.especialidad ?? '',
      estado: docente?.estado ?? 'activo',
    },
    onOcupado,
    enviar: (d) => {
      const comunes = { nombres: d.nombres, apellidos: d.apellidos, email: d.email === '' ? null : d.email, telefono: d.telefono === '' ? null : d.telefono, especialidad: d.especialidad === '' ? null : d.especialidad };
      return docente
        ? ejecutar<{ message: string; data: DocenteDetalle }>(`academico/docentes/${docente.identificador}`, 'PUT', { ...comunes, estado: d.estado })
        : ejecutar<{ message: string; data: DocenteDetalle }>('academico/docentes', 'POST', { dni: d.dni, ...comunes });
    },
    onExito: (r) => onGuardado(r.data, r.message),
  });

  const dniActual = f.form.watch('dni');
  const datosListos = editando || persona !== null || manual;
  const bloqueadoPorDuplicado = persona?.ya_registrado_como_docente === true || persona?.ya_registrado_como_estudiante === true;

  async function consultarDni() {
    setErrorDni(null);
    setPersona(null);
    if (!/^\d{8}$/.test(dniActual)) {
      setErrorDni({ message: 'El DNI debe tener exactamente 8 dígitos.', code: 'validacion' });
      return;
    }
    setConsultando(true);
    const r = await ejecutar<{ data: PersonaDni }>('academico/consultas/dni', 'POST', { dni: dniActual });
    setConsultando(false);
    if (!r.ok) {
      setErrorDni(r.error);
      return;
    }
    setPersona(r.datos.data);
    f.form.setValue('nombres', r.datos.data.nombres, { shouldValidate: false });
    f.form.setValue('apellidos', r.datos.data.apellidos, { shouldValidate: false });
  }

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      {f.hayErroresDeCampo ? <Alerta tipo="error">Revisa los campos marcados.</Alerta> : null}

      {editando ? (
        <Campo etiqueta="DNI" value={docente.dni} readOnly disabled ayuda="El DNI identifica a la persona y no cambia." />
      ) : (
        <div className="space-y-3">
          <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
            <Campo
              etiqueta="DNI"
              {...f.registro('dni')}
              error={f.error('dni')}
              inputMode="numeric"
              maxLength={8}
              autoComplete="off"
              disabled={persona !== null || manual}
              className="flex-1"
              ayuda="8 dígitos. Se consulta en RENIEC para completar los datos."
            />
            {persona === null && !manual ? (
              <Boton variante="secundario" icono={<Search className="h-4 w-4" aria-hidden="true" />} onClick={consultarDni} cargando={consultando}>Consultar DNI</Boton>
            ) : (
              <Boton variante="secundario" onClick={() => { setPersona(null); setManual(false); setErrorDni(null); }}>Cambiar DNI</Boton>
            )}
          </div>

          {errorDni ? (
            <Alerta tipo={errorDni.code === 'validacion' ? 'error' : 'aviso'}>
              {errorDni.message}
              {CODIGOS_ALTA_MANUAL.has(errorDni.code) && !manual ? (
                <button type="button" onClick={() => { setManual(true); setErrorDni(null); }} className="ml-2 font-semibold underline">Ingresar los datos manualmente</button>
              ) : null}
            </Alerta>
          ) : null}
          {persona && !bloqueadoPorDuplicado ? (
            <Alerta tipo="exito" titulo="Datos encontrados en RENIEC">
              <span className="inline-flex items-center gap-2"><UserCheck className="h-4 w-4" aria-hidden="true" />{persona.nombres} {persona.apellidos}. Revisa que correspondan a la persona y completa el resto.</span>
            </Alerta>
          ) : null}
          {persona?.ya_registrado_como_docente ? <Alerta tipo="error">Este DNI ya está registrado como docente (puede estar desactivado: restáuralo desde el listado).</Alerta> : null}
          {persona?.ya_registrado_como_estudiante ? <Alerta tipo="error">Este DNI ya está registrado como estudiante: una misma persona no puede ser estudiante y docente.</Alerta> : null}
          {manual ? <Alerta tipo="aviso">Registro manual: el servicio de consulta no estuvo disponible. Verifica el nombre contra el documento de la persona.</Alerta> : null}
        </div>
      )}

      {datosListos ? (
        <>
          <div className="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Nombres" {...f.registro('nombres')} error={f.error('nombres')} maxLength={100} autoComplete="off" readOnly={persona !== null} />
            <Campo etiqueta="Apellidos" {...f.registro('apellidos')} error={f.error('apellidos')} maxLength={100} autoComplete="off" readOnly={persona !== null} />
            <Campo etiqueta="Correo (opcional)" type="email" {...f.registro('email')} error={f.error('email')} maxLength={150} autoComplete="off" />
            <Campo etiqueta="Teléfono (opcional)" type="tel" {...f.registro('telefono')} error={f.error('telefono')} maxLength={20} autoComplete="off" />
            <Campo etiqueta="Especialidad (opcional)" {...f.registro('especialidad')} error={f.error('especialidad')} maxLength={150} autoComplete="off" className="sm:col-span-2" />
            {editando ? <CampoSeleccion etiqueta="Estado" {...f.registro('estado')} error={f.error('estado')} opciones={ETIQUETAS.estadoActivo} /> : null}
          </div>
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton variante="secundario" onClick={onCancelar} disabled={f.guardando}>Cancelar</Boton>
            <Boton type="submit" cargando={f.guardando} disabled={bloqueadoPorDuplicado}>{editando ? 'Guardar cambios' : 'Registrar docente'}</Boton>
          </div>
        </>
      ) : (
        <div className="flex justify-end border-t border-panel-border pt-4">
          <Boton variante="secundario" onClick={onCancelar}>Cancelar</Boton>
        </div>
      )}
    </form>
  );
}
