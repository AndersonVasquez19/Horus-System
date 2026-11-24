'use client';

import { ArrowRight, Award, BookOpen, Calendar, CreditCard, FileBarChart, GraduationCap, LayoutDashboard, Search, UserPlus, Users, X } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { useOperacionPanel } from './use-operacion-panel';

interface ResultadoGrupo {
  estudiantes?: { id: number; texto: string; detalle: string }[];
  cursos?: { id: number; texto: string; detalle: string }[];
  matriculas?: { id: number; texto: string; detalle: string }[];
  ediciones?: { identificador: string; texto: string; detalle: string }[];
  certificados?: { codigo: string; texto: string; detalle: string }[];
  pagos?: { identificador: string; texto: string; detalle: string }[];
}

const ATAJOS = [
  { etiqueta: 'Tablero académico', href: '/admin/academico', palabras: 'tablero inicio resumen', icono: LayoutDashboard },
  { etiqueta: 'Nueva matrícula', href: '/admin/academico/matriculas/nueva', palabras: 'nueva matricula inscribir', icono: UserPlus },
  { etiqueta: 'Matrículas', href: '/admin/academico/matriculas', palabras: 'matriculas inscripciones', icono: GraduationCap },
  { etiqueta: 'Pagos y deudores', href: '/admin/academico/pagos', palabras: 'pagos caja yape cuotas cobros deudores', icono: CreditCard },
  { etiqueta: 'Riesgo de deserción', href: '/admin/academico/riesgo', palabras: 'riesgo desercion abandono faltas seguimiento', icono: GraduationCap },
  { etiqueta: 'Cobranza', href: '/admin/academico/cobranza', palabras: 'cobranza mora deuda vencida promesa contacto', icono: CreditCard },
  { etiqueta: 'Certificados', href: '/admin/academico/certificados', palabras: 'certificados diplomas codigo', icono: Award },
  { etiqueta: 'Estudiantes', href: '/admin/academico/estudiantes', palabras: 'estudiantes alumnos dni', icono: Users },
  { etiqueta: 'Cursos', href: '/admin/academico/cursos', palabras: 'cursos catalogo', icono: BookOpen },
  { etiqueta: 'Ediciones', href: '/admin/academico/convocatorias', palabras: 'ediciones convocatorias horarios sesiones', icono: Calendar },
  { etiqueta: 'Reportes', href: '/admin/academico/reportes', palabras: 'reportes excel pdf', icono: FileBarChart },
] as const;

/**
 * Paleta de comandos global (`Ctrl + K` / `Cmd + K`): atajos de navegación + búsqueda en vivo
 * por DNI, nombre, teléfono, matrícula, edición o número de operación Yape.
 */
export function PaletaComandos() {
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const [abierto, setAbierto] = useState(false);
  const [consulta, setConsulta] = useState('');
  const [resultados, setResultados] = useState<ResultadoGrupo | null>(null);
  const [buscando, setBuscando] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const cerrar = () => {
    setAbierto(false);
    setConsulta('');
    setResultados(null);
    setBuscando(false);
  };

  useEffect(() => {
    const manejarTecla = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setAbierto((v) => {
          if (v) {
            setConsulta('');
            setResultados(null);
            setBuscando(false);
          }
          return !v;
        });
      } else if (e.key === 'Escape') {
        cerrar();
      }
    };
    window.addEventListener('keydown', manejarTecla);
    return () => window.removeEventListener('keydown', manejarTecla);
  }, []);

  useEffect(() => {
    if (abierto) {
      const id = setTimeout(() => inputRef.current?.focus(), 20);
      return () => clearTimeout(id);
    }
    return undefined;
  }, [abierto]);

  useEffect(() => {
    const limpio = consulta.trim();
    if (!abierto || limpio.length < 2) {
      return;
    }

    const timer = setTimeout(async () => {
      setBuscando(true);
      const r = await ejecutar<{ data: { grupos: ResultadoGrupo } }>(`academico/busqueda?q=${encodeURIComponent(limpio)}`, 'GET');
      setBuscando(false);
      if (r.ok) {
        setResultados(r.datos.data.grupos);
      }
    }, 220);

    return () => clearTimeout(timer);
  }, [abierto, consulta, ejecutar]);

  const ir = (href: string) => {
    cerrar();
    router.push(href);
  };

  const atajosFiltrados = consulta.trim() === ''
    ? ATAJOS
    : ATAJOS.filter((a) => `${a.etiqueta} ${a.palabras}`.toLowerCase().includes(consulta.trim().toLowerCase()));

  return (
    <>
      <button
        type="button"
        onClick={() => setAbierto(true)}
        className="inline-flex h-10 w-10 items-center justify-center gap-2 rounded-lg border border-panel-border bg-panel-bg text-xs text-panel-muted outline-offset-2 transition hover:border-academico-600/40 hover:text-panel-text focus-visible:outline-2 focus-visible:outline-academico-700 sm:h-auto sm:w-auto sm:px-2.5 sm:py-1.5"
        title="Buscador rápido (Ctrl + K)"
        aria-haspopup="dialog"
      >
        <Search className="h-4 w-4 sm:h-3.5 sm:w-3.5" aria-hidden="true" />
        <span className="sr-only sm:not-sr-only">Buscar…</span>
        <kbd className="hidden rounded border border-panel-border bg-panel-surface px-1.5 py-0.5 font-mono text-[10px] sm:inline">Ctrl K</kbd>
      </button>

      {abierto ? (
        <div
          className="fixed inset-0 z-50 flex items-start justify-center bg-slate-950/50 p-4 pt-16 backdrop-blur-[1px]"
          role="dialog"
          aria-modal="true"
          aria-label="Buscador rápido del panel"
          onClick={(e) => { if (e.target === e.currentTarget) cerrar(); }}
        >
          <div className="w-full max-w-xl overflow-hidden rounded-2xl border border-panel-border bg-panel-surface shadow-2xl">
            <div className="flex items-center gap-2 border-b border-panel-border px-4 py-3">
              <Search className="h-4 w-4 text-panel-muted" aria-hidden="true" />
              <input
                ref={inputRef}
                type="search"
                aria-label="Buscar en el panel"
                value={consulta}
                onChange={(e) => {
                  const valor = e.target.value;
                  setConsulta(valor);
                  if (valor.trim().length < 2) {
                    setResultados(null);
                    setBuscando(false);
                  }
                }}
                placeholder="Buscar por DNI, teléfono, nombre, N.º operación Yape o ir a un módulo…"
                className="flex-1 bg-transparent text-sm text-panel-text outline-none placeholder:text-panel-muted"
              />
              <button type="button" onClick={cerrar} className="rounded p-1 text-panel-muted hover:text-panel-text" aria-label="Cerrar buscador">
                <X className="h-4 w-4" />
              </button>
            </div>

            <div className="max-h-[65vh] space-y-4 overflow-y-auto p-4">
              {atajosFiltrados.length > 0 ? (
                <div>
                  <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-panel-muted">Atajos rápidos</p>
                  <ul className="space-y-1">
                    {atajosFiltrados.map((a) => {
                      const Icono = a.icono;
                      return (
                        <li key={a.href}>
                          <button
                            type="button"
                            onClick={() => ir(a.href)}
                            className="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm text-panel-text transition hover:bg-panel-bg"
                          >
                            <span className="flex items-center gap-2.5">
                              <Icono className="h-4 w-4 text-academico-700" aria-hidden="true" />
                              {a.etiqueta}
                            </span>
                            <ArrowRight className="h-3.5 w-3.5 text-panel-muted" aria-hidden="true" />
                          </button>
                        </li>
                      );
                    })}
                  </ul>
                </div>
              ) : null}

              {buscando ? <p className="text-xs text-panel-muted">Buscando en el sistema…</p> : null}

              {resultados ? (
                <div className="space-y-3">
                  {resultados.estudiantes?.length ? (
                    <GrupoResultados titulo="Estudiantes" items={resultados.estudiantes.map((e) => ({ clave: `e-${e.id}`, texto: e.texto, detalle: e.detalle, href: `/admin/academico/estudiantes/${e.id}` }))} onElegir={ir} />
                  ) : null}
                  {resultados.cursos?.length ? (
                    <GrupoResultados titulo="Cursos" items={resultados.cursos.map((c) => ({ clave: `cur-${c.id}`, texto: c.texto, detalle: c.detalle, href: `/admin/academico/cursos/${c.id}` }))} onElegir={ir} />
                  ) : null}
                  {resultados.matriculas?.length ? (
                    <GrupoResultados titulo="Matrículas" items={resultados.matriculas.map((m) => ({ clave: `m-${m.id}`, texto: m.texto, detalle: m.detalle, href: `/admin/academico/matriculas/${m.id}` }))} onElegir={ir} />
                  ) : null}
                  {resultados.ediciones?.length ? (
                    <GrupoResultados titulo="Ediciones" items={resultados.ediciones.map((c) => ({ clave: `c-${c.identificador}`, texto: c.texto, detalle: c.detalle, href: `/admin/academico/convocatorias/${c.identificador}` }))} onElegir={ir} />
                  ) : null}
                  {resultados.certificados?.length ? (
                    <GrupoResultados titulo="Certificados" items={resultados.certificados.map((c) => ({ clave: `cert-${c.codigo}`, texto: c.texto, detalle: c.detalle, href: `/admin/academico/certificados?buscar=${encodeURIComponent(c.codigo)}` }))} onElegir={ir} />
                  ) : null}
                  {resultados.pagos?.length ? (
                    <GrupoResultados titulo="Pagos / Operaciones" items={resultados.pagos.map((p, idx) => ({ clave: `p-${p.identificador}-${idx}`, texto: p.texto, detalle: p.detalle, href: `/admin/academico/pagos/${p.identificador}` }))} onElegir={ir} />
                  ) : null}
                </div>
              ) : null}
            </div>
          </div>
        </div>
      ) : null}
    </>
  );
}

function GrupoResultados({ titulo, items, onElegir }: { titulo: string; items: { clave: string; texto: string; detalle: string; href: string }[]; onElegir: (href: string) => void }) {
  return (
    <div>
      <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-panel-muted">{titulo}</p>
      <ul className="space-y-1">
        {items.map((item) => (
          <li key={item.clave}>
            <button
              type="button"
              onClick={() => onElegir(item.href)}
              className="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left transition hover:bg-panel-bg"
            >
              <div className="min-w-0">
                <p className="truncate text-sm font-medium text-panel-text">{item.texto}</p>
                {item.detalle ? <p className="truncate text-xs text-panel-muted">{item.detalle}</p> : null}
              </div>
              <ArrowRight className="h-3.5 w-3.5 shrink-0 text-panel-muted" aria-hidden="true" />
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}
