import Link from 'next/link';
import { Suspense } from 'react';
import { AccionesFilaCobranza } from '@/components/academico/acciones-cobranza';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia } from '@/components/shared/ui/insignia';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { ColaCobranza, TramoMora } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { fecha, moneda } from '@/lib/panel/formato';

export const metadata = { title: 'Cobranza · Gestión académica' };

const RUTA = '/admin/academico/cobranza';
const PARAMETROS = ['tramo', 'promesa', 'busqueda'] as const;
const TRAMOS: Record<TramoMora, { etiqueta: string; rango: string; color: 'ambar' | 'azul' | 'rojo' }> = {
  reciente: { etiqueta: 'Reciente', rango: '1–7 días', color: 'azul' },
  media: { etiqueta: 'Media', rango: '8–30 días', color: 'ambar' },
  critica: { etiqueta: 'Crítica', rango: 'más de 30 días', color: 'rojo' },
};

type Parametros = Record<string, string | string[] | undefined>;

export default function CobranzaPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Cobranza"
        descripcion="Deuda vencida ordenada por antigüedad, con el último contacto y las promesas de pago. Los contactos se registran a mano."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Cobranza' }]}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando la cola de cobranza" />}>
        <Cola searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Cola({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: ColaCobranza }>(`academico/cobranza/cola${cadena}`, `${RUTA}${cadena}`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="No se pudo cargar la cola de cobranza. Inténtalo de nuevo en unos minutos." />;

  const { rows, resumen, truncado } = resultado.datos.data;
  const hayFiltros = PARAMETROS.some((nombre) => parametro(parametros, nombre) !== '');

  return (
    <div className="space-y-4">
      <dl className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Cifra titulo="Con deuda vencida" valor={String(resumen.obligaciones)} detalle={moneda(resumen.monto_vencido)} />
        {(Object.keys(TRAMOS) as TramoMora[]).map((t) => (
          <Cifra key={t} titulo={`${TRAMOS[t].etiqueta} (${TRAMOS[t].rango})`} valor={String(resumen.tramos[t].cantidad)} detalle={moneda(resumen.tramos[t].monto)} />
        ))}
        <Cifra titulo="Promesas vigentes" valor={String(resumen.promesas_vigentes)} />
        <Cifra titulo="Promesas incumplidas" valor={String(resumen.promesas_incumplidas)} />
        <Cifra titulo="Sin ningún contacto" valor={String(resumen.sin_contacto)} />
      </dl>

      <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="DNI, estudiante o curso" etiqueta="Buscar" />
        <SelectorFiltro
          tono="academico"
          nombre="tramo"
          etiqueta="Antigüedad"
          valor={parametro(parametros, 'tramo')}
          opciones={{ critica: 'Crítica (más de 30 días)', media: 'Media (8–30 días)', reciente: 'Reciente (1–7 días)' }}
        />
        <SelectorFiltro
          tono="academico"
          nombre="promesa"
          etiqueta="Promesa de pago"
          valor={parametro(parametros, 'promesa')}
          opciones={{ incumplida: 'Incumplida', vigente: 'Vigente', ninguna: 'Sin promesa' }}
        />
      </FiltrosListado>

      {truncado ? <p className="text-sm text-panel-muted">Se muestran las 200 más urgentes. Filtra para ver el resto.</p> : null}

      {rows.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ninguna obligación coincide con los filtros" texto="Prueba con otra búsqueda o limpia los filtros." />
        ) : (
          <EstadoVacio titulo="No hay deuda vencida" texto="Cuando una cuota venza sin pagarse aparecerá aquí." />
        )
      ) : (
        <Tabla
          accionesFijas
          titulo={`Cola de cobranza (${rows.length})`}
          columnas={['Estudiante', 'Curso', 'Mora', 'Vencido', 'Último contacto', 'Promesa', { texto: 'Acciones', derecha: true }]}
        >
          {rows.map((f) => (
            <tr key={f.obligacion} className="hover:bg-academico-50/50">
              <td className="px-4 py-3">
                <Link href={`/admin/academico/pagos/${f.obligacion}`} className="font-semibold text-academico-700 hover:underline">
                  {f.estudiante ?? 'Estudiante'}
                </Link>
                <div className="font-mono text-xs text-panel-muted">DNI {f.dni ?? '—'}</div>
              </td>
              <td className="px-4 py-3 text-sm text-panel-ink">{f.curso?.nombre ?? '—'}</td>
              <td className="whitespace-nowrap px-4 py-3">
                <Insignia color={TRAMOS[f.tramo].color}>{f.dias_mora} día(s)</Insignia>
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-sm">
                <div className="font-semibold text-panel-ink">{moneda(f.monto_vencido)}</div>
                <div className="text-xs text-panel-muted">
                  {f.cuotas_vencidas} cuota(s) · saldo total {moneda(f.saldo)}
                </div>
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-xs text-panel-muted">
                {f.ultimo_contacto ? `${f.ultimo_contacto.canal} · ${f.ultimo_contacto.fecha ? fecha(f.ultimo_contacto.fecha) : ''}` : 'Sin contacto'}
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-xs">
                {f.promesa ? (
                  <Insignia color={f.promesa.estado === 'vigente' ? 'verde' : 'rojo'}>
                    {f.promesa.estado === 'vigente' ? 'Vigente' : 'Incumplida'} · {fecha(f.promesa.fecha)}
                  </Insignia>
                ) : (
                  <span className="text-panel-muted">—</span>
                )}
              </td>
              <td className="px-4 py-3">
                <AccionesFilaCobranza obligacion={f.obligacion} estudiante={f.estudiante ?? 'Estudiante'} />
              </td>
            </tr>
          ))}
        </Tabla>
      )}
    </div>
  );
}

function Cifra({ titulo, valor, detalle }: { titulo: string; valor: string; detalle?: string }) {
  return (
    <div className="rounded-xl border border-panel-border bg-panel-surface p-4 shadow-sm">
      <dt className="text-xs font-medium text-panel-muted">{titulo}</dt>
      <dd className="mt-1 text-2xl font-semibold text-panel-ink">{valor}</dd>
      {detalle ? <dd className="text-sm text-panel-text">{detalle}</dd> : null}
    </div>
  );
}
