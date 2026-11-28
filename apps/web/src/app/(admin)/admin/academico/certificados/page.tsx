import Link from 'next/link';
import { Award } from 'lucide-react';
import { Suspense } from 'react';
import { AccionesFilaCertificado } from '@/components/academico/acciones-certificado';
import { BotonCopiarDato } from '@/components/shared/ui/acciones-celda';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, SelectorPorPagina, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { CertificadoResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Certificados · Gestión académica' };

const RUTA = '/admin/academico/certificados';
const PARAMETROS = ['buscar', 'estado', 'convocatoria', 'sort', 'per_page', 'page'] as const;

type Parametros = Record<string, string | string[] | undefined>;

export default function CertificadosPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Certificados"
        descripcion="Diplomas institucionales emitidos con código verificable. Para emitir por lote, ingresa a la edición correspondiente."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Certificados' }]}
        acciones={
          <Link
            href="/admin/academico/convocatorias"
            className="inline-flex h-10 items-center gap-2 rounded-lg border border-panel-border bg-panel-surface px-4 text-sm font-medium text-panel-ink transition hover:bg-panel-bg"
          >
            <Award className="h-4 w-4 text-academico-700" aria-hidden="true" />
            Ir a ediciones
          </Link>
        }
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando certificados" />}>
        <ListadoCertificados searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function ListadoCertificados({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: CertificadoResumen[]; meta: MetaListado }>(
    `academico/certificados${cadena}`,
    `${RUTA}${cadena}`,
  );

  if (resultado.estado === 'prohibido') {
    return <EstadoProhibido volver="/admin/academico" />;
  }
  if (resultado.estado !== 'ok') {
    return <EstadoError texto="No se pudo cargar el registro de certificados. Inténtalo de nuevo en unos minutos." />;
  }

  const { data: certificados, meta } = resultado.datos;
  const actuales = Object.fromEntries(
    PARAMETROS.filter((nombre) => nombre !== 'page' && parametro(parametros, nombre) !== '').map((nombre) => [nombre, parametro(parametros, nombre)]),
  );
  const hayFiltros = Object.keys(meta.filtros).length > 0;

  return (
    <div className="space-y-4">
      <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda
          tono="academico"
          nombre="buscar"
          valor={parametro(parametros, 'buscar')}
          marcador="Código (HORUS-…), DNI, estudiante o curso"
          etiqueta="Buscar certificado"
        />
        <SelectorFiltro
          tono="academico"
          nombre="estado"
          etiqueta="Estado"
          valor={parametro(parametros, 'estado')}
          opciones={{ emitido: 'Emitidos', anulado: 'Anulados' }}
        />
        <SelectorPorPagina tono="academico" valor={parametro(parametros, 'per_page')} />
      </FiltrosListado>

      {certificados.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ningún certificado coincide con los filtros" texto="Prueba con otro código, DNI o limpia los filtros." />
        ) : (
          <EstadoVacio
            titulo="Todavía no hay certificados emitidos"
            texto="Puedes emitir certificados en lote desde la pestaña Certificados dentro de cada edición."
          />
        )
      ) : (
        <Tabla
          accionesFijas
          titulo={`Certificados (${meta.total})`}
          columnas={[
            'Código',
            'Estudiante',
            'Curso y edición',
            'Rendimiento',
            'Estado',
            'Emitido',
            { texto: 'Acciones', derecha: true },
          ]}
          pie={<Paginacion meta={meta} ruta={RUTA} parametros={actuales} />}
        >
          {certificados.map((c) => (
            <tr key={c.identificador} className="hover:bg-academico-50/50">
              <td className="whitespace-nowrap px-4 py-3">
                <div className="inline-flex items-center gap-1">
                  <span className="font-mono text-sm font-semibold text-panel-ink">{c.codigo}</span>
                  <BotonCopiarDato valor={c.codigo} etiqueta={`Copiar código ${c.codigo}`} />
                </div>
              </td>
              <td className="px-4 py-3">
                {c.estudiante.id ? (
                  <Link href={`/admin/academico/estudiantes/${c.estudiante.id}`} className="font-semibold text-academico-700 hover:underline">
                    {c.estudiante.nombre}
                  </Link>
                ) : (
                  <span className="font-semibold text-panel-ink">{c.estudiante.nombre}</span>
                )}
                <div className="font-mono text-xs text-panel-muted">DNI {c.estudiante.dni || '—'}</div>
              </td>
              <td className="px-4 py-3">
                <div className="font-medium text-panel-ink">{c.curso.nombre}</div>
                <div className="text-xs text-panel-muted">
                  {c.edicion ? (
                    <Link href={`/admin/academico/convocatorias/${c.edicion.identificador}`} className="hover:underline">
                      Edición {c.edicion.codigo}
                    </Link>
                  ) : (
                    <span>Matrícula #{c.matricula_id}</span>
                  )}
                </div>
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-xs text-panel-muted">
                {c.porcentaje_asistencia !== null ? <div>Asistencia: {c.porcentaje_asistencia.toFixed(1)}%</div> : null}
                {c.nota_final !== null ? <div>Nota final: {c.nota_final.toFixed(2)}</div> : null}
                {c.porcentaje_asistencia === null && c.nota_final === null ? '—' : null}
              </td>
              <td className="whitespace-nowrap px-4 py-3">
                <Insignia color={c.estado === 'emitido' ? 'verde' : 'rojo'}>
                  {c.estado_etiqueta}
                </Insignia>
                {c.motivo_anulacion ? (
                  <div className="mt-1 max-w-[14rem] truncate text-xs text-panel-muted" title={c.motivo_anulacion}>
                    {c.motivo_anulacion}
                  </div>
                ) : null}
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-xs text-panel-muted">
                <div>{fecha(c.emitido_en)}</div>
                {c.emitido_por ? <div>por {c.emitido_por}</div> : null}
              </td>
              <td className="px-4 py-3">
                <AccionesFilaCertificado
                  identificador={c.identificador}
                  codigo={c.codigo}
                  anulado={c.estado === 'anulado'}
                />
              </td>
            </tr>
          ))}
        </Tabla>
      )}
    </div>
  );
}
