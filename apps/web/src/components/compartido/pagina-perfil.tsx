import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido } from '@/components/shared/ui/estados';
import type { TonoUi } from '@/components/shared/ui/boton';
import type { PerfilPropio, SesionActiva } from '@/features/notificaciones/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { FormularioPerfil } from './formulario-perfil';
import { SesionesActivas } from './sesiones-activas';

/** Perfil propio y sesiones activas. La monta cada panel bajo su propia ruta: `/admin/<panel>/perfil`. */
export function PaginaPerfil({ tono }: { tono: TonoUi }) {
  return (
    <>
      <EncabezadoPagina
        titulo="Mi perfil"
        descripcion="Tus datos de acceso y los dispositivos con sesión abierta."
        migas={[{ etiqueta: 'Inicio', href: `/admin/${tono}` }, { etiqueta: 'Mi perfil' }]}
      />
      <Suspense fallback={<div className="h-72 animate-pulse rounded-xl border border-panel-border bg-panel-surface" aria-busy="true" aria-label="Cargando tu perfil" />}>
        <Contenido tono={tono} />
      </Suspense>
    </>
  );
}

async function Contenido({ tono }: { tono: TonoUi }) {
  const ruta = `/admin/${tono}/perfil`;
  const [perfil, sesiones] = await Promise.all([
    consultarPanel<{ data: PerfilPropio }>('compartido/perfil', ruta),
    consultarPanel<{ data: { disponible: boolean; sesiones: SesionActiva[] } }>('compartido/perfil/sesiones', ruta),
  ]);

  if (perfil.estado === 'prohibido' || sesiones.estado === 'prohibido') return <EstadoProhibido volver={`/admin/${tono}`} />;
  if (perfil.estado !== 'ok' || sesiones.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  return (
    <div className="grid gap-6 xl:grid-cols-2">
      <FormularioPerfil perfil={perfil.datos.data} tono={tono} />
      <SesionesActivas sesiones={sesiones.datos.data.sesiones} disponible={sesiones.datos.data.disponible} tono={tono} />
    </div>
  );
}
