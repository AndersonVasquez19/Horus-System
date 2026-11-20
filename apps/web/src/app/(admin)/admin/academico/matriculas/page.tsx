import Link from 'next/link';
import { Suspense } from 'react';
import { AccionesListadoMatriculas, MenuFilaMatricula } from '@/components/academico/acciones-matricula';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, SelectorPorPagina, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado, type ColorInsignia } from '@/components/shared/ui/insignia';
import type { ElementoMenuFila } from '@/components/shared/ui/menu-fila';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { MatriculaResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { mensajeBienvenida, urlMailto, urlWhatsApp } from '@/lib/panel/contacto';
import { ETIQUETAS, etiqueta, fecha, moneda } from '@/lib/panel/formato';

export const metadata = { title: 'Matrículas · Gestión académica' };

const RUTA = '/admin/academico/matriculas';
const PARAMETROS = ['busqueda', 'estado', 'curso_id', 'convocatoria', 'estado_cobro', 'eliminados', 'sort', 'per_page', 'page'] as const;
const FILTROS = ['busqueda', 'estado', 'curso_id', 'convocatoria', 'estado_cobro', 'eliminados'] as const;

type Parametros = Record<string, string | string[] | undefined>;
interface MetaMatriculas extends MetaListado {
  convocatorias?: { identificador: string; codigo: string; nombre: string | null }[];
}

const ESTADOS_COBRO = {
  pendiente: 'Pendiente',
  parcial: 'Parcial',
  con_saldo: 'Con saldo pendiente',
  pagada: 'Pagada',
  anulada: 'Obligación anulada',
  sin_obligacion: 'Sin obligación',
} as const;

const COLOR_COBRO_BADGE: Record<string, { texto: string; color: ColorInsignia }> = {
  pendiente: { texto: 'Pendiente', color: 'ambar' },
  parcial: { texto: 'Parcial', color: 'azul' },
  pagada: { texto: 'Pagada', color: 'verde' },
  anulada: { texto: 'Anulada', color: 'rojo' },
  sin_obligacion: { texto: 'Sin obligación', color: 'gris' },
};

export default function MatriculasPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <Suspense fallback={<EsqueletoListado etiqueta="Cargando matrículas" />}>
      <ListadoMatriculas searchParams={searchParams} />
    </Suspense>
  );
}

async function ListadoMatriculas({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: MatriculaResumen[]; meta: MetaMatriculas }>(`academico/matriculas${cadena}`, `${RUTA}${cadena}`);

  const encabezado = (
    <EncabezadoPagina
      titulo="Matrículas"
      descripcion="Matrículas por estudiante y curso, con su estado y ocupación de cupos."
      migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Matrículas' }]}
      // La exportación usa exactamente los filtros del listado (sin orden ni página).
      acciones={<AccionesListadoMatriculas filtros={consulta(parametros, FILTROS)} />}
    />
  );

  if (resultado.estado === 'prohibido') return <>{encabezado}<EstadoProhibido volver="/admin/academico" /></>;
  if (resultado.estado !== 'ok') return <>{encabezado}<EstadoError texto="Inténtalo de nuevo en unos minutos." /></>;

  const { data: matriculas, meta } = resultado.datos;
  const actuales = Object.fromEntries(
    PARAMETROS.filter((nombre) => nombre !== 'page' && parametro(parametros, nombre) !== '').map((nombre) => [nombre, parametro(parametros, nombre)]),
  );
  const hayFiltros = Object.keys(meta.filtros).length > 0;
  const cursoFiltrado = parametro(parametros, 'curso_id');

  return (
    <>
      {encabezado}
      <div className="space-y-4">
        <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
          <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="# matrícula, DNI, estudiante, código o curso" />
          <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoMatricula} />
          <SelectorFiltro
            tono="academico"
            nombre="convocatoria"
            etiqueta="Edición"
            valor={parametro(parametros, 'convocatoria')}
            opciones={{
              sin_convocatoria: 'Sin edición',
              ...Object.fromEntries((meta.convocatorias ?? []).map((c) => [c.identificador, c.nombre ? `${c.codigo} — ${c.nombre}` : c.codigo])),
            }}
          />
          <SelectorFiltro tono="academico" nombre="estado_cobro" etiqueta="Estado de cobro" valor={parametro(parametros, 'estado_cobro')} opciones={ESTADOS_COBRO} />
          <SelectorFiltro
            tono="academico"
            nombre="sort"
            etiqueta="Ordenar por"
            valor={parametro(parametros, 'sort') || '-fecha'}
            todos={null}
            opciones={{ '-fecha': 'Más recientes', fecha: 'Más antiguas', '-creado': 'Registradas últimamente', estado: 'Estado' }}
          />
          <SelectorPorPagina tono="academico" valor={parametro(parametros, 'per_page')} />
          {/^\d{1,18}$/.test(cursoFiltrado) ? <input type="hidden" name="curso_id" value={cursoFiltrado} /> : null}
          <label className="flex items-center gap-2 pb-2 text-sm text-panel-text">
            <input type="checkbox" name="eliminados" value="1" defaultChecked={parametro(parametros, 'eliminados') === '1'} className="h-4 w-4 rounded border-panel-border accent-academico-700" />
            Eliminadas
          </label>
        </FiltrosListado>

        {meta.filtros.curso_id ? (
          <p className="text-sm text-panel-muted">
            Mostrando solo un curso.{' '}
            <Link href={RUTA} className="font-semibold text-academico-700 hover:underline">Ver todos los cursos</Link>
          </p>
        ) : null}

        {matriculas.length === 0 ? (
          hayFiltros ? (
            <EstadoSinResultados titulo="Ninguna matrícula coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
          ) : (
            <EstadoVacio titulo="Todavía no hay matrículas registradas" />
          )
        ) : (
          <Tabla
            titulo={`Matrículas (${meta.total})`}
            columnas={['Estudiante', 'Curso', 'Edición', { texto: 'Fecha', orden: 'fecha' }, { texto: 'Monto', derecha: true }, 'Cobro', { texto: 'Estado', orden: 'estado' }, { texto: 'Acciones', oculta: true, derecha: true }]}
            ordenActual={meta.orden}
            rutaOrden={RUTA}
            parametrosOrden={actuales}
            pie={<Paginacion meta={meta} ruta={RUTA} parametros={actuales} />}
          >
            {matriculas.map((matricula) => {
              const est = matricula.estudiante;
              const nombreEst = est?.nombre ?? 'Estudiante';
              const cursoNombre = matricula.curso?.nombre ?? 'nuestro curso';
              const saludoWa = mensajeBienvenida({
                nombre: nombreEst,
                curso: cursoNombre,
                edicion: matricula.convocatoria?.codigo,
                enlaceGrupo: matricula.curso?.enlace_grupo_whatsapp,
              });
              const cobro = COLOR_COBRO_BADGE[matricula.estado_cobro ?? 'sin_obligacion'] ?? COLOR_COBRO_BADGE.sin_obligacion;
              const wa = urlWhatsApp(est?.telefono, saludoWa);
              const mail = urlMailto(est?.email, `Bienvenida · ${cursoNombre}`, saludoWa);
              const acciones: ElementoMenuFila[] = [
                { tipo: 'enlace', etiqueta: 'Ver matrícula', href: `${RUTA}/${matricula.id}` },
                ...(!matricula.eliminado ? [{ tipo: 'enlace' as const, etiqueta: 'Editar matrícula', href: `${RUTA}/${matricula.id}/editar` }] : []),
                ...(est ? [{ tipo: 'enlace' as const, etiqueta: 'Ver estudiante', href: `/admin/academico/estudiantes/${est.id}` }] : []),
                ...(matricula.obligacion_identificador ? [{ tipo: 'enlace' as const, etiqueta: 'Ver obligación de pago', href: `/admin/academico/pagos/${matricula.obligacion_identificador}` }] : []),
                ...(wa ? [{ tipo: 'enlace' as const, etiqueta: 'WhatsApp de bienvenida', href: wa, externo: true }] : []),
                ...(mail ? [{ tipo: 'enlace' as const, etiqueta: 'Enviar correo', href: mail, externo: true }] : []),
                ...(est?.telefono ? [{ tipo: 'copiar' as const, etiqueta: 'Copiar teléfono', valor: est.telefono, aviso: 'Teléfono copiado.' }] : []),
                ...(est?.email ? [{ tipo: 'copiar' as const, etiqueta: 'Copiar correo', valor: est.email, aviso: 'Correo copiado.' }] : []),
              ];
              return (
                <tr key={matricula.id} className="hover:bg-academico-50/50">
                  <td className="px-4 py-3">
                    <Link href={`${RUTA}/${matricula.id}`} className="font-semibold text-panel-text hover:text-academico-800 hover:underline">
                      {matricula.estudiante?.nombre ?? 'Estudiante no disponible'}
                    </Link>
                    <p className="font-mono text-xs text-panel-muted">{matricula.estudiante?.dni}</p>
                  </td>
                  <td className="px-4 py-3">
                    <p className="text-panel-text">{matricula.curso?.nombre ?? '—'}</p>
                    <p className="text-xs text-panel-muted">{matricula.curso?.codigo}</p>
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 text-panel-muted">
                    {matricula.convocatoria ? (
                      <Link href={`/admin/academico/convocatorias/${matricula.convocatoria.identificador}`} className="font-mono text-xs text-academico-700 hover:underline">
                        {matricula.convocatoria.codigo}
                      </Link>
                    ) : (
                      <span className="text-xs text-panel-muted">—</span>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(matricula.fecha)}</td>
                  <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums text-panel-muted">{moneda(matricula.monto_pagado)}</td>
                  <td className="px-4 py-3">
                    {matricula.obligacion_identificador ? (
                      <Link href={`/admin/academico/pagos/${matricula.obligacion_identificador}`} className="inline-flex flex-col gap-0.5 hover:underline">
                        <span><Insignia color={cobro.color}>{cobro.texto}</Insignia></span>
                        {matricula.saldo_cobro && Number.parseFloat(matricula.saldo_cobro) > 0 ? (
                          <span className="text-xs text-panel-muted">Saldo {moneda(matricula.saldo_cobro)}</span>
                        ) : null}
                      </Link>
                    ) : (
                      <Insignia color="gris">{cobro.texto}</Insignia>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1">
                      <Insignia color={colorEstado(matricula.estado)}>{etiqueta('estadoMatricula', matricula.estado)}</Insignia>
                      {matricula.eliminado ? <Insignia color="rojo">Eliminada</Insignia> : null}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <MenuFilaMatricula matricula={{ id: matricula.id, estado: matricula.estado, eliminado: Boolean(matricula.eliminado) }} elementos={acciones} />
                  </td>
                </tr>
              );
            })}
          </Tabla>
        )}
      </div>
    </>
  );
}
