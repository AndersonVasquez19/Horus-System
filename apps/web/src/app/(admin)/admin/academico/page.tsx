import Link from 'next/link';
import { BookOpen, GraduationCap, Users } from 'lucide-react';
import { Suspense } from 'react';
import { TableroAcademico } from '@/components/academico/tablero';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoVacio } from '@/components/shared/ui/estados';
import { GraficoBarras } from '@/components/shared/ui/grafico-barras';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { TarjetaKpi } from '@/components/shared/ui/tarjeta-kpi';
import { Tabla } from '@/components/shared/ui/tabla';
import type { ResumenAcademico } from '@/features/academico/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { FormularioBusqueda } from '@/components/academico/formulario-busqueda';
import { etiqueta, fecha, numero } from '@/lib/panel/formato';

export const metadata = { title: 'Inicio · Gestión académica' };

const RUTA = '/admin/academico';

export default function AcademicoInicioPage() {
  return (
    <>
      <EncabezadoPagina titulo="Panel académico" descripcion="Resumen de la operación académica." />
      <div className="mb-6">
        <FormularioBusqueda valor="" />
      </div>
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
  const tablero = <TableroAcademico datos={resultado.datos.data} />;
  const tarjetas = [
    i.estudiantes_activos !== undefined && {
      etiqueta: 'Estudiantes activos', valor: numero(i.estudiantes_activos), icono: <Users className="h-5 w-5" />,
    },
    i.cursos_activos !== undefined && {
      etiqueta: 'Cursos activos', valor: numero(i.cursos_activos), icono: <BookOpen className="h-5 w-5" />,
      detalle: `${numero(i.cupos_disponibles)} de ${numero(i.cupos_maximos)} cupos disponibles`,
    },
  ].filter((tarjeta) => tarjeta !== false);
  // Matrículas del mes, cobranza (del libro de pagos), cupos y asistencia viven en el tablero nuevo.
  const d = resultado.datos.data;
  const hayTablero = i.matriculas_mes !== undefined || [d.acciones, d.negocio, d.academico].some((bloque) => bloque !== undefined && Object.keys(bloque).length > 0);

  if (tarjetas.length === 0 && !hayTablero) {
    return (
      <EstadoVacio
        titulo="Aún no tienes módulos asignados"
        texto="Tu cuenta puede entrar al panel, pero no tiene permisos sobre ningún módulo académico."
      />
    );
  }

  return (
    <div className="space-y-8">
      {tablero}

      {tarjetas.length > 0 ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {tarjetas.map((tarjeta) => (
            <TarjetaKpi key={tarjeta.etiqueta} tono="academico" {...tarjeta} />
          ))}
        </div>
      ) : null}

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
              </div>
              {b.ultimas_matriculas.length === 0 ? (
                <p className="px-5 py-10 text-center text-sm text-panel-muted">Todavía no hay matrículas registradas.</p>
              ) : (
                <Tabla plana titulo="Últimas matrículas" columnas={['Estudiante', 'Curso', 'Estado', 'Fecha']}>
                      {b.ultimas_matriculas.map((matricula) => (
                        <tr key={matricula.id}>
                          <td className="px-4 py-3 font-medium text-panel-text">{matricula.estudiante ?? '—'}</td>
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

      {i.cursos_activos !== undefined ? (
        <p className="text-sm text-panel-muted">
          <GraduationCap className="mr-1 inline h-4 w-4 align-text-bottom" aria-hidden="true" />
          Consulta el detalle de la oferta en{' '}
          <Link href="/admin/academico/cursos" className="font-semibold text-academico-700 underline-offset-4 hover:underline">
            Cursos
          </Link>
          .
        </p>
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
