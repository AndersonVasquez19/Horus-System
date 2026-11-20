'use client';

import { ArchiveRestore, Ban, Copy, Download, FileText, MessageCircle, Pencil, Plus, Trash2 } from 'lucide-react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { CampoArea } from '@/components/shared/ui/campo';
import { Dialogo, DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import { MenuFila, type ElementoMenuFila } from '@/components/shared/ui/menu-fila';
import type { MatriculaDetalle } from '@/features/academico/tipos';
import { copiarAlPortapapeles, enlaceWaMe, mensajeBienvenidaMatricula } from '@/lib/panel/whatsapp-plantillas';

const LISTADO = '/admin/academico/matriculas';
const ENLACE = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold outline-offset-2 transition focus-visible:outline-2 focus-visible:outline-academico-700';

/** Acciones de cabecera del listado: nueva matrícula y exportación CSV (cada una con su permiso). */
export function AccionesListadoMatriculas({ filtros, mostrarNueva = true }: { filtros: string; mostrarNueva?: boolean }) {
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
      {mostrarNueva && puedeCrear ? (
        <Link href={`${LISTADO}/nueva`} className={`${ENLACE} bg-academico-700 text-white hover:bg-academico-800`}>
          <Plus className="h-4 w-4" aria-hidden="true" /> Nueva matrícula
        </Link>
      ) : null}
    </>
  );
}

export type Operacion = 'anular' | 'eliminar' | 'restaurar';

const TEXTOS: Record<Operacion, { titulo: string; texto: string; confirmar: string; peligro: boolean }> = {
  anular: {
    titulo: 'Anular matrícula',
    texto: 'La matrícula pasará a «Anulada» y su cupo quedará libre. Indica el motivo de anulación (obligatorio).',
    confirmar: 'Anular matrícula',
    peligro: true,
  },
  eliminar: {
    titulo: 'Eliminar matrícula',
    texto: 'Dejará de aparecer en los listados y, si estaba vigente, liberará su cupo. Indica el motivo (obligatorio).',
    confirmar: 'Eliminar matrícula',
    peligro: true,
  },
  restaurar: {
    titulo: '¿Restaurar la matrícula?',
    texto: 'Si no está anulada volverá a ocupar un cupo; se comprueban de nuevo el cupo, los duplicados y que estudiante y curso sigan activos.',
    confirmar: 'Restaurar',
    peligro: false,
  },
};

/**
 * Diálogo de anulación, eliminación (ambas con motivo obligatorio) o restauración de una matrícula. Lo comparten la ficha y el
 * menú ⋯ del listado. `operacion = null` lo mantiene cerrado.
 */
export function OperacionMatricula({ matriculaId, operacion, onCerrar }: { matriculaId: number; operacion: Operacion | null; onCerrar: () => void }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const [motivo, setMotivo] = useState('');
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const cerrar = () => {
    setMotivo('');
    setError(null);
    onCerrar();
  };

  async function confirmarConMotivo(evento?: FormEvent<HTMLFormElement>) {
    evento?.preventDefault();
    if (operacion === null) return;
    if (operacion !== 'restaurar' && motivo.trim().length < 3) {
      setError('Indica un motivo de al menos 3 caracteres.');
      return;
    }
    setOcupado(true);
    setError(null);
    const cuerpo = operacion === 'restaurar' ? undefined : { motivo: motivo.trim() };
    const resultado = operacion === 'eliminar'
      ? await ejecutar<{ message: string }>(`academico/matriculas/${matriculaId}`, 'DELETE', cuerpo)
      : await ejecutar<{ message: string }>(`academico/matriculas/${matriculaId}/${operacion}`, 'POST', cuerpo);
    setOcupado(false);

    if (!resultado.ok) {
      const detalle = Object.values(resultado.error.errors ?? {})[0]?.[0];
      setError(detalle ?? resultado.error.message);
      return;
    }
    cerrar();
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  const textos = operacion ? TEXTOS[operacion] : null;

  return (
    <>
      <Dialogo
        abierto={operacion === 'anular' || operacion === 'eliminar'}
        titulo={textos?.titulo ?? ''}
        descripcion={textos?.texto}
        ancho="sm"
        bloqueado={ocupado}
        onCerrar={cerrar}
      >
        <form onSubmit={confirmarConMotivo} noValidate className="space-y-4">
          <CampoArea
            etiqueta="Motivo"
            rows={3}
            minLength={3}
            maxLength={200}
            value={motivo}
            onChange={(e) => setMotivo(e.target.value)}
            placeholder="Describe el motivo (entre 3 y 200 caracteres)"
            required
          />
          {error ? <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-estado-error">{error}</p> : null}
          <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Boton variante="secundario" onClick={cerrar} disabled={ocupado}>Cancelar</Boton>
            <Boton type="submit" variante="peligro" cargando={ocupado}>{textos?.confirmar ?? 'Confirmar'}</Boton>
          </div>
        </form>
      </Dialogo>

      <DialogoConfirmacion
        abierto={operacion === 'restaurar'}
        titulo={textos?.titulo ?? ''}
        texto={<p>{textos?.texto}</p>}
        confirmar={textos?.confirmar ?? ''}
        peligro={false}
        cargando={ocupado}
        error={error}
        onConfirmar={() => void confirmarConMotivo()}
        onCerrar={cerrar}
      />
    </>
  );
}

/** Menú ⋯ de una fila del listado: los elementos de contacto y navegación (serializables) más anular, eliminar o restaurar según permisos. */
export function MenuFilaMatricula({ matricula, elementos }: { matricula: { id: number; estado: string; eliminado: boolean }; elementos: readonly ElementoMenuFila[] }) {
  const puedeAnular = usePuede('matriculas.anular');
  const puedeEliminar = usePuede('matriculas.eliminar');
  const puedeRestaurar = usePuede('matriculas.restaurar');
  const [operacion, setOperacion] = useState<Operacion | null>(null);

  const propios: ElementoMenuFila[] = matricula.eliminado
    ? puedeRestaurar ? [{ tipo: 'accion', etiqueta: 'Restaurar matrícula', onClick: () => setOperacion('restaurar') }] : []
    : [
        ...(puedeAnular && matricula.estado !== 'anulada' ? [{ tipo: 'accion' as const, etiqueta: 'Anular matrícula', peligro: true, onClick: () => setOperacion('anular') }] : []),
        ...(puedeEliminar ? [{ tipo: 'accion' as const, etiqueta: 'Eliminar matrícula', peligro: true, onClick: () => setOperacion('eliminar') }] : []),
      ];

  return (
    <>
      <MenuFila etiqueta={`Acciones para matrícula #${matricula.id}`} elementos={[...elementos, ...propios]} />
      <OperacionMatricula matriculaId={matricula.id} operacion={operacion} onCerrar={() => setOperacion(null)} />
    </>
  );
}

/** Acciones de la ficha de una matrícula: constancia PDF, edición, anulación/eliminación con motivo obligatorio y restauración. */
export function AccionesMatricula({ matricula }: { matricula: MatriculaDetalle }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { descargar, ejecutar } = useOperacionPanel();
  const puedeVer = usePuede('matriculas.ver');
  const puedeEditar = usePuede('matriculas.editar');
  const puedeAnular = usePuede('matriculas.anular');
  const puedeEliminar = usePuede('matriculas.eliminar');
  const puedeRestaurar = usePuede('matriculas.restaurar');
  const [operacion, setOperacion] = useState<Operacion | null>(null);
  const [descargandoConstancia, setDescargandoConstancia] = useState(false);
  const [etiquetaActual, setEtiquetaActual] = useState(matricula.etiqueta ?? '');

  async function descargarConstancia() {
    setDescargandoConstancia(true);
    const resultado = await descargar(`academico/matriculas/${matricula.id}/constancia`, 'GET');
    setDescargandoConstancia(false);
    if (resultado.ok) mostrar('exito', 'Constancia descargada en PDF.');
    else mostrar('error', resultado.error.message);
  }

  const textoBienvenida = mensajeBienvenidaMatricula({
    estudiante: matricula.estudiante?.nombre ?? 'estudiante',
    curso: matricula.curso?.nombre ?? 'curso',
    edicion: matricula.convocatoria?.codigo,
    enlaceGrupo: matricula.curso?.enlace_grupo_whatsapp,
  });
  const urlWa = enlaceWaMe(matricula.estudiante?.telefono, textoBienvenida);

  return (
    <>
      {puedeVer ? (
        <>
          <Boton
            variante="secundario"
            icono={<FileText className="h-4 w-4" aria-hidden="true" />}
            cargando={descargandoConstancia}
            onClick={descargarConstancia}
          >
            Constancia PDF
          </Boton>
          <Boton
            variante="fantasma"
            icono={<Copy className="h-4 w-4" aria-hidden="true" />}
            onClick={async () => {
              const ok = await copiarAlPortapapeles(textoBienvenida);
              mostrar(ok ? 'exito' : 'error', ok ? 'Mensaje de bienvenida copiado para WhatsApp.' : 'No se pudo copiar.');
            }}
          >
            Copiar bienvenida
          </Boton>
          {urlWa ? (
            <a
              href={urlWa}
              target="_blank"
              rel="noopener noreferrer"
              className={`${ENLACE} border border-emerald-600/30 bg-emerald-50 text-emerald-800 hover:bg-emerald-100`}
            >
              <MessageCircle className="h-4 w-4" aria-hidden="true" /> Enviar WhatsApp
            </a>
          ) : null}
          {matricula.convocatoria ? (
            <Boton
              variante="fantasma"
              icono={<Copy className="h-4 w-4" aria-hidden="true" />}
              onClick={async () => {
                const r = await ejecutar<{ data: { identificador: string } }>(`academico/matriculas/${matricula.id}/encuesta`, 'POST', {});
                if (!r.ok) {
                  mostrar('error', r.error.message);
                  return;
                }
                const url = `${typeof window !== 'undefined' ? window.location.origin : ''}/encuesta/${r.datos.data.identificador}`;
                const ok = await copiarAlPortapapeles(url);
                mostrar(ok ? 'exito' : 'error', ok ? 'Enlace de encuesta copiado.' : 'No se pudo copiar.');
              }}
            >
              Copiar encuesta
            </Boton>
          ) : null}
          {puedeEditar ? (
            <select
              aria-label="Etiqueta operativa"
              value={etiquetaActual}
              onChange={async (e) => {
                const valor = e.target.value;
                setEtiquetaActual(valor);
                const r = await ejecutar<{ message: string }>(`academico/matriculas/${matricula.id}/etiqueta`, 'POST', { etiqueta: valor || null });
                if (r.ok) {
                  mostrar('exito', r.datos.message);
                  router.refresh();
                } else {
                  mostrar('error', r.error.message);
                }
              }}
              className="rounded-lg border border-panel-border bg-panel-surface px-2.5 py-2 text-sm text-panel-text"
            >
              <option value="">Sin etiqueta</option>
              <option value="factura">Requiere factura</option>
              <option value="becado">Becado</option>
              <option value="seguimiento">Seguimiento</option>
              <option value="empresa">Empresa</option>
            </select>
          ) : null}
        </>
      ) : null}

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

      <OperacionMatricula matriculaId={matricula.id} operacion={operacion} onCerrar={() => setOperacion(null)} />
    </>
  );
}
