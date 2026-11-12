'use client';

import { ArchiveRestore, Ban, Download, Pencil, Plus, Trash2 } from 'lucide-react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import type { MatriculaDetalle } from '@/features/academico/tipos';

const LISTADO = '/admin/academico/matriculas';
const ENLACE = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold outline-offset-2 transition focus-visible:outline-2 focus-visible:outline-academico-700';

/** Acciones de cabecera del listado: nueva matrícula y exportación CSV (cada una con su permiso). */
export function AccionesListadoMatriculas({ filtros }: { filtros: string }) {
  const puedeCrear = usePuede('matriculas.crear');
  const puedeExportar = usePuede('matriculas.exportar');
  const { descargar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [exportando, setExportando] = useState(false);

  async function exportar() {
    setExportando(true);
    const resultado = await descargar(`academico/matriculas/exportar${filtros}`);
    setExportando(false);
    if (resultado.ok) mostrar('exito', 'Exportación generada con los filtros aplicados.');
    else mostrar('error', resultado.error.message);
  }

  return (
    <>
      {puedeExportar ? (
        <Boton variante="secundario" icono={<Download className="h-4 w-4" aria-hidden="true" />} cargando={exportando} onClick={exportar}>
          Exportar CSV
        </Boton>
      ) : null}
      {puedeCrear ? (
        <Link href={`${LISTADO}/nueva`} className={`${ENLACE} bg-academico-700 text-white hover:bg-academico-800`}>
          <Plus className="h-4 w-4" aria-hidden="true" /> Nueva matrícula
        </Link>
      ) : null}
    </>
  );
}

type Operacion = 'anular' | 'eliminar' | 'restaurar';

const TEXTOS: Record<Operacion, { titulo: string; texto: string; confirmar: string; peligro: boolean }> = {
  anular: {
    titulo: '¿Anular la matrícula?',
    texto: 'La matrícula pasará a «Anulada» y su cupo quedará libre. Queda registrada en el historial.',
    confirmar: 'Anular matrícula',
    peligro: true,
  },
  eliminar: {
    titulo: '¿Eliminar la matrícula?',
    texto: 'Dejará de aparecer en los listados y, si estaba vigente, liberará su cupo. Puede restaurarse después si hay cupo disponible.',
    confirmar: 'Eliminar',
    peligro: true,
  },
  restaurar: {
    titulo: '¿Restaurar la matrícula?',
    texto: 'Si no está anulada volverá a ocupar un cupo; se comprueban de nuevo el cupo, los duplicados y que estudiante y curso sigan activos.',
    confirmar: 'Restaurar',
    peligro: false,
  },
};

/** Acciones de la ficha de una matrícula. Laravel comprueba permiso y reglas de cada una bajo bloqueo. */
export function AccionesMatricula({ matricula }: { matricula: MatriculaDetalle }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puedeEditar = usePuede('matriculas.editar');
  const puedeAnular = usePuede('matriculas.anular');
  const puedeEliminar = usePuede('matriculas.eliminar');
  const puedeRestaurar = usePuede('matriculas.restaurar');
  const [operacion, setOperacion] = useState<Operacion | null>(null);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function confirmar() {
    if (operacion === null) return;
    setOcupado(true);
    setError(null);
    const resultado = operacion === 'eliminar'
      ? await ejecutar<{ message: string }>(`academico/matriculas/${matricula.id}`, 'DELETE')
      : await ejecutar<{ message: string }>(`academico/matriculas/${matricula.id}/${operacion}`, 'POST');
    setOcupado(false);

    if (!resultado.ok) {
      // Errores de regla (cupo, duplicado…) llegan como validación con el detalle por campo.
      const detalle = Object.values(resultado.error.errors ?? {})[0]?.[0];
      setError(detalle ?? resultado.error.message);
      return;
    }
    setOperacion(null);
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  const textos = operacion ? TEXTOS[operacion] : null;

  return (
    <>
      {matricula.eliminado ? (
        puedeRestaurar ? (
          <Boton variante="secundario" icono={<ArchiveRestore className="h-4 w-4" aria-hidden="true" />} onClick={() => setOperacion('restaurar')}>
            Restaurar
          </Boton>
        ) : null
      ) : (
        <>
          {puedeEditar ? (
            <Link href={`${LISTADO}/${matricula.id}/editar`} className={`${ENLACE} border border-panel-border bg-panel-surface text-panel-text hover:bg-academico-50`}>
              <Pencil className="h-4 w-4" aria-hidden="true" /> Editar
            </Link>
          ) : null}
          {puedeAnular && matricula.estado !== 'anulada' ? (
            <Boton variante="secundario" icono={<Ban className="h-4 w-4" aria-hidden="true" />} onClick={() => setOperacion('anular')}>
              Anular
            </Boton>
          ) : null}
          {puedeEliminar ? (
            <Boton variante="secundario" icono={<Trash2 className="h-4 w-4" aria-hidden="true" />} onClick={() => setOperacion('eliminar')}>
              Eliminar
            </Boton>
          ) : null}
        </>
      )}

      <DialogoConfirmacion
        abierto={operacion !== null}
        titulo={textos?.titulo ?? ''}
        texto={<p>{textos?.texto}</p>}
        confirmar={textos?.confirmar ?? ''}
        peligro={textos?.peligro}
        cargando={ocupado}
        error={error}
        onConfirmar={confirmar}
        onCerrar={() => {
          setOperacion(null);
          setError(null);
        }}
      />
    </>
  );
}
