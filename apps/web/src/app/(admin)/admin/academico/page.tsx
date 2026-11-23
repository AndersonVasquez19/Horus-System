import Link from 'next/link';
import { CalendarPlus, Plus, UserPlus, Wallet } from 'lucide-react';
import { Suspense } from 'react';
import { TableroAcademico } from '@/components/academico/tablero';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoVacio } from '@/components/shared/ui/estados';
import { GraficoBarras } from '@/components/shared/ui/grafico-barras';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { Tabla } from '@/components/shared/ui/tabla';
import type { ResumenAcademico } from '@/features/academico/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Inicio · Gestión académica' };

const RUTA = '/admin/academico';

export default function AcademicoInicioPage() {
  return (
    <>
      <EncabezadoPagina
        titulo="Panel académico"
        descripcion="Vista ejecutiva, alertas prioritarias y accesos rápidos."
        acciones={
          <div className="flex flex-wrap items-center gap-2">
            <Link
              href="/admin/academico/matriculas/nueva"
              className="inline-flex items-center gap-1.5 rounded-lg bg-academico-700 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-academico-800"
            >
              <Plus className="h-3.5 w-3.5" aria-hidden="true" />
              Nueva matrícula
            </Link>
            <Link
              href="/admin/academico/pagos"
              className="inline-flex items-center gap-1.5 rounded-lg border border-panel-border bg-panel-surface px-3 py-2 text-xs font-semibold text-panel-text transition hover:bg-panel-bg"
            >
              <Wallet className="h-3.5 w-3.5 text-academico-700" aria-hidden="true" />
              Pagos
            </Link>
            <Link
              href="/admin/academico/estudiantes"
              className="inline-flex items-center gap-1.5 rounded-lg border border-panel-border bg-panel-surface px-3 py-2 text-xs font-semibold text-panel-text transition hover:bg-panel-bg"
            >
              <UserPlus className="h-3.5 w-3.5 text-academico-700" aria-hidden="true" />
              Estudiantes
            </Link>
            <Link
              href="/admin/academico/convocatorias"
              className="inline-flex items-center gap-1.5 rounded-lg border border-panel-border bg-panel-surface px-3 py-2 text-xs font-semibold text-panel-text transition hover:bg-panel-bg"
            >
              <CalendarPlus className="h-3.5 w-3.5 text-academico-700" aria-hidden="true" />
              Ediciones
            </Link>
          </div>
        }
      />
      <Suspense fallback={<EsqueletoResumen />}>
        <Resumen />
      </Suspense>
    </>
  );
}

async function Resumen() {
  const resultado = await consultarPanel<{ data: ResumenAcademico }>('academico/resumen', RUTA);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { indicadores: i, bloques: b } = resultado.datos.data;
  const d = resultado.datos.data;
  const hayContenido =
    i.estudiantes_activos !== undefined ||
    i.cursos_activos !== undefined ||
    i.matriculas_mes !== undefined ||
    [d.acciones, d.negocio, d.academico].some((bloque) => bloque !== undefined && Object.keys(bloque).length > 0);

  if (!hayContenido) {
    return (
      <EstadoVacio
        titulo="Aún no tienes módulos asignados"
        texto="Tu cuenta puede entrar al panel, pero no tiene permisos sobre ningún módulo académico."
      />
    );
  }

  return (
    <div className="space-y-6">
      <TableroAcademico datos={d} />

      {b.matriculas_por_mes || b.ultimas_matriculas ? (
        <div className="grid gap-6 xl:grid-cols-5">
          {b.matriculas_por_mes ? (
            <section aria-labelledby="titulo-tendencia" className="rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm xl:col-span-2">
              <h2 id="titulo-tendencia" className="font-heading text-base font-semibold text-panel-text">
                Matrículas por mes
              </h2>
              <p className="mb-4 text-xs text-panel-muted">Últimos seis meses</p>
              <GraficoBarras tono="academico" titulo="Matrículas registradas por mes" datos={b.matriculas_por_mes} />
            </section>
          ) : null}

          {b.ultimas_matriculas ? (
            <section aria-labelledby="titulo-ultimas" className="overflow-hidden rounded-xl border border-panel-border bg-panel-surface shadow-sm xl:col-span-3">
              <div className="flex items-center justify-between px-5 pt-5">
                <h2 id="titulo-ultimas" className="font-heading text-base font-semibold text-panel-text">
                  Últimas matrículas
                </h2>
                <Link href="/admin/academico/matriculas" className="text-xs font-semibold text-academico-700 hover:underline">
                  Ver todas
                </Link>
              </div>
              {b.ultimas_matriculas.length === 0 ? (
                <p className="px-5 py-10 text-center text-sm text-panel-muted">Todavía no hay matrículas registradas.</p>
              ) : (
                <Tabla plana titulo="Últimas matrículas" columnas={['Estudiante', 'Curso', 'Estado', 'Fecha']}>
                  {b.ultimas_matriculas.map((matricula) => (
                    <tr key={matricula.id} className="hover:bg-academico-50/40">
                      <td className="px-4 py-3 font-medium text-panel-text">
                        <Link href={`/admin/academico/matriculas/${matricula.id}`} className="hover:text-academico-700 hover:underline">
                          {matricula.estudiante ?? '—'}
                        </Link>
                      </td>
                      <td className="px-4 py-3 text-panel-muted">{matricula.curso ?? '—'}</td>
                      <td className="px-4 py-3">
                        <Insignia color={colorEstado(matricula.estado)}>{etiqueta('estadoMatricula', matricula.estado)}</Insignia>
                      </td>
                      <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(matricula.fecha)}</td>
                    </tr>
                  ))}
                </Tabla>
              )}
            </section>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

function EsqueletoResumen() {
  return (
    <div className="space-y-6" aria-busy="true" aria-label="Cargando el resumen">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {[0, 1, 2, 3].map((n) => (
          <div key={n} className="h-32 animate-pulse rounded-xl border border-panel-border bg-panel-surface" />
        ))}
      </div>
      <div className="grid gap-6 xl:grid-cols-5">
        <div className="h-72 animate-pulse rounded-xl border border-panel-border bg-panel-surface xl:col-span-2" />
        <div className="h-72 animate-pulse rounded-xl border border-panel-border bg-panel-surface xl:col-span-3" />
      </div>
    </div>
  );
}
