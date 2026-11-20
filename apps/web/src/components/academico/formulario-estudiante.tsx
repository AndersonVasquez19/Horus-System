'use client';

import { Search } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoSeleccion } from '@/components/shared/ui/campo';
import type { EstudianteDetalle, PersonaDni } from '@/features/academico/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { ETIQUETAS } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

const CAMPOS = ['dni', 'nombres', 'apellidos', 'email', 'telefono', 'fecha_nacimiento', 'direccion', 'estado'] as const;
type Datos = Record<(typeof CAMPOS)[number], string>;

interface FormularioEstudianteProps {
  /** Sin estudiante: registro. Con estudiante: edición. */
  estudiante?: EstudianteDetalle;
  onGuardado: (estudiante: EstudianteDetalle, mensaje: string) => void;
  onCancelar: () => void;
  onOcupado?: (ocupado: boolean) => void;
}

function inicial(estudiante?: EstudianteDetalle): Datos {
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

/**
 * Registro y edición de estudiantes. Las reglas (DNI de 8 dígitos y único, correo único, fecha no futura…) las
 * aplica Laravel con StoreEstudianteRequest / UpdateEstudianteRequest; aquí solo se ayudan con atributos HTML y se
 * muestran sus errores campo a campo.
 */
export function FormularioEstudiante({ estudiante, onGuardado, onCancelar, onOcupado }: FormularioEstudianteProps) {
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const puedeConsultarDni = usePuede('estudiantes.crear');
  const [datos, setDatos] = useState<Datos>(() => inicial(estudiante));
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);
  const [consultandoDni, setConsultandoDni] = useState(false);
  const [avisoDni, setAvisoDni] = useState<string | null>(null);

  const cambiar = (campo: keyof Datos) => (evento: { target: { value: string } }) => {
    if (campo === 'dni') setAvisoDni(null);
    setDatos((actuales) => ({ ...actuales, [campo]: evento.target.value }));
  };

  async function consultarDni() {
    const dni = datos.dni.trim();
    if (!/^\d{8}$/.test(dni) || consultandoDni) return;
    setConsultandoDni(true);
    setError(null);
    setAvisoDni(null);
    const resultado = await ejecutar<{ data: PersonaDni }>('academico/consultas/dni', 'POST', { dni });
    setConsultandoDni(false);
    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    const persona = resultado.datos.data;
    setDatos((actuales) => ({
      ...actuales,
      nombres: persona.nombres || actuales.nombres,
      apellidos: persona.apellidos || actuales.apellidos,
    }));
    if (persona.ya_registrado_como_estudiante && (!estudiante || estudiante.dni !== dni)) {
      setAvisoDni('Este DNI ya figura registrado como estudiante en el sistema.');
    } else {
      mostrar('exito', 'Datos del DNI cargados correctamente.');
    }
  }

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setGuardando(true);
    onOcupado?.(true);
    setError(null);

    // Los opcionales vacíos viajan como null (los Form Requests los declaran `nullable`).
    const cuerpo = Object.fromEntries(CAMPOS.map((campo) => [campo, datos[campo].trim() === '' ? null : datos[campo].trim()]));
    const resultado = estudiante
      ? await ejecutar<{ message: string; data: EstudianteDetalle }>(`academico/estudiantes/${estudiante.id}`, 'PUT', cuerpo)
      : await ejecutar<{ message: string; data: EstudianteDetalle }>('academico/estudiantes', 'POST', cuerpo);

    setGuardando(false);
    onOcupado?.(false);
    if (resultado.ok) onGuardado(resultado.datos.data, resultado.datos.message);
    else setError(resultado.error);
  }

  const err = (campo: string) => errorDeCampo(error, campo);

  return (
    <form onSubmit={enviar} noValidate className="space-y-4">
      {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
      {error?.code === 'validacion' ? <Alerta tipo="error">Revisa los campos marcados.</Alerta> : null}
      {avisoDni ? <Alerta tipo="aviso">{avisoDni}</Alerta> : null}

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex items-start gap-2">
          <Campo etiqueta="DNI" className="flex-1" value={datos.dni} onChange={cambiar('dni')} error={err('dni')} required inputMode="numeric" maxLength={8} pattern="\d{8}" autoComplete="off" ayuda="8 dígitos" />
          {puedeConsultarDni ? (
            <Boton
              type="button"
              variante="secundario"
              className="mt-[26px] shrink-0"
              cargando={consultandoDni}
              disabled={guardando || !/^\d{8}$/.test(datos.dni.trim())}
              icono={<Search className="h-4 w-4" aria-hidden="true" />}
              onClick={() => void consultarDni()}
            >
              Consultar DNI
            </Boton>
          ) : null}
        </div>
        <CampoSeleccion etiqueta="Estado" value={datos.estado} onChange={cambiar('estado')} error={err('estado')} opciones={ETIQUETAS.estadoEstudiante} />
        <Campo etiqueta="Nombres" value={datos.nombres} onChange={cambiar('nombres')} error={err('nombres')} required maxLength={100} autoComplete="off" />
        <Campo etiqueta="Apellidos" value={datos.apellidos} onChange={cambiar('apellidos')} error={err('apellidos')} required maxLength={100} autoComplete="off" />
        <Campo etiqueta="Correo (opcional)" type="email" value={datos.email} onChange={cambiar('email')} error={err('email')} maxLength={150} autoComplete="off" />
        <Campo etiqueta="Teléfono (opcional)" type="tel" value={datos.telefono} onChange={cambiar('telefono')} error={err('telefono')} maxLength={20} autoComplete="off" />
        <Campo etiqueta="Fecha de nacimiento (opcional)" type="date" value={datos.fecha_nacimiento} onChange={cambiar('fecha_nacimiento')} error={err('fecha_nacimiento')} />
        <Campo etiqueta="Dirección (opcional)" value={datos.direccion} onChange={cambiar('direccion')} error={err('direccion')} maxLength={255} autoComplete="off" />
      </div>

      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton variante="secundario" onClick={onCancelar} disabled={guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={guardando}>{estudiante ? 'Guardar cambios' : 'Registrar estudiante'}</Boton>
      </div>
    </form>
  );
}
