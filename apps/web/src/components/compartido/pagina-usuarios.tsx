import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { TonoUi } from '@/components/shared/ui/boton';
import type { UsuarioPanel } from '@/features/notificaciones/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { AccionesUsuario, BotonNuevoUsuario } from './acciones-usuario';

type Parametros = Record<string, string | string[] | undefined>;

const PARAMETROS = ['busqueda', 'sort', 'page'] as const;

/** Usuarios del sistema. La monta cada panel bajo `/admin/<panel>/usuarios`; Laravel decide qué puede hacer cada persona. */
export function PaginaUsuarios({ tono, searchParams }: { tono: TonoUi; searchParams: Promise<Parametros> }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Usuarios"
        descripcion="Cuentas del personal y los roles que determinan su acceso."
        migas={[{ etiqueta: 'Inicio', href: `/admin/${tono}` }, { etiqueta: 'Usuarios' }]}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando los usuarios" />}>
        <Listado tono={tono} searchParams={searchParams} />
      </Suspense>
    </>
  );
}

async function Listado({ tono, searchParams }: { tono: TonoUi; searchParams: Promise<Parametros> }) {
  const ruta = `/admin/${tono}/usuarios`;
  const parametros = await searchParams;
  const cadena = consulta(parametros, PARAMETROS);
  const [usuarios, roles] = await Promise.all([
    consultarPanel<{ data: UsuarioPanel[]; meta: MetaListado }>(`compartido/usuarios${cadena}`, `${ruta}${cadena}`),
    // Sin `usuarios.crear` ni `usuarios.editar` Laravel responde 403: no hay formularios que alimentar.
    consultarPanel<{ data: string[] }>('compartido/usuarios/roles-asignables', ruta),
  ]);

  if (usuarios.estado === 'prohibido') return <EstadoProhibido volver={`/admin/${tono}`} />;
  if (usuarios.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const nombresRol = roles.estado === 'ok' ? roles.datos.data : [];
  const { data: filas, meta } = usuarios.datos;
  const busqueda = parametro(parametros, 'busqueda');
  const actuales: Record<string, string> = busqueda === '' ? {} : { busqueda };
  const hayFiltros = busqueda !== '';

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <FiltrosListado tono={tono} ruta={ruta} hayFiltros={hayFiltros}>
          <CampoBusqueda tono={tono} nombre="busqueda" valor={busqueda} marcador="Nombre o correo" />
        </FiltrosListado>
        <BotonNuevoUsuario roles={nombresRol} tono={tono} />
      </div>

      {filas.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ningún usuario coincide con la búsqueda" texto="Prueba con otro nombre o correo." />
        ) : (
          <EstadoVacio titulo="Todavía no hay usuarios" texto="Registra la primera cuenta con «Nuevo usuario»." />
        )
      ) : (
        <Tabla
          titulo={`Usuarios (${meta.total})`}
          columnas={['Nombre', 'Correo', 'Roles', { texto: 'Acciones', oculta: true, derecha: true }]}
          pie={<Paginacion meta={meta} ruta={ruta} parametros={actuales} />}
        >
          {filas.map((usuario) => (
            <tr key={usuario.id} className="align-middle">
              <td className="px-4 py-3 font-medium text-panel-text">{usuario.nombre}</td>
              <td className="px-4 py-3 text-panel-muted">{usuario.email}</td>
              <td className="px-4 py-3">
                {usuario.roles.length === 0 ? (
                  <span className="text-panel-muted">Sin roles</span>
                ) : (
                  <span className="flex flex-wrap gap-1">
                    {usuario.roles.map((rol) => <Insignia key={rol} color={rol === 'admin' ? 'azul' : 'gris'}>{rol}</Insignia>)}
                  </span>
                )}
              </td>
              <td className="px-4 py-3"><AccionesUsuario usuario={usuario} roles={nombresRol} tono={tono} /></td>
            </tr>
          ))}
        </Tabla>
      )}
    </div>
  );
}
