'use client';

import { Loader2, Search } from 'lucide-react';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useFormularioPanel } from '@/components/shared/use-formulario-panel';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoSeleccion } from '@/components/shared/ui/campo';
import type { EstudianteDetalle, PersonaDni } from '@/features/academico/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { cuerpoEstudiante, esquemaEstudiante, type DatosEstudiante } from '@/lib/panel/esquemas';
import { ETIQUETAS } from '@/lib/panel/formato';

interface FormularioEstudianteProps {
  /** Sin estudiante: registro. Con estudiante: edición. */
  estudiante?: EstudianteDetalle;
  onGuardado: (estudiante: EstudianteDetalle, mensaje: string) => void;
  onCancelar: () => void;
  onOcupado?: (ocupado: boolean) => void;
}

function inicial(estudiante?: EstudianteDetalle): DatosEstudiante {
  return {
    dni: estudiante?.dni ?? '',
    nombres: estudiante?.nombres ?? '',
    apellidos: estudiante?.apellidos ?? '',
    email: estudiante?.email ?? '',
    telefono: estudiante?.telefono ?? '',
    fecha_nacimiento: estudiante?.fecha_nacimiento ?? '',
    direccion: estudiante?.direccion ?? '',
    estado: estudiante?.estado ?? 'activo',
  };
}

const DNI_COMPLETO = /^\d{8}$/;

/**
 * Registro y edición de estudiantes. Las reglas (DNI de 8 dígitos y único, correo único, fecha no futura…) las
 * aplica Laravel con StoreEstudianteRequest / UpdateEstudianteRequest; el esquema de `lib/panel/esquemas.ts` solo
 * ayuda en el navegador y los errores del servidor se muestran campo a campo.
 */
export function FormularioEstudiante({ estudiante, onGuardado, onCancelar, onOcupado }: FormularioEstudianteProps) {
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const puedeConsultarDni = usePuede('estudiantes.crear');
  const [consultandoDni, setConsultandoDni] = useState(false);
  const [errorDni, setErrorDni] = useState<string | null>(null);
  const [avisoDni, setAvisoDni] = useState<string | null>(null);

  const f = useFormularioPanel({
    esquema: esquemaEstudiante,
    valores: inicial(estudiante),
    onOcupado,
    // Los opcionales vacíos viajan como null (los Form Requests los declaran `nullable`).
    enviar: (datos) => {
      const cuerpo = cuerpoEstudiante(datos);
      return estudiante
        ? ejecutar<{ message: string; data: EstudianteDetalle }>(`academico/estudiantes/${estudiante.id}`, 'PUT', cuerpo)
        : ejecutar<{ message: string; data: EstudianteDetalle }>('academico/estudiantes', 'POST', cuerpo);
    },
    onExito: (respuesta) => onGuardado(respuesta.data, respuesta.message),
  });

  const dniEscrito = f.form.watch('dni');
  const dniListo = DNI_COMPLETO.test(dniEscrito.trim());

  async function consultarDni() {
    const dni = f.form.getValues('dni').trim();
    if (!DNI_COMPLETO.test(dni) || consultandoDni) return;
    setConsultandoDni(true);
    setErrorDni(null);
    setAvisoDni(null);
    const resultado = await ejecutar<{ data: PersonaDni }>('academico/consultas/dni', 'POST', { dni });
    setConsultandoDni(false);
    if (!resultado.ok) {
      setErrorDni(errorDeCampo(resultado.error, 'dni') ?? resultado.error.message);
      return;
    }
    const persona = resultado.datos.data;
    if (persona.nombres) f.form.setValue('nombres', persona.nombres, { shouldDirty: true });
    if (persona.apellidos) f.form.setValue('apellidos', persona.apellidos, { shouldDirty: true });
    if (persona.ya_registrado_como_estudiante && (!estudiante || estudiante.dni !== dni)) {
      setAvisoDni('Este DNI ya figura registrado como estudiante en el sistema.');
    } else {
      mostrar('exito', 'Datos del DNI cargados correctamente.');
    }
  }

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      {f.hayErroresDeCampo ? <Alerta tipo="error">Revisa los campos marcados.</Alerta> : null}
      {errorDni ? <Alerta tipo="error">{errorDni}</Alerta> : null}
      {avisoDni ? <Alerta tipo="aviso">{avisoDni}</Alerta> : null}

      <div className="grid gap-4 sm:grid-cols-2">
        <Campo
          etiqueta="DNI"
          required
          inputMode="numeric"
          maxLength={8}
          autoComplete="off"
          ayuda={puedeConsultarDni ? '8 dígitos. La lupa carga los nombres.' : '8 dígitos'}
          {...f.registro('dni', { onChange: () => setAvisoDni(null) })}
          error={f.error('dni')}
          accesorio={
            puedeConsultarDni ? (
              <button
                type="button"
                onClick={() => void consultarDni()}
                disabled={f.guardando || consultandoDni || !dniListo}
                aria-label="Consultar DNI"
                title="Consultar DNI"
                className="inline-flex h-9 w-9 items-center justify-center rounded-md text-academico-700 hover:bg-academico-50 disabled:cursor-not-allowed disabled:opacity-40"
              >
                {consultandoDni ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : <Search className="h-4 w-4" aria-hidden="true" />}
              </button>
            ) : undefined
          }
        />
        <CampoSeleccion etiqueta="Estado" {...f.registro('estado')} error={f.error('estado')} opciones={ETIQUETAS.estadoEstudiante} />
        <Campo etiqueta="Nombres" {...f.registro('nombres')} error={f.error('nombres')} required maxLength={100} autoComplete="off" />
        <Campo etiqueta="Apellidos" {...f.registro('apellidos')} error={f.error('apellidos')} required maxLength={100} autoComplete="off" />
        <Campo etiqueta="Correo (opcional)" type="email" {...f.registro('email')} error={f.error('email')} maxLength={150} autoComplete="off" />
        <Campo
          etiqueta="Teléfono (opcional)"
          type="tel"
          inputMode="numeric"
          maxLength={9}
          ayuda="9 dígitos (celular peruano, inicia en 9)."
          {...f.registro('telefono')}
          error={f.error('telefono')}
          autoComplete="off"
        />
        <Campo
          etiqueta="Fecha de nacimiento (opcional)"
          type="date"
          min="1920-01-01"
          max="2010-12-31"
          ayuda="Máximo hasta el año 2010."
          {...f.registro('fecha_nacimiento')}
          error={f.error('fecha_nacimiento')}
        />
        <Campo etiqueta="Dirección (opcional)" {...f.registro('direccion')} error={f.error('direccion')} maxLength={255} autoComplete="off" />
      </div>

      <div className="pie-acciones">
        <Boton variante="secundario" onClick={onCancelar} disabled={f.guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={f.guardando}>{estudiante ? 'Guardar cambios' : 'Registrar estudiante'}</Boton>
      </div>
    </form>
  );
}
