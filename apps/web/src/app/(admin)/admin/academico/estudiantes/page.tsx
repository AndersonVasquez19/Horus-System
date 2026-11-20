import Link from 'next/link';
import { Suspense } from 'react';
import { BotonNuevoEstudiante } from '@/components/academico/acciones-estudiante';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { BotonExportarListado } from '@/components/shared/ui/boton-exportar-listado';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { MenuFila, type ElementoMenuFila } from '@/components/shared/ui/menu-fila';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { EstudianteResumen } from '@/features/academico/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { mensajeEstudiante, urlMailto, urlWhatsApp } from '@/lib/panel/contacto';
import { ETIQUETAS, etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Estudiantes · Gestión académica' };

const RUTA = '/admin/academico/estudiantes';
const PARAMETROS = ['busqueda', 'estado', 'eliminados', 'sort', 'page'] as const;

type Parametros = Record<string, string | string[] | undefined>;

export default function EstudiantesPage({ searchParams }: { searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Estudiantes"
        descripcion="Registro de estudiantes, sus datos de contacto y su estado."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Estudiantes' }]}
        acciones={<BotonNuevoEstudiante />}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando estudiantes" />}>
        <ListadoEstudiantes searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function ListadoEstudiantes({ searchParams }: { searchParams: Promise<Parametros> }) {
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const resultado = await consultarPanel<{ data: EstudianteResumen[]; meta: MetaListado }>(`academico/estudiantes${cadena}`, `${RUTA}${cadena}`);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { data: estudiantes, meta } = resultado.datos;
  const actuales = Object.fromEntries(
    PARAMETROS.filter((nombre) => nombre !== 'page' && parametro(parametros, nombre) !== '').map((nombre) => [nombre, parametro(parametros, nombre)]),
  );
  const hayFiltros = Object.keys(meta.filtros).length > 0;

  return (
    <div className="space-y-4">
      <FiltrosListado tono="academico" ruta={RUTA} hayFiltros={hayFiltros}>
        <CampoBusqueda tono="academico" nombre="busqueda" valor={parametro(parametros, 'busqueda')} marcador="DNI, nombres o apellidos" />
        <SelectorFiltro tono="academico" nombre="estado" etiqueta="Estado" valor={parametro(parametros, 'estado')} opciones={ETIQUETAS.estadoEstudiante} />
        <SelectorFiltro
          tono="academico"
          nombre="sort"
          etiqueta="Ordenar por"
          valor={parametro(parametros, 'sort') || 'apellidos'}
          todos={null}
          opciones={{ apellidos: 'Apellidos (A–Z)', '-apellidos': 'Apellidos (Z–A)', dni: 'DNI', '-creado': 'Más recientes' }}
        />
        <label className="flex items-center gap-2 pb-2 text-sm text-panel-text">
          <input type="checkbox" name="eliminados" value="1" defaultChecked={parametro(parametros, 'eliminados') === '1'} className="h-4 w-4 rounded border-panel-border accent-academico-700" />
          Desactivados
        </label>
      </FiltrosListado>

      <div className="flex justify-end">
        <BotonExportarListado
          ruta="academico/estudiantes/exportar"
          filtros={consulta(parametros, ['busqueda', 'estado', 'eliminados', 'sort'])}
          permiso="estudiantes.ver"
        />
      </div>

      {estudiantes.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ningún estudiante coincide con los filtros" texto="Prueba con otros criterios o limpia los filtros." />
        ) : (
          <EstadoVacio titulo="Todavía no hay estudiantes registrados" texto="Registra el primero con «Nuevo estudiante»." />
        )
      ) : (
        <Tabla
          titulo={`Estudiantes (${meta.total})`}
          columnas={['Estudiante', 'DNI', { texto: 'Contacto' }, 'Registrado', 'Estado', { texto: 'Acciones', oculta: true, derecha: true }]}
          pie={<Paginacion meta={meta} ruta={RUTA} parametros={actuales} />}
        >
          {estudiantes.map((estudiante) => {
            const nombreCompleto = `${estudiante.nombres} ${estudiante.apellidos}`.trim();
            const wa = urlWhatsApp(estudiante.telefono, mensajeEstudiante({ nombre: nombreCompleto }));
            const mail = urlMailto(estudiante.email, 'Horus Group · Información académica');
            const acciones: ElementoMenuFila[] = [
              { tipo: 'enlace', etiqueta: 'Ver ficha', href: `${RUTA}/${estudiante.id}` },
              ...(!estudiante.eliminado && estudiante.estado === 'activo'
                ? [{ tipo: 'enlace' as const, etiqueta: 'Matricular', href: `/admin/academico/matriculas/nueva?estudiante_id=${estudiante.id}` }]
                : []),
              ...(wa ? [{ tipo: 'enlace' as const, etiqueta: 'WhatsApp', href: wa, externo: true }] : []),
              ...(mail ? [{ tipo: 'enlace' as const, etiqueta: 'Enviar correo', href: mail, externo: true }] : []),
              ...(estudiante.telefono ? [{ tipo: 'copiar' as const, etiqueta: 'Copiar teléfono', valor: estudiante.telefono, aviso: 'Teléfono copiado.' }] : []),
              ...(estudiante.email ? [{ tipo: 'copiar' as const, etiqueta: 'Copiar correo', valor: estudiante.email, aviso: 'Correo copiado.' }] : []),
              { tipo: 'copiar', etiqueta: 'Copiar DNI', valor: estudiante.dni, aviso: 'DNI copiado.' },
            ];
            return (
              <tr key={estudiante.id} className="hover:bg-academico-50/50">
                <td className="px-4 py-3">
                  <Link href={`${RUTA}/${estudiante.id}`} className="font-semibold text-panel-text hover:text-academico-800 hover:underline">
                    {estudiante.apellidos}, {estudiante.nombres}
                  </Link>
                </td>
                <td className="px-4 py-3 font-mono text-xs text-panel-muted">{estudiante.dni}</td>
                <td className="px-4 py-3 text-panel-muted">
                  <p className="max-w-56 truncate">{estudiante.email ?? '—'}</p>
                  {estudiante.telefono ? <p className="text-xs">{estudiante.telefono}</p> : null}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{fecha(estudiante.creado_en)}</td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap gap-1">
                    <Insignia color={colorEstado(estudiante.estado)}>{etiqueta('estadoEstudiante', estudiante.estado)}</Insignia>
                    {estudiante.eliminado ? <Insignia color="rojo">Desactivado</Insignia> : null}
                  </div>
                </td>
                <td className="px-4 py-3 text-right">
                  <MenuFila etiqueta={`Acciones para ${nombreCompleto}`} elementos={acciones} />
                </td>
              </tr>
            );
          })}
        </Tabla>
      )}
    </div>
  );
}
