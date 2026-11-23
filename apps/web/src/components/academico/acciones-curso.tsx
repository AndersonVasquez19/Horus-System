'use client';

import { ArchiveRestore, Archive, BookPlus, CalendarRange, Copy, Eye, Link2, Pencil } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { BotonAccionFila, EnlaceAccionFila } from '@/components/shared/ui/acciones-celda';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Dialogo, DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import type { CursoDetalle, CursoResumen } from '@/features/academico/tipos';
import { copiarAlPortapapeles, mensajeFichaCurso } from '@/lib/panel/whatsapp-plantillas';
import { FormularioCurso } from './formulario-curso';

const LISTADO = '/admin/academico/cursos';
const RUTA_CATALOGO_PUBLICO = '/educacion/cursos#catalogo-cursos';

function urlCatalogoPublico(): string {
  if (typeof window === 'undefined') return RUTA_CATALOGO_PUBLICO;
  return `${window.location.origin}${RUTA_CATALOGO_PUBLICO}`;
}

/** Botón «Nuevo curso» del listado: abre el formulario en un diálogo y lleva al detalle del curso creado. */
export function BotonNuevoCurso() {
  const puede = usePuede('cursos.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);

  if (!puede) return null;

  return (
    <>
      <Boton icono={<BookPlus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>
        Nuevo curso
      </Boton>
      <Dialogo abierto={abierto} lado="derecha" titulo="Registrar curso" descripcion="La ficha pública se autoriza aparte, desde el detalle del curso." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <FormularioCurso
          onOcupado={setOcupado}
          onCancelar={() => setAbierto(false)}
          onGuardado={(curso, mensaje) => {
            setAbierto(false);
            mostrar('exito', mensaje);
            router.push(`${LISTADO}/${curso.id}`);
          }}
        />
      </Dialogo>
    </>
  );
}

type Confirmacion = 'archivar' | 'restaurar' | null;

/** Acciones del detalle del curso: editar, duplicar, copiar enlace público, archivar y restaurar. */
export function AccionesCurso({ curso }: { curso: CursoDetalle }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puedeCrear = usePuede('cursos.crear');
  const puedeEditar = usePuede('cursos.editar');
  const puedeEliminar = usePuede('cursos.eliminar');
  const puedeRestaurar = usePuede('cursos.restaurar');
  const [editando, setEditando] = useState(false);
  const [duplicando, setDuplicando] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [confirmacion, setConfirmacion] = useState<Confirmacion>(null);
  const [error, setError] = useState<string | null>(null);

  async function duplicar() {
    setDuplicando(true);
    const resultado = await ejecutar<{ message: string; data: { id: number } }>(`academico/cursos/${curso.id}/duplicar`, 'POST');
    setDuplicando(false);
    if (!resultado.ok) {
      mostrar('error', resultado.error.message);
      return;
    }
    mostrar('exito', resultado.datos.message);
    router.push(`${LISTADO}/${resultado.datos.data.id}`);
  }

  function copiarEnlacePublico() {
    if (typeof navigator === 'undefined' || !navigator.clipboard) {
      mostrar('error', 'El portapapeles no está disponible en este navegador.');
      return;
    }
    navigator.clipboard.writeText(urlCatalogoPublico()).then(
      () => mostrar('exito', 'Enlace del catálogo público copiado.'),
      () => mostrar('error', 'No se pudo copiar el enlace.'),
    );
  }

  async function confirmar() {
    if (confirmacion === null) return;
    setOcupado(true);
    setError(null);
    const resultado = confirmacion === 'archivar'
      ? await ejecutar<{ message: string }>(`academico/cursos/${curso.id}`, 'DELETE')
      : await ejecutar<{ message: string }>(`academico/cursos/${curso.id}/restaurar`, 'POST');
    setOcupado(false);

    if (!resultado.ok) {
      setError(resultado.error.message);
      return;
    }
    setConfirmacion(null);
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  const cerrarConfirmacion = () => {
    setConfirmacion(null);
    setError(null);
  };

  return (
    <>
      {curso.eliminado ? (
        puedeRestaurar ? (
          <Boton variante="secundario" icono={<ArchiveRestore className="h-4 w-4" aria-hidden="true" />} onClick={() => setConfirmacion('restaurar')}>
            Restaurar
          </Boton>
        ) : null
      ) : (
        <>
          {curso.publicado ? (
            <Boton variante="secundario" icono={<Link2 className="h-4 w-4" aria-hidden="true" />} onClick={copiarEnlacePublico}>
              Copiar enlace público
            </Boton>
          ) : null}
          <Boton
            variante="fantasma"
            icono={<Copy className="h-4 w-4" aria-hidden="true" />}
            onClick={async () => {
              const ok = await copiarAlPortapapeles(
                mensajeFichaCurso({
                  nombre: curso.nombre,
                  modalidad: curso.modalidad,
                  duracionHoras: curso.duracion_horas,
                  precio: curso.precio,
                  descripcion: curso.descripcion,
                  urlPublica: curso.publicado ? urlCatalogoPublico() : null,
                }),
              );
              mostrar(ok ? 'exito' : 'error', ok ? 'Ficha del curso copiada para WhatsApp.' : 'No se pudo copiar.');
            }}
          >
            Copiar informes
          </Boton>
          {puedeCrear ? (
            <Boton variante="secundario" icono={<Copy className="h-4 w-4" aria-hidden="true" />} onClick={duplicar} cargando={duplicando}>
              Duplicar
            </Boton>
          ) : null}
          {puedeEditar ? (
            <Boton variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setEditando(true)}>
              Editar
            </Boton>
          ) : null}
          {puedeEliminar ? (
            <Boton variante="secundario" icono={<Archive className="h-4 w-4" aria-hidden="true" />} onClick={() => setConfirmacion('archivar')}>
              Archivar
            </Boton>
          ) : null}
        </>
      )}

      <Dialogo abierto={editando} lado="derecha" titulo="Editar curso" descripcion={`${curso.codigo} · ${curso.nombre}`} onCerrar={() => setEditando(false)} bloqueado={ocupado}>
        <FormularioCurso
          curso={curso}
          onOcupado={setOcupado}
          onCancelar={() => setEditando(false)}
          onGuardado={(_, mensaje) => {
            setEditando(false);
            mostrar('exito', mensaje);
            router.refresh();
          }}
        />
      </Dialogo>

      <DialogoConfirmacion
        abierto={confirmacion !== null}
        titulo={confirmacion === 'restaurar' ? '¿Restaurar el curso?' : '¿Archivar el curso?'}
        texto={
          confirmacion === 'restaurar' ? (
            <p>Volverá a aparecer en los listados y en las nuevas matrículas si su estado es «Activo».</p>
          ) : (
            <p>
              Dejará de aparecer en los listados y en las nuevas matrículas. Su historial se conserva y puede restaurarse. Si tiene
              matrículas vigentes, primero hay que anularlas.
            </p>
          )
        }
        confirmar={confirmacion === 'restaurar' ? 'Restaurar' : 'Archivar'}
        peligro={confirmacion === 'archivar'}
        cargando={ocupado}
        error={error}
        onConfirmar={confirmar}
        onCerrar={cerrarConfirmacion}
      />
    </>
  );
}

/** Acciones directas por fila en el listado de cursos (sin menú de 3 puntos). */
export function MenuFilaCurso({ curso }: { curso: CursoResumen }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puedeCrear = usePuede('cursos.crear');
  const [duplicando, setDuplicando] = useState(false);

  async function duplicar() {
    setDuplicando(true);
    const resultado = await ejecutar<{ message: string; data: { id: number } }>(`academico/cursos/${curso.id}/duplicar`, 'POST');
    setDuplicando(false);
    if (!resultado.ok) {
      mostrar('error', resultado.error.message);
      return;
    }
    mostrar('exito', resultado.datos.message);
    router.push(`${LISTADO}/${resultado.datos.data.id}`);
  }

  return (
    <div className="inline-flex items-center justify-end gap-1.5">
      <EnlaceAccionFila href={`${LISTADO}/${curso.id}`} etiqueta="Ver" icono={<Eye className="h-3.5 w-3.5" aria-hidden="true" />} />
      <EnlaceAccionFila
        href={`/admin/academico/convocatorias?curso_id=${curso.id}`}
        etiqueta="Ediciones"
        tono="primario"
        icono={<CalendarRange className="h-3.5 w-3.5" aria-hidden="true" />}
      />
      {puedeCrear ? (
        <BotonAccionFila
          onClick={() => void duplicar()}
          disabled={duplicando}
          etiqueta="Duplicar"
          icono={<Copy className="h-3.5 w-3.5" aria-hidden="true" />}
        />
      ) : null}
    </div>
  );
}
