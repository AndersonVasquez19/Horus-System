'use client';

import {
  ArchiveRestore,
  Ban,
  Copy,
  Download,
  ExternalLink,
  Eye,
  FileText,
  Mail,
  MessageCircle,
  Pencil,
  Phone,
  Plus,
  Receipt,
  Trash2,
} from 'lucide-react';
import Link from 'next/link';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useCallback, useState, type FormEvent, type ReactNode } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { BotonCopiarDato, EnlaceAccionFila } from '@/components/shared/ui/acciones-celda';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { CampoArea } from '@/components/shared/ui/campo';
import { Dialogo, DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import { Insignia } from '@/components/shared/ui/insignia';
import { MenuFila, type ElementoMenuFila } from '@/components/shared/ui/menu-fila';
import type { MatriculaDetalle } from '@/features/academico/tipos';
import { esEnlaceGrupoWhatsApp, urlMailto, urlWhatsApp } from '@/lib/panel/contacto';
import { copiarAlPortapapeles, mensajeBienvenidaMatricula } from '@/lib/panel/whatsapp-plantillas';
import { ModalEditarMatricula, ModalNuevaMatricula } from './modal-matricula';

const LISTADO = '/admin/academico/matriculas';

export const ETIQUETAS_OPERATIVAS: Record<string, string> = {
  factura: 'Requiere factura',
  becado: 'Becado',
  seguimiento: 'Seguimiento',
  empresa: 'Empresa',
};

/**
 * Acciones de cabecera del listado: exportación CSV y «Nueva matrícula» (abre modal centrado amplio).
 * Si se llega desde un acceso externo redirigido (`?nueva=1&estudiante_id=...&curso_id=...`), abre el modal
 * automáticamente con la preselección y limpia la URL al cerrarlo.
 */
export function AccionesListadoMatriculas({
  filtros,
  mostrarNueva = true,
  abrirNuevaInicial = false,
  estudianteInicialId = null,
  cursoInicialId = null,
}: {
  filtros: string;
  mostrarNueva?: boolean;
  abrirNuevaInicial?: boolean;
  estudianteInicialId?: string | null;
  cursoInicialId?: string | null;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const puedeCrear = usePuede('matriculas.crear');
  const puedeExportar = usePuede('matriculas.exportar');
  const { descargar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [exportando, setExportando] = useState(false);
  const [modalNueva, setModalNueva] = useState(abrirNuevaInicial && mostrarNueva);
  const abiertoPorUrl = mostrarNueva && puedeCrear && searchParams.get('nueva') === '1';
  const modalAbierto = modalNueva || abiertoPorUrl;

  const cerrarModalNueva = useCallback(() => {
    setModalNueva(false);
    if (searchParams.has('nueva') || searchParams.has('estudiante_id') || searchParams.has('pre_curso_id')) {
      const qs = new URLSearchParams(searchParams.toString());
      qs.delete('nueva');
      qs.delete('estudiante_id');
      qs.delete('pre_curso_id');
      const destino = qs.toString() ? `${pathname}?${qs.toString()}` : pathname;
      router.replace(destino, { scroll: false });
    }
  }, [pathname, router, searchParams]);

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
        <Boton icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setModalNueva(true)}>
          Nueva matrícula
        </Boton>
      ) : null}
      {mostrarNueva && puedeCrear ? (
        <ModalNuevaMatricula
          abierto={modalAbierto}
          onCerrar={cerrarModalNueva}
          estudianteInicialId={searchParams.get('estudiante_id') ?? estudianteInicialId}
          cursoInicialId={searchParams.get('pre_curso_id') ?? cursoInicialId}
        />
      ) : null}
    </>
  );
}

/** Botón CTA del estado vacío del listado: abre el mismo modal centrado de nueva matrícula. */
export function BotonNuevaMatriculaVacio() {
  const puedeCrear = usePuede('matriculas.crear');
  const [abierto, setAbierto] = useState(false);

  if (!puedeCrear) return null;

  return (
    <>
      <Boton icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>
        Registrar primera matrícula
      </Boton>
      <ModalNuevaMatricula abierto={abierto} onCerrar={() => setAbierto(false)} />
    </>
  );
}

/** Botón de 1 clic para regularizar matrículas antiguas sin obligación de cobro. */
export function BotonGenerarCobro({ matriculaId }: { matriculaId: number }) {
  const router = useRouter();
  const puedeRegistrar = usePuede('pagos.registrar');
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [cargando, setCargando] = useState(false);

  if (!puedeRegistrar) return <span className="text-xs text-panel-muted">Sin obligación</span>;

  async function generar() {
    setCargando(true);
    const r = await ejecutar<{ message?: string }>(`academico/matriculas/${matriculaId}/obligacion/generar`, 'POST');
    setCargando(false);
    if (!r.ok) {
      mostrar('error', r.error.message);
      return;
    }
    mostrar('exito', r.datos?.message || 'Obligación generada. Ya aparece en Pendientes y deudores.');
    router.refresh();
  }

  return (
    <button
      type="button"
      onClick={generar}
      disabled={cargando}
      className="inline-flex items-center gap-1 rounded-md border border-academico-300 bg-academico-50 px-2.5 py-1 text-xs font-semibold text-academico-800 transition hover:bg-academico-100 disabled:opacity-50 cursor-pointer"
    >
      <Receipt className="h-3.5 w-3.5" aria-hidden="true" />
      {cargando ? 'Generando…' : 'Generar cobro'}
    </button>
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
 * Diálogo de anulación, eliminación (ambas con motivo obligatorio) o restauración de una matrícula.
 * Compartido entre el menú del listado y la ficha de detalle.
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
          <div className="pie-acciones">
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

/**
 * Acciones por fila en el listado de matrículas:
 *  - Acción directa «Ver» (estándar `EnlaceAccionFila`)
 *  - Menú contextual accesible `⋯` (`MenuFila`) con permisos verificados y edición en modal centrado.
 */
export function MenuFilaMatricula({ matricula }: { matricula: { id: number; estado: string; eliminado: boolean } }) {
  const { mostrar } = useAvisos();
  const { descargar } = useOperacionPanel();
  const puedeVer = usePuede('matriculas.ver');
  const puedeEditar = usePuede('matriculas.editar');
  const puedeAnular = usePuede('matriculas.anular');
  const puedeEliminar = usePuede('matriculas.eliminar');
  const puedeRestaurar = usePuede('matriculas.restaurar');
  const [operacion, setOperacion] = useState<Operacion | null>(null);
  const [editando, setEditando] = useState(false);
  const [descargando, setDescargando] = useState(false);

  async function descargarConstancia() {
    setDescargando(true);
    const resultado = await descargar(`academico/matriculas/${matricula.id}/constancia`, 'GET');
    setDescargando(false);
    if (resultado.ok) mostrar('exito', 'Constancia descargada en PDF.');
    else mostrar('error', resultado.error.message);
  }

  const elementos: ElementoMenuFila[] = [];
  if (!matricula.eliminado && puedeEditar) {
    elementos.push({ tipo: 'accion', etiqueta: 'Editar matrícula', icono: Pencil, onClick: () => setEditando(true) });
  }
  if (puedeVer) {
    elementos.push({ tipo: 'accion', etiqueta: 'Descargar constancia PDF', icono: FileText, onClick: () => void descargarConstancia() });
  }
  if (matricula.eliminado && puedeRestaurar) {
    elementos.push({ tipo: 'accion', etiqueta: 'Restaurar matrícula', icono: ArchiveRestore, onClick: () => setOperacion('restaurar') });
  }
  if (!matricula.eliminado && puedeAnular && matricula.estado !== 'anulada') {
    elementos.push({ tipo: 'accion', etiqueta: 'Anular matrícula', icono: Ban, peligro: true, onClick: () => setOperacion('anular') });
  }
  if (!matricula.eliminado && puedeEliminar) {
    elementos.push({ tipo: 'accion', etiqueta: 'Eliminar matrícula', icono: Trash2, peligro: true, onClick: () => setOperacion('eliminar') });
  }

  return (
    <>
      <div className="inline-flex items-center justify-end gap-1.5">
        <EnlaceAccionFila href={`${LISTADO}/${matricula.id}`} etiqueta="Ver" icono={<Eye className="h-3.5 w-3.5" aria-hidden="true" />} />
        <MenuFila etiqueta={`Más acciones para matrícula #${matricula.id}`} cargando={descargando} elementos={elementos} />
      </div>

      {!matricula.eliminado && puedeEditar ? (
        <ModalEditarMatricula abierto={editando} matriculaId={matricula.id} onCerrar={() => setEditando(false)} />
      ) : null}
      <OperacionMatricula matriculaId={matricula.id} operacion={operacion} onCerrar={() => setOperacion(null)} />
    </>
  );
}

/** Selector inline de etiqueta operativa para la tarjeta «Datos de la matrícula» en la ficha de detalle. */
export function SelectorEtiquetaMatricula({ matriculaId, inicial }: { matriculaId: number; inicial: string }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puedeEditar = usePuede('matriculas.editar');
  const [valor, setValor] = useState(inicial);
  const [guardando, setGuardando] = useState(false);

  if (!puedeEditar) {
    return valor ? <Insignia color="azul">{ETIQUETAS_OPERATIVAS[valor] ?? valor}</Insignia> : <span className="text-panel-muted">Sin etiqueta</span>;
  }

  return (
    <select
      aria-label="Etiqueta operativa"
      value={valor}
      disabled={guardando}
      onChange={async (e) => {
        const nuevo = e.target.value;
        const previo = valor;
        setValor(nuevo);
        setGuardando(true);
        const r = await ejecutar<{ message: string }>(`academico/matriculas/${matriculaId}/etiqueta`, 'POST', { etiqueta: nuevo || null });
        setGuardando(false);
        if (r.ok) {
          mostrar('exito', r.datos.message);
          router.refresh();
        } else {
          setValor(previo);
          mostrar('error', r.error.message);
        }
      }}
      className="h-9 max-w-full rounded-lg border border-panel-border bg-panel-surface px-2.5 text-sm font-medium text-panel-text transition hover:border-academico-300 focus:border-academico-700 focus:outline-none focus:ring-4 focus:ring-academico-700/20 disabled:opacity-60"
    >
      <option value="">Sin etiqueta</option>
      {Object.entries(ETIQUETAS_OPERATIVAS).map(([clave, texto]) => (
        <option key={clave} value={clave}>
          {texto}
        </option>
      ))}
    </select>
  );
}

/**
 * Bloque de estudiante y canales de comunicación de la ficha de matrícula.
 * Evita duplicar WhatsApp en cabecera y presenta acciones uniformes (h-8/h-9) sin saturar el `<dl>`.
 */
export function TarjetaContactoMatricula({
  estudiante,
  mensajeWa,
  asuntoCorreo,
  cuerpoCorreo,
  enlaceGrupo,
}: {
  estudiante: MatriculaDetalle['estudiante'];
  mensajeWa: string;
  asuntoCorreo: string;
  cuerpoCorreo: string;
  enlaceGrupo?: string | null;
}) {
  const { mostrar } = useAvisos();
  const hrefWa = urlWhatsApp(estudiante?.telefono, mensajeWa);
  const hrefMail = urlMailto(estudiante?.email, asuntoCorreo, cuerpoCorreo);
  const grupoValido = esEnlaceGrupoWhatsApp((enlaceGrupo ?? '').trim()) ? (enlaceGrupo ?? '').trim() : null;

  const claseBotonCompacto =
    'inline-flex h-8 items-center justify-center gap-1.5 rounded-lg border border-panel-border bg-panel-surface px-2.5 text-xs font-semibold text-panel-text transition hover:border-academico-300 hover:bg-academico-50 hover:text-academico-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-academico-700 cursor-pointer';

  async function copiarEnlaceGrupo() {
    if (!grupoValido) return;
    const ok = await copiarAlPortapapeles(grupoValido);
    mostrar(ok ? 'exito' : 'error', ok ? 'Enlace del grupo de WhatsApp copiado.' : 'No se pudo copiar el enlace.');
  }

  return (
    <div className="space-y-4">
      {estudiante ? (
        <div className="space-y-3">
          <div>
            <Link
              href={`/admin/academico/estudiantes/${estudiante.id}`}
              className="text-base font-semibold text-panel-text hover:text-academico-800 hover:underline"
            >
              {estudiante.nombre}
            </Link>
            <div className="mt-1 flex items-center gap-1.5">
              <span className="font-mono text-xs text-panel-muted">DNI {estudiante.dni}</span>
              <BotonCopiarDato valor={estudiante.dni} etiqueta={`Copiar DNI ${estudiante.dni}`} />
            </div>
          </div>

          <div className="divide-y divide-panel-border rounded-lg border border-panel-border bg-panel-bg/40">
            <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5">
              <div className="flex min-w-0 items-center gap-2 text-sm">
                <Phone className="h-4 w-4 shrink-0 text-panel-muted" aria-hidden="true" />
                {estudiante.telefono ? (
                  <span className="font-mono text-xs font-medium text-panel-text">{estudiante.telefono}</span>
                ) : (
                  <span className="text-xs text-panel-muted">Sin teléfono</span>
                )}
              </div>
              {estudiante.telefono ? (
                <div className="flex items-center gap-1.5">
                  {hrefWa ? (
                    <a
                      href={hrefWa}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-emerald-600/30 bg-emerald-50 px-2.5 text-xs font-semibold text-emerald-800 transition hover:bg-emerald-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-academico-700"
                    >
                      <MessageCircle className="h-3.5 w-3.5" aria-hidden="true" />
                      WhatsApp
                    </a>
                  ) : null}
                  <BotonCopiarDato valor={estudiante.telefono} etiqueta={`Copiar teléfono ${estudiante.telefono}`} />
                </div>
              ) : null}
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5">
              <div className="flex min-w-0 flex-1 items-center gap-2 text-sm">
                <Mail className="h-4 w-4 shrink-0 text-panel-muted" aria-hidden="true" />
                {estudiante.email ? (
                  <span className="truncate text-xs font-medium text-panel-text" title={estudiante.email}>
                    {estudiante.email}
                  </span>
                ) : (
                  <span className="text-xs text-panel-muted">Sin correo</span>
                )}
              </div>
              {estudiante.email ? (
                <div className="flex items-center gap-1.5">
                  {hrefMail ? (
                    <a href={hrefMail} className={claseBotonCompacto}>
                      <Mail className="h-3.5 w-3.5" aria-hidden="true" />
                      Correo
                    </a>
                  ) : null}
                  <BotonCopiarDato valor={estudiante.email} etiqueta={`Copiar correo ${estudiante.email}`} />
                </div>
              ) : null}
            </div>
          </div>
        </div>
      ) : (
        <p className="text-sm text-panel-muted">Estudiante no disponible.</p>
      )}

      {grupoValido ? (
        <div className="space-y-2 border-t border-panel-border pt-3.5">
          <p className="text-xs font-medium text-panel-muted">Grupo de WhatsApp del curso</p>
          <div className="flex flex-wrap items-center gap-2">
            <a
              href={grupoValido}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-emerald-600/30 bg-emerald-50 px-3 text-xs font-semibold text-emerald-800 transition hover:bg-emerald-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-academico-700"
            >
              <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
              Abrir grupo
            </a>
            <button type="button" onClick={() => void copiarEnlaceGrupo()} className={claseBotonCompacto}>
              <Copy className="h-3.5 w-3.5" aria-hidden="true" />
              Copiar enlace
            </button>
          </div>
        </div>
      ) : null}
    </div>
  );
}

/**
 * Acciones de cabecera de la ficha de matrícula, ordenadas por prioridad operativa:
 *  Constancia PDF → Emitir certificado (si aplica) → Editar/Restaurar (primario) → Más acciones ▾.
 */
export function AccionesMatricula({
  matricula,
  abrirEditarInicial = false,
  accionCertificado,
}: {
  matricula: MatriculaDetalle;
  abrirEditarInicial?: boolean;
  accionCertificado?: ReactNode;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const { mostrar } = useAvisos();
  const { descargar, ejecutar } = useOperacionPanel();
  const puedeVer = usePuede('matriculas.ver');
  const puedeEditar = usePuede('matriculas.editar');
  const puedeAnular = usePuede('matriculas.anular');
  const puedeEliminar = usePuede('matriculas.eliminar');
  const puedeRestaurar = usePuede('matriculas.restaurar');
  const [operacion, setOperacion] = useState<Operacion | null>(null);
  const [editando, setEditando] = useState(abrirEditarInicial && !matricula.eliminado);
  const [descargandoConstancia, setDescargandoConstancia] = useState(false);
  const editarPorUrl = !matricula.eliminado && puedeEditar && searchParams.get('editar') === '1';
  const modalEdicionAbierto = editando || editarPorUrl;

  const cerrarEdicion = useCallback(() => {
    setEditando(false);
    if (searchParams.has('editar')) {
      const qs = new URLSearchParams(searchParams.toString());
      qs.delete('editar');
      const destino = qs.toString() ? `${pathname}?${qs.toString()}` : pathname;
      router.replace(destino, { scroll: false });
    }
  }, [pathname, router, searchParams]);

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

  const masAcciones: ElementoMenuFila[] = [];
  if (puedeVer) {
    masAcciones.push({
      tipo: 'copiar',
      etiqueta: 'Copiar mensaje de bienvenida',
      valor: textoBienvenida,
      aviso: 'Mensaje de bienvenida copiado para WhatsApp.',
      icono: Copy,
    });
    if (matricula.convocatoria) {
      masAcciones.push({
        tipo: 'accion',
        etiqueta: 'Copiar enlace de encuesta',
        icono: Copy,
        onClick: () => {
          void (async () => {
            const r = await ejecutar<{ data: { identificador: string } }>(`academico/matriculas/${matricula.id}/encuesta`, 'POST', {});
            if (!r.ok) {
              mostrar('error', r.error.message);
              return;
            }
            const url = `${typeof window !== 'undefined' ? window.location.origin : ''}/encuesta/${r.datos.data.identificador}`;
            const ok = await copiarAlPortapapeles(url);
            mostrar(ok ? 'exito' : 'error', ok ? 'Enlace de encuesta copiado.' : 'No se pudo copiar.');
          })();
        },
      });
    }
  }
  if (!matricula.eliminado && puedeAnular && matricula.estado !== 'anulada') {
    masAcciones.push({ tipo: 'accion', etiqueta: 'Anular matrícula', icono: Ban, peligro: true, onClick: () => setOperacion('anular') });
  }
  if (!matricula.eliminado && puedeEliminar) {
    masAcciones.push({ tipo: 'accion', etiqueta: 'Eliminar matrícula', icono: Trash2, peligro: true, onClick: () => setOperacion('eliminar') });
  }

  return (
    <>
      {puedeVer ? (
        <Boton
          variante="secundario"
          icono={<FileText className="h-4 w-4" aria-hidden="true" />}
          cargando={descargandoConstancia}
          onClick={descargarConstancia}
        >
          Constancia PDF
        </Boton>
      ) : null}

      {accionCertificado}

      {matricula.eliminado ? (
        puedeRestaurar ? (
          <Boton variante="primario" icono={<ArchiveRestore className="h-4 w-4" aria-hidden="true" />} onClick={() => setOperacion('restaurar')}>
            Restaurar
          </Boton>
        ) : null
      ) : puedeEditar ? (
        <Boton variante="primario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setEditando(true)}>
          Editar
        </Boton>
      ) : null}

      <MenuFila etiqueta="Más acciones de la matrícula" disparador={{ texto: 'Más acciones' }} elementos={masAcciones} />

      {!matricula.eliminado && puedeEditar ? (
        <ModalEditarMatricula abierto={modalEdicionAbierto} matriculaId={matricula.id} matriculaInicial={matricula} onCerrar={cerrarEdicion} />
      ) : null}

      <OperacionMatricula matriculaId={matricula.id} operacion={operacion} onCerrar={() => setOperacion(null)} />
    </>
  );
}
