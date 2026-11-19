'use client';

import { useState, type FormEvent } from 'react';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoArea, CampoSeleccion } from '@/components/shared/ui/campo';
import type { CursoDetalle } from '@/features/academico/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { ETIQUETAS } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

const CAMPOS = ['codigo', 'nombre', 'descripcion', 'categoria', 'duracion_horas', 'precio', 'modalidad', 'horario', 'cupos_maximos', 'estado'] as const;
type Datos = Record<(typeof CAMPOS)[number], string>;

interface FormularioCursoProps {
  /** Sin curso: registro. Con curso: edición. */
  curso?: CursoDetalle;
  onGuardado: (curso: CursoDetalle, mensaje: string) => void;
  onCancelar: () => void;
  onOcupado?: (ocupado: boolean) => void;
}

function inicial(curso?: CursoDetalle): Datos {
  return {
    codigo: curso?.codigo ?? '',
    nombre: curso?.nombre ?? '',
    descripcion: curso?.descripcion ?? '',
    categoria: curso?.categoria ?? 'tecnologia',
    duracion_horas: curso ? String(curso.duracion_horas) : '',
    precio: curso?.precio ?? '',
    modalidad: curso?.modalidad ?? 'presencial',
    horario: curso?.horario ?? '',
    cupos_maximos: curso ? String(curso.cupos_maximos) : '',
    estado: curso?.estado ?? 'activo',
  };
}

/**
 * Registro y edición de cursos. Las reglas (código único, límites numéricos, cupos no inferiores a las matrículas
 * activas…) las aplica Laravel con StoreCursoRequest / UpdateCursoRequest y GestionCursos; aquí solo se muestran sus
 * errores campo a campo. Los cupos disponibles nunca se envían: los calcula el servidor.
 */
export function FormularioCurso({ curso, onGuardado, onCancelar, onOcupado }: FormularioCursoProps) {
  const { ejecutar } = useOperacionPanel();
  const [datos, setDatos] = useState<Datos>(() => inicial(curso));
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);

  const cambiar = (campo: keyof Datos) => (evento: { target: { value: string } }) =>
    setDatos((actuales) => ({ ...actuales, [campo]: evento.target.value }));

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setGuardando(true);
    onOcupado?.(true);
    setError(null);

    const cuerpo = Object.fromEntries(CAMPOS.map((campo) => [campo, datos[campo].trim()]));
    const resultado = curso
      ? await ejecutar<{ message: string; data: CursoDetalle }>(`academico/cursos/${curso.id}`, 'PUT', cuerpo)
      : await ejecutar<{ message: string; data: CursoDetalle }>('academico/cursos', 'POST', cuerpo);

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

      <div className="grid gap-4 sm:grid-cols-2">
        <Campo etiqueta="Código" value={datos.codigo} onChange={cambiar('codigo')} error={err('codigo')} required maxLength={20} autoComplete="off" />
        <CampoSeleccion etiqueta="Estado" value={datos.estado} onChange={cambiar('estado')} error={err('estado')} opciones={ETIQUETAS.estadoCurso} />
        <Campo etiqueta="Nombre" value={datos.nombre} onChange={cambiar('nombre')} error={err('nombre')} required maxLength={150} autoComplete="off" className="sm:col-span-2" />
        <CampoArea etiqueta="Descripción" value={datos.descripcion} onChange={cambiar('descripcion')} error={err('descripcion')} required rows={4} maxLength={5000} className="sm:col-span-2" />
        <CampoSeleccion etiqueta="Categoría" value={datos.categoria} onChange={cambiar('categoria')} error={err('categoria')} opciones={ETIQUETAS.categoria} />
        <CampoSeleccion etiqueta="Modalidad" value={datos.modalidad} onChange={cambiar('modalidad')} error={err('modalidad')} opciones={ETIQUETAS.modalidad} />
        <Campo etiqueta="Duración (horas)" type="number" inputMode="numeric" min={1} step={1} value={datos.duracion_horas} onChange={cambiar('duracion_horas')} error={err('duracion_horas')} required />
        <Campo etiqueta="Precio (S/)" type="number" inputMode="decimal" min={0} step="0.01" value={datos.precio} onChange={cambiar('precio')} error={err('precio')} required />
        <Campo etiqueta="Horario" value={datos.horario} onChange={cambiar('horario')} error={err('horario')} required maxLength={100} autoComplete="off" />
        <Campo
          etiqueta="Cupos máximos"
          type="number"
          inputMode="numeric"
          min={1}
          max={10}
          step={1}
          value={datos.cupos_maximos}
          onChange={cambiar('cupos_maximos')}
          error={err('cupos_maximos')}
          required
          ayuda={curso ? 'Máximo 10. No puede ser menor que las matrículas vigentes.' : 'Máximo 10. Los cupos disponibles empiezan igual al máximo.'}
        />
      </div>

      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton variante="secundario" onClick={onCancelar} disabled={guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={guardando}>{curso ? 'Guardar cambios' : 'Registrar curso'}</Boton>
      </div>
    </form>
  );
}
