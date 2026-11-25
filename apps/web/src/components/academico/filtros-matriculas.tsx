'use client';

import { ChevronDown, Search, SlidersHorizontal, Trash2, X } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useId, useMemo, useState, useTransition, type FormEvent } from 'react';

export interface ConvocatoriaFiltro {
  identificador: string;
  codigo: string;
  nombre: string | null;
}

export interface FiltrosMatriculasValores {
  busqueda: string;
  estado: string;
  convocatoria: string;
  estado_cobro: string;
  curso_id: string;
  eliminados: string;
  sort: string;
  per_page: string;
}

interface FiltrosMatriculasProps {
  ruta: string;
  valores: FiltrosMatriculasValores;
  convocatorias: readonly ConvocatoriaFiltro[];
  /** Nombre legible del curso cuando se llegó filtrado por `?curso_id=`. */
  cursoFiltradoNombre?: string | null;
}

const ESTADOS_COBRO: Record<string, string> = {
  con_saldo: 'Con saldo pendiente',
  pendiente: 'Pendiente (sin abonos)',
  parcial: 'Abono parcial',
  pagada: 'Pagada',
  anulada: 'Obligación anulada',
  sin_obligacion: 'Sin obligación',
};

const ORDENES: Record<string, string> = {
  '-fecha': 'Más recientes',
  fecha: 'Más antiguas',
  '-creado': 'Registradas últimamente',
  estado: 'Estado',
};

const PESTANAS_ESTADO = [
  { clave: 'todas', etiqueta: 'Todas' },
  { clave: 'pendiente', etiqueta: 'Pendientes' },
  { clave: 'pagada', etiqueta: 'Pagadas' },
  { clave: 'anulada', etiqueta: 'Anuladas' },
] as const;

const CLASE_SELECT =
  'h-10 rounded-lg border border-panel-border bg-panel-surface px-3 text-sm text-panel-text transition hover:border-academico-300 focus:border-academico-700 focus:outline-none focus:ring-4 focus:ring-academico-700/20';

function construirUrl(ruta: string, valores: FiltrosMatriculasValores): string {
  const qs = new URLSearchParams();
  if (valores.busqueda.trim() !== '') qs.set('busqueda', valores.busqueda.trim());
  if (valores.eliminados === '1') {
    qs.set('eliminados', '1');
  } else if (valores.estado !== '') {
    qs.set('estado', valores.estado);
  }
  if (valores.convocatoria !== '') qs.set('convocatoria', valores.convocatoria);
  if (valores.estado_cobro !== '') qs.set('estado_cobro', valores.estado_cobro);
  if (valores.curso_id !== '') qs.set('curso_id', valores.curso_id);
  if (valores.sort !== '' && valores.sort !== '-fecha') qs.set('sort', valores.sort);
  if (valores.per_page !== '' && valores.per_page !== '20') qs.set('per_page', valores.per_page);
  const texto = qs.toString();
  return texto ? `${ruta}?${texto}` : ruta;
}

/**
 * Controles de filtrado de Matrículas en dos niveles:
 *  1. Pestañas rápidas de estado (Todas · Pendientes · Pagadas · Anuladas | Eliminadas).
 *  2. Buscador amplio con debounce + 3 selectores auto-aplicables (Edición, Cobro, Orden) + chips activos.
 */
export function FiltrosMatriculas({ ruta, valores, convocatorias, cursoFiltradoNombre }: FiltrosMatriculasProps) {
  const router = useRouter();
  const [pendiente, iniciarTransicion] = useTransition();
  const [textoBusqueda, setTextoBusqueda] = useState(valores.busqueda);
  const [filtrosMovilAbiertos, setFiltrosMovilAbiertos] = useState(false);
  const idFiltrosMovil = useId();
  const idBusqueda = useId();
  const idConvocatoria = useId();
  const idCobro = useId();
  const idOrden = useId();

  // Sincroniza el campo de búsqueda cuando la URL cambia externamente (p. ej. al pulsar «Limpiar filtros»).
  const [busquedaPropPrevia, setBusquedaPropPrevia] = useState(valores.busqueda);
  if (valores.busqueda !== busquedaPropPrevia) {
    setBusquedaPropPrevia(valores.busqueda);
    setTextoBusqueda(valores.busqueda);
  }

  const navegar = useCallback(
    (cambios: Partial<FiltrosMatriculasValores>) => {
      const siguiente: FiltrosMatriculasValores = { ...valores, ...cambios };
      iniciarTransicion(() => {
        router.push(construirUrl(ruta, siguiente));
      });
    },
    [router, ruta, valores],
  );

  // Debounce suave solo cuando el usuario escribe en el buscador.
  useEffect(() => {
    const limpio = textoBusqueda.trim();
    if (limpio === valores.busqueda.trim()) return undefined;
    const temporizador = window.setTimeout(() => {
      navegar({ busqueda: limpio });
    }, 380);
    return () => window.clearTimeout(temporizador);
  }, [textoBusqueda, valores.busqueda, navegar]);

  function enviarBusqueda(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    navegar({ busqueda: textoBusqueda.trim() });
  }

  const esEliminadas = valores.eliminados === '1';
  const pestanaActiva = esEliminadas ? 'eliminadas' : valores.estado || 'todas';

  const etiquetaEdicion = useMemo(() => {
    if (valores.convocatoria === 'sin_convocatoria') return 'Sin edición';
    const encontrada = convocatorias.find((c) => c.identificador === valores.convocatoria);
    if (!encontrada) return valores.convocatoria;
    return encontrada.nombre ? `${encontrada.codigo} — ${encontrada.nombre}` : encontrada.codigo;
  }, [valores.convocatoria, convocatorias]);

  const chips = useMemo(() => {
    const lista: { clave: string; etiqueta: string; limpiar: () => void }[] = [];
    if (valores.busqueda.trim() !== '') {
      lista.push({
        clave: 'busqueda',
        etiqueta: `Búsqueda: «${valores.busqueda.trim()}»`,
        limpiar: () => {
          setTextoBusqueda('');
          navegar({ busqueda: '' });
        },
      });
    }
    if (valores.curso_id !== '') {
      lista.push({
        clave: 'curso_id',
        etiqueta: cursoFiltradoNombre ? `Curso: ${cursoFiltradoNombre}` : `Curso #${valores.curso_id}`,
        limpiar: () => navegar({ curso_id: '' }),
      });
    }
    if (valores.convocatoria !== '') {
      lista.push({
        clave: 'convocatoria',
        etiqueta: `Edición: ${etiquetaEdicion}`,
        limpiar: () => navegar({ convocatoria: '' }),
      });
    }
    if (valores.estado_cobro !== '') {
      lista.push({
        clave: 'estado_cobro',
        etiqueta: `Cobro: ${ESTADOS_COBRO[valores.estado_cobro] ?? valores.estado_cobro}`,
        limpiar: () => navegar({ estado_cobro: '' }),
      });
    }
    return lista;
  }, [valores.busqueda, valores.curso_id, valores.convocatoria, valores.estado_cobro, cursoFiltradoNombre, etiquetaEdicion, navegar]);

  const filtrosSecundariosActivos = [valores.convocatoria, valores.estado_cobro].filter(Boolean).length;
  const hayAlgunFiltro = chips.length > 0 || pestanaActiva !== 'todas';

  return (
    <div className="space-y-3 print:hidden" aria-busy={pendiente || undefined}>
      {/* Nivel 1: pestañas rápidas de estado */}
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-panel-border pb-2.5">
        <nav aria-label="Filtrar por estado de matrícula" className="flex flex-wrap items-center gap-1">
          {PESTANAS_ESTADO.map((p) => {
            const activa = pestanaActiva === p.clave;
            return (
              <button
                key={p.clave}
                type="button"
                aria-pressed={activa}
                onClick={() => navegar({ estado: p.clave === 'todas' ? '' : p.clave, eliminados: '' })}
                className={`inline-flex h-9 items-center rounded-lg px-3.5 text-sm font-medium transition cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-academico-700 ${
                  activa
                    ? 'bg-academico-700 font-semibold text-white shadow-xs'
                    : 'text-panel-muted hover:bg-panel-surface hover:text-panel-text'
                }`}
              >
                {p.etiqueta}
              </button>
            );
          })}
        </nav>

        <button
          type="button"
          aria-pressed={esEliminadas}
          onClick={() => navegar(esEliminadas ? { eliminados: '', estado: '' } : { eliminados: '1', estado: '' })}
          className={`inline-flex h-9 items-center gap-1.5 rounded-lg border px-3 text-sm font-medium transition cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-academico-700 ${
            esEliminadas
              ? 'border-red-200 bg-red-50 font-semibold text-estado-error'
              : 'border-panel-border bg-panel-surface text-panel-muted hover:border-red-200 hover:bg-red-50/50 hover:text-estado-error'
          }`}
        >
          <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
          Eliminadas
        </button>
      </div>

      {/* Nivel 2: buscador + filtros secundarios auto-aplicables */}
      <div className="rounded-xl border border-panel-border bg-panel-surface p-3.5 shadow-xs">
        <div className="flex flex-col gap-3 md:flex-row md:items-end">
          <form onSubmit={enviarBusqueda} className="min-w-0 flex-1" role="search">
            <label htmlFor={idBusqueda} className="mb-1 block text-xs font-medium text-panel-muted">
              Buscar matrícula
            </label>
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-panel-muted" aria-hidden="true" />
              <input
                id={idBusqueda}
                type="search"
                value={textoBusqueda}
                onChange={(e) => setTextoBusqueda(e.target.value)}
                placeholder="# matrícula, DNI, estudiante o curso"
                maxLength={200}
                className="h-10 w-full rounded-lg border border-panel-border bg-panel-surface pl-9 pr-9 text-sm text-panel-text transition placeholder:text-panel-muted hover:border-academico-300 focus:border-academico-700 focus:outline-none focus:ring-4 focus:ring-academico-700/20"
              />
              {textoBusqueda !== '' ? (
                <button
                  type="button"
                  onClick={() => {
                    setTextoBusqueda('');
                    navegar({ busqueda: '' });
                  }}
                  aria-label="Limpiar búsqueda"
                  className="absolute right-1.5 top-1/2 inline-flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-md text-panel-muted transition hover:bg-panel-bg hover:text-panel-text cursor-pointer"
                >
                  <X className="h-3.5 w-3.5" aria-hidden="true" />
                </button>
              ) : null}
            </div>
          </form>

          {/* Disparador móvil para los selectores secundarios */}
          <button
            type="button"
            onClick={() => setFiltrosMovilAbiertos((v) => !v)}
            aria-expanded={filtrosMovilAbiertos}
            aria-controls={idFiltrosMovil}
            className="flex h-10 items-center justify-between rounded-lg border border-panel-border bg-panel-bg px-3 text-sm font-medium text-panel-text md:hidden"
          >
            <span className="inline-flex items-center gap-2">
              <SlidersHorizontal className="h-4 w-4 text-panel-muted" aria-hidden="true" />
              Más filtros
              {filtrosSecundariosActivos > 0 ? (
                <span className="rounded-full bg-academico-700 px-2 py-0.5 text-xs font-semibold text-white">
                  {filtrosSecundariosActivos}
                </span>
              ) : null}
            </span>
            <ChevronDown className={`h-4 w-4 text-panel-muted transition-transform ${filtrosMovilAbiertos ? 'rotate-180' : ''}`} aria-hidden="true" />
          </button>

          <div id={idFiltrosMovil} className={`${filtrosMovilAbiertos ? 'grid' : 'hidden'} grid-cols-1 gap-3 sm:grid-cols-3 md:flex md:items-end`}>
            <div className="min-w-0 md:w-44 lg:w-48">
              <label htmlFor={idConvocatoria} className="mb-1 block text-xs font-medium text-panel-muted">
                Edición
              </label>
              <select
                id={idConvocatoria}
                value={valores.convocatoria}
                onChange={(e) => navegar({ convocatoria: e.target.value })}
                className={`${CLASE_SELECT} w-full`}
              >
                <option value="">Todas las ediciones</option>
                <option value="sin_convocatoria">Sin edición</option>
                {convocatorias.map((c) => (
                  <option key={c.identificador} value={c.identificador}>
                    {c.nombre ? `${c.codigo} — ${c.nombre}` : c.codigo}
                  </option>
                ))}
              </select>
            </div>

            <div className="min-w-0 md:w-44">
              <label htmlFor={idCobro} className="mb-1 block text-xs font-medium text-panel-muted">
                Cobro
              </label>
              <select
                id={idCobro}
                value={valores.estado_cobro}
                onChange={(e) => navegar({ estado_cobro: e.target.value })}
                className={`${CLASE_SELECT} w-full`}
              >
                <option value="">Todos los cobros</option>
                {Object.entries(ESTADOS_COBRO).map(([clave, texto]) => (
                  <option key={clave} value={clave}>
                    {texto}
                  </option>
                ))}
              </select>
            </div>

            <div className="min-w-0 md:w-44">
              <label htmlFor={idOrden} className="mb-1 block text-xs font-medium text-panel-muted">
                Ordenar por
              </label>
              <select
                id={idOrden}
                value={valores.sort || '-fecha'}
                onChange={(e) => navegar({ sort: e.target.value })}
                className={`${CLASE_SELECT} w-full`}
              >
                {Object.entries(ORDENES).map(([clave, texto]) => (
                  <option key={clave} value={clave}>
                    {texto}
                  </option>
                ))}
              </select>
            </div>
          </div>
        </div>

        {/* Chips removibles de filtros activos */}
        {chips.length > 0 ? (
          <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-panel-border/80 pt-3">
            <span className="text-xs font-medium text-panel-muted">Filtros activos:</span>
            {chips.map((chip) => (
              <span
                key={chip.clave}
                className="inline-flex items-center gap-1 rounded-full border border-academico-200 bg-academico-50 py-0.5 pl-2.5 pr-1 text-xs font-medium text-academico-800"
              >
                <span className="max-w-[220px] truncate">{chip.etiqueta}</span>
                <button
                  type="button"
                  onClick={chip.limpiar}
                  aria-label={`Quitar filtro ${chip.etiqueta}`}
                  className="inline-flex h-5 w-5 items-center justify-center rounded-full text-academico-700 transition hover:bg-academico-200/70 cursor-pointer"
                >
                  <X className="h-3 w-3" aria-hidden="true" />
                </button>
              </span>
            ))}
            {hayAlgunFiltro ? (
              <button
                type="button"
                onClick={() => {
                  setTextoBusqueda('');
                  iniciarTransicion(() => {
                    router.push(ruta);
                  });
                }}
                className="ml-auto text-xs font-semibold text-academico-700 transition hover:text-academico-900 hover:underline cursor-pointer"
              >
                Limpiar todo
              </button>
            ) : null}
          </div>
        ) : null}
      </div>
    </div>
  );
}

/** Control compacto de filas por página pensado para vivir en el pie de tabla, junto a la paginación. */
export function SelectorPorPaginaPie({ ruta, valores }: { ruta: string; valores: FiltrosMatriculasValores }) {
  const router = useRouter();
  const id = useId();
  const actual = ['20', '50', '100'].includes(valores.per_page) ? valores.per_page : '20';

  return (
    <div className="inline-flex items-center gap-2 text-sm text-panel-muted">
      <label htmlFor={id} className="whitespace-nowrap">
        Filas:
      </label>
      <select
        id={id}
        value={actual}
        onChange={(e) => {
          router.push(construirUrl(ruta, { ...valores, per_page: e.target.value }));
        }}
        className="h-9 rounded-lg border border-panel-border bg-panel-surface px-2.5 text-sm font-medium text-panel-text transition hover:border-academico-300 focus:border-academico-700 focus:outline-none focus:ring-4 focus:ring-academico-700/20"
      >
        <option value="20">20</option>
        <option value="50">50</option>
        <option value="100">100</option>
      </select>
    </div>
  );
}
