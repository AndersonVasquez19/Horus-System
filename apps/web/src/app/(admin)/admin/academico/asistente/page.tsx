import { Suspense } from 'react';
import { ProbadorAsistente } from '@/components/compartido/probador-asistente';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoVacio } from '@/components/shared/ui/estados';
import { Insignia } from '@/components/shared/ui/insignia';
import { EsqueletoListado, Seccion, Tabla } from '@/components/shared/ui/tabla';
import { consultarPanel } from '@/lib/panel/api-servidor';

export const metadata = { title: 'Asistente virtual' };

interface Datos {
  intenciones: { nombre: string; respuesta: string; prioridad: number; activo: boolean }[];
  registros: { id: number; fecha_local: string; mensaje: string; respuesta: string; intencion: string | null; fuente: string | null }[];
}

const FUENTES: Record<string, string> = { advisor: 'Horus AI', respaldo: 'Contingencia institucional' };

export default function AsistentePage() {
  return (
    <>
      <EncabezadoPagina
        titulo="Asistente virtual"
        descripcion="Prueba el asistente y revisa sus últimas conversaciones."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Asistente virtual' }]}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Cargando el asistente" />}>
        <Contenido />
      </Suspense>
    </>
  );
}

async function Contenido() {
  const resultado = await consultarPanel<{ data: Datos }>('compartido/asistente', '/admin/academico/asistente');
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { intenciones, registros } = resultado.datos.data;

  return (
    <div className="space-y-8">
      <Seccion id="titulo-probador" titulo="Probador" descripcion="Responde con Horus AI y, si no está disponible, con la contingencia institucional.">
        <ProbadorAsistente />
      </Seccion>

      <section aria-labelledby="titulo-registros" className="space-y-3">
        <h2 id="titulo-registros" className="font-heading text-base font-semibold text-panel-text">
          Últimas conversaciones
        </h2>
        {registros.length === 0 ? (
          <EstadoVacio titulo="Todavía no hay conversaciones" texto="Aparecerán aquí las del sitio público y las de este probador." />
        ) : (
          <Tabla titulo={`Últimas conversaciones (${registros.length})`} columnas={['Fecha (Lima)', 'Mensaje', 'Respuesta', 'Intención', 'Origen']}>
            {registros.map((registro) => (
              <tr key={registro.id} className="align-top">
                <td className="whitespace-nowrap px-4 py-3 text-panel-muted">{registro.fecha_local}</td>
                <td className="max-w-xs whitespace-pre-line px-4 py-3 font-medium text-panel-text">{registro.mensaje}</td>
                <td className="max-w-md whitespace-pre-line px-4 py-3 text-panel-muted">{registro.respuesta}</td>
                <td className="px-4 py-3">
                  {registro.intencion ? <Insignia color="azul">{registro.intencion.replaceAll('_', ' ')}</Insignia> : <span className="text-panel-muted">—</span>}
                </td>
                <td className="px-4 py-3 text-panel-muted">{(registro.fuente && FUENTES[registro.fuente]) ?? 'No registrado'}</td>
              </tr>
            ))}
          </Tabla>
        )}
      </section>

      {intenciones.length > 0 ? (
        <section aria-labelledby="titulo-intenciones" className="space-y-3">
          <h2 id="titulo-intenciones" className="font-heading text-base font-semibold text-panel-text">
            Intenciones configuradas
          </h2>
          <Tabla titulo={`Intenciones (${intenciones.length})`} columnas={['Intención', 'Prioridad', 'Estado']}>
            {intenciones.map((intencion) => (
              <tr key={intencion.nombre}>
                <td className="px-4 py-3">
                  <p className="font-medium text-panel-text">{intencion.nombre.replaceAll('_', ' ')}</p>
                  <p className="mt-0.5 max-w-xl truncate text-xs text-panel-muted">{intencion.respuesta}</p>
                </td>
                <td className="px-4 py-3 tabular-nums">{intencion.prioridad}</td>
                <td className="px-4 py-3">{intencion.activo ? <Insignia color="verde">Activa</Insignia> : <Insignia color="gris">Inactiva</Insignia>}</td>
              </tr>
            ))}
          </Tabla>
        </section>
      ) : null}
    </div>
  );
}
