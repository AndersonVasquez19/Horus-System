'use client';

import { useFormularioPanel } from '@/components/shared/use-formulario-panel';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoArea, CampoSeleccion } from '@/components/shared/ui/campo';
import type { CursoDetalle } from '@/features/academico/tipos';
import { esquemaCurso, type DatosCurso } from '@/lib/panel/esquemas';
import { ETIQUETAS } from '@/lib/panel/formato';

interface FormularioCursoProps {
  /** Sin curso: registro. Con curso: edición. */
  curso?: CursoDetalle;
  onGuardado: (curso: CursoDetalle, mensaje: string) => void;
  onCancelar: () => void;
  onOcupado?: (ocupado: boolean) => void;
}

function inicial(curso?: CursoDetalle): DatosCurso {
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
    enlace_grupo_whatsapp: curso?.enlace_grupo_whatsapp ?? '',
  };
}

/**
 * Registro y edición de cursos. Las reglas (código único, límites numéricos, cupos no inferiores a las matrículas
 * activas…) las aplica Laravel con StoreCursoRequest / UpdateCursoRequest y GestionCursos; el esquema de
 * `lib/panel/esquemas.ts` solo ayuda en el navegador y los errores del servidor se muestran campo a campo. Los cupos
 * disponibles nunca se envían: los calcula el servidor.
 */
export function FormularioCurso({ curso, onGuardado, onCancelar, onOcupado }: FormularioCursoProps) {
  const { ejecutar } = useOperacionPanel();

  const f = useFormularioPanel({
    esquema: esquemaCurso,
    valores: inicial(curso),
    onOcupado,
    // El esquema ya recorta los textos: el cuerpo son los mismos campos de siempre, como cadenas.
    enviar: (datos) =>
      curso
        ? ejecutar<{ message: string; data: CursoDetalle }>(`academico/cursos/${curso.id}`, 'PUT', datos)
        : ejecutar<{ message: string; data: CursoDetalle }>('academico/cursos', 'POST', datos),
    onExito: (respuesta) => onGuardado(respuesta.data, respuesta.message),
  });

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      {f.hayErroresDeCampo ? <Alerta tipo="error">Revisa los campos marcados.</Alerta> : null}

      <div className="grid gap-4 sm:grid-cols-2">
        <Campo etiqueta="Código" {...f.registro('codigo')} error={f.error('codigo')} required maxLength={20} autoComplete="off" />
        <CampoSeleccion etiqueta="Estado" {...f.registro('estado')} error={f.error('estado')} opciones={ETIQUETAS.estadoCurso} />
        <Campo etiqueta="Nombre" {...f.registro('nombre')} error={f.error('nombre')} required maxLength={150} autoComplete="off" className="sm:col-span-2" />
        <CampoArea etiqueta="Descripción" {...f.registro('descripcion')} error={f.error('descripcion')} required rows={4} maxLength={5000} className="sm:col-span-2" />
        <CampoSeleccion etiqueta="Categoría" {...f.registro('categoria')} error={f.error('categoria')} opciones={ETIQUETAS.categoria} />
        <CampoSeleccion etiqueta="Modalidad" {...f.registro('modalidad')} error={f.error('modalidad')} opciones={ETIQUETAS.modalidad} />
        <Campo etiqueta="Duración (horas)" type="number" inputMode="numeric" min={1} step={1} {...f.registro('duracion_horas')} error={f.error('duracion_horas')} required />
        <Campo etiqueta="Precio (S/)" type="number" inputMode="decimal" min={0} step="0.01" {...f.registro('precio')} error={f.error('precio')} required />
        <Campo etiqueta="Horario" {...f.registro('horario')} error={f.error('horario')} required maxLength={100} autoComplete="off" />
        <Campo
          etiqueta="Cupos máximos"
          type="number"
          inputMode="numeric"
          min={1}
          max={10}
          step={1}
          {...f.registro('cupos_maximos')}
          error={f.error('cupos_maximos')}
          required
          ayuda={curso ? 'Máximo 10. No puede ser menor que las matrículas vigentes.' : 'Máximo 10. Los cupos disponibles empiezan igual al máximo.'}
        />
        <Campo
          etiqueta="Grupo de WhatsApp (opcional)"
          type="url"
          {...f.registro('enlace_grupo_whatsapp')}
          error={f.error('enlace_grupo_whatsapp')}
          maxLength={255}
          placeholder="https://chat.whatsapp.com/..."
          ayuda="Enlace de invitación al grupo del curso. Se incluye al dar la bienvenida por WhatsApp."
          className="sm:col-span-2"
        />
      </div>

      <div className="pie-acciones">
        <Boton variante="secundario" onClick={onCancelar} disabled={f.guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={f.guardando}>{curso ? 'Guardar cambios' : 'Registrar curso'}</Boton>
      </div>
    </form>
  );
}
