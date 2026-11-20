'use client';

import { ArchiveRestore, FileText, MessageCircle, Pencil, UserMinus, UserPlus } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Dialogo, DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import type { EstudianteDetalle } from '@/features/academico/tipos';
import { enlaceWaMe } from '@/lib/panel/whatsapp-plantillas';
import { FormularioEstudiante } from './formulario-estudiante';

const LISTADO = '/admin/academico/estudiantes';

/** Botón «Nuevo estudiante» del listado: abre el formulario en un diálogo y lleva a la ficha creada. */
export function BotonNuevoEstudiante() {
  const puede = usePuede('estudiantes.crear');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);

  if (!puede) return null;

  return (
    <>
      <Boton icono={<UserPlus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>
        Nuevo estudiante
      </Boton>
      <Dialogo abierto={abierto} lado="derecha" titulo="Registrar estudiante" descripcion="Los campos opcionales pueden completarse después." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <FormularioEstudiante
          onOcupado={setOcupado}
          onCancelar={() => setAbierto(false)}
          onGuardado={(estudiante, mensaje) => {
            setAbierto(false);
            mostrar('exito', mensaje);
            router.push(`${LISTADO}/${estudiante.id}`);
          }}
        />
      </Dialogo>
    </>
  );
}

type Confirmacion = 'desactivar' | 'restaurar' | null;

/** Acciones de la ficha del estudiante: editar, desactivar (eliminación lógica) y restaurar. */
export function AccionesEstudiante({ estudiante }: { estudiante: EstudianteDetalle }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar, descargar } = useOperacionPanel();
  const puedeEditar = usePuede('estudiantes.editar');
  const puedeEliminar = usePuede('estudiantes.eliminar');
  const puedeRestaurar = usePuede('estudiantes.restaurar');
  const [editando, setEditando] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [confirmacion, setConfirmacion] = useState<Confirmacion>(null);
  const [error, setError] = useState<string | null>(null);

  async function confirmar() {
    if (confirmacion === null) return;
    setOcupado(true);
    setError(null);
    const resultado = confirmacion === 'desactivar'
      ? await ejecutar<{ message: string }>(`academico/estudiantes/${estudiante.id}`, 'DELETE')
      : await ejecutar<{ message: string }>(`academico/estudiantes/${estudiante.id}/restaurar`, 'POST');
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
      {estudiante.eliminado ? (
        puedeRestaurar ? (
          <Boton variante="secundario" icono={<ArchiveRestore className="h-4 w-4" aria-hidden="true" />} onClick={() => setConfirmacion('restaurar')}>
            Restaurar
          </Boton>
        ) : null
      ) : (
        <>
          <Boton
            variante="fantasma"
            icono={<FileText className="h-4 w-4" aria-hidden="true" />}
            onClick={async () => {
              const r = await descargar(`academico/estudiantes/${estudiante.id}/historial/pdf`);
              mostrar(r.ok ? 'exito' : 'error', r.ok ? 'Historial académico descargado en PDF.' : r.error.message);
            }}
          >
            Historial PDF
          </Boton>
          {enlaceWaMe(estudiante.telefono) ? (
            <a
              href={enlaceWaMe(estudiante.telefono) ?? '#'}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center justify-center gap-2 rounded-lg border border-emerald-600/30 bg-emerald-50 px-3.5 py-2 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100"
            >
              <MessageCircle className="h-4 w-4" aria-hidden="true" /> WhatsApp
            </a>
          ) : null}
          {puedeEditar ? (
            <Boton variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setEditando(true)}>
              Editar
            </Boton>
          ) : null}
          {puedeEliminar ? (
            <Boton variante="secundario" icono={<UserMinus className="h-4 w-4" aria-hidden="true" />} onClick={() => setConfirmacion('desactivar')}>
              Desactivar
            </Boton>
          ) : null}
        </>
      )}

      <Dialogo abierto={editando} lado="derecha" titulo="Editar estudiante" descripcion={`${estudiante.nombres} ${estudiante.apellidos}`} onCerrar={() => setEditando(false)} bloqueado={ocupado}>
        <FormularioEstudiante
          estudiante={estudiante}
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
        titulo={confirmacion === 'restaurar' ? '¿Restaurar al estudiante?' : '¿Desactivar al estudiante?'}
        texto={
          confirmacion === 'restaurar' ? (
            <p>Volverá a aparecer en los listados y podrá matricularse si su estado es «Activo».</p>
          ) : (
            <p>
              Dejará de aparecer en los listados y en las nuevas matrículas. Su historial se conserva y puede restaurarse. Si tiene
              matrículas vigentes, primero hay que anularlas.
            </p>
          )
        }
        confirmar={confirmacion === 'restaurar' ? 'Restaurar' : 'Desactivar'}
        peligro={confirmacion === 'desactivar'}
        cargando={ocupado}
        error={error}
        onConfirmar={confirmar}
        onCerrar={cerrarConfirmacion}
      />
    </>
  );
}
