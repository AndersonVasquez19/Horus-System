import { Suspense } from 'react';
import { BotonExportarListado } from '@/components/shared/ui/boton-exportar-listado';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados, EstadoVacio } from '@/components/shared/ui/estados';
import { CampoBusqueda, FiltrosListado, SelectorFiltro, SelectorPorPagina, parametro } from '@/components/shared/ui/filtros-listado';
import { Insignia } from '@/components/shared/ui/insignia';
import { Paginacion, type MetaListado } from '@/components/shared/ui/paginacion';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { TonoUi } from '@/components/shared/ui/boton';
import type { UsuarioPanel } from '@/features/notificaciones/tipos';
import { consulta, consultarPanel } from '@/lib/panel/api-servidor';
import { etiqueta, fecha } from '@/lib/panel/formato';
import { AccionesUsuario, BotonNuevoUsuario } from './acciones-usuario';

type Parametros = Record<string, string | string[] | undefined>;

const PARAMETROS = ['busqueda', 'rol', 'estado', 'eliminados', 'sort', 'page', 'per_page'] as const;
const ESTADOS_USUARIO = { verificado: 'Correo verificado', no_verificado: 'Sin verificar', suspendido: 'Suspendidas' };

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
  const rol = parametro(parametros, 'rol');
  const estado = parametro(parametros, 'estado');
  const eliminados = parametro(parametros, 'eliminados') === '1';
  const sort = parametro(parametros, 'sort');
  const porPagina = parametro(parametros, 'per_page');

  const actuales: Record<string, string> = {};
  if (busqueda !== '') actuales.busqueda = busqueda;
  if (rol !== '') actuales.rol = rol;
  if (estado !== '') actuales.estado = estado;
  if (eliminados) actuales.eliminados = '1';
  if (porPagina !== '') actuales.per_page = porPagina;
  const actualesPaginacion = sort === '' ? actuales : { ...actuales, sort };
  const hayFiltros = busqueda !== '' || rol !== '' || estado !== '' || eliminados;

  const opcionesRol: Record<string, string> = {};
  for (const r of new Set(['admin', 'coordinador', 'secretaria', ...nombresRol, ...filas.flatMap((u) => u.roles)])) {
    opcionesRol[r] = etiqueta('rol', r);
  }
  opcionesRol.sin_rol = 'Sin rol';

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <FiltrosListado tono={tono} ruta={ruta} hayFiltros={hayFiltros}>
          <CampoBusqueda tono={tono} nombre="busqueda" valor={busqueda} marcador="Nombre o correo" />
          <SelectorFiltro tono={tono} nombre="rol" etiqueta="Rol" valor={rol} opciones={opcionesRol} />
          <SelectorFiltro tono={tono} nombre="estado" etiqueta="Estado" valor={estado} opciones={ESTADOS_USUARIO} />
          <label className="inline-flex items-center gap-2 pb-2 text-xs font-medium text-panel-text">
            <input type="checkbox" name="eliminados" value="1" defaultChecked={eliminados} className="h-4 w-4 rounded border-panel-border" />
            Solo dadas de baja
          </label>
          <SelectorPorPagina tono={tono} valor={porPagina} />
        </FiltrosListado>
        <BotonNuevoUsuario roles={nombresRol} tono={tono} />
      </div>

      <div className="flex justify-end">
        <BotonExportarListado
          ruta="compartido/usuarios/exportar"
          filtros={consulta(parametros, ['busqueda', 'rol', 'estado', 'eliminados', 'sort'])}
          permiso="usuarios.ver"
        />
      </div>

      {filas.length === 0 ? (
        hayFiltros ? (
          <EstadoSinResultados titulo="Ningún usuario coincide con los filtros" texto="Prueba con otro término, rol o estado." />
        ) : (
          <EstadoVacio titulo="Todavía no hay usuarios" texto="Registra la primera cuenta con «Nuevo usuario»." />
        )
      ) : (
        <Tabla
          titulo={`Usuarios (${meta.total})`}
          columnas={[
            { texto: 'Nombre', orden: 'nombre' },
            { texto: 'Correo', orden: 'email' },
            'Roles',
            'Verificado',
            { texto: 'Registrado', orden: 'creado' },
            { texto: 'Acciones', oculta: true, derecha: true },
          ]}
          ordenActual={sort || 'nombre'}
          rutaOrden={ruta}
          parametrosOrden={actuales}
          pie={<Paginacion meta={meta} ruta={ruta} parametros={actualesPaginacion} />}
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
                    {usuario.roles.map((r) => (
                      <Insignia key={r} color={r === 'admin' ? 'azul' : 'gris'}>
                        {etiqueta('rol', r)}
                      </Insignia>
                    ))}
                  </span>
                )}
              </td>
              <td className="px-4 py-3">
                <div className="flex flex-wrap gap-1">
                  {usuario.eliminado ? (
                    <Insignia color="rojo">De baja</Insignia>
                  ) : usuario.suspendido ? (
                    <Insignia color="rojo">Suspendida</Insignia>
                  ) : (
                    <Insignia color={usuario.verificado ? 'verde' : 'ambar'}>
                      {usuario.verificado ? 'Verificado' : 'Pendiente'}
                    </Insignia>
                  )}
                  {usuario.debe_cambiar_clave ? <Insignia color="ambar">Clave temporal</Insignia> : null}
                  {usuario.two_factor_habilitado ? <Insignia color="azul">2FA</Insignia> : null}
                </div>
              </td>
              <td className="px-4 py-3 text-panel-muted">{fecha(usuario.creado_en)}</td>
              <td className="px-4 py-3"><AccionesUsuario usuario={usuario} roles={nombresRol} tono={tono} /></td>
            </tr>
          ))}
        </Tabla>
      )}
    </div>
  );
}
