import Link from 'next/link';
import { History, RotateCcw } from 'lucide-react';
import { Suspense } from 'react';
import { ExportarRespaldo, type SeccionRespaldo } from '@/components/compartido/exportar-respaldo';
import { Alerta } from '@/components/shared/ui/alerta';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido } from '@/components/shared/ui/estados';
import { EsqueletoFicha, Seccion } from '@/components/shared/ui/tabla';
import { consultarPanel } from '@/lib/panel/api-servidor';

export const metadata = { title: 'Exportar datos' };

interface RegistroExportacion {
  id: number;
  fecha_local: string;
  usuario: string;
  tablas: number | null;
}

export default function RespaldoPage() {
  return (
    <>
      <EncabezadoPagina
        titulo="Exportar datos"
        descripcion="Descarga un archivo ZIP con hojas CSV agrupadas por área, con conteo real de registros y filtros por fecha."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Exportar datos' }]}
      />
      <Suspense fallback={<EsqueletoFicha etiqueta="Cargando" />}>
        <Contenido />
      </Suspense>
    </>
  );
}

async function Contenido() {
  const resultado = await consultarPanel<{
    data: { contenido: SeccionRespaldo[]; historial?: RegistroExportacion[]; aviso: string };
  }>('compartido/respaldo', '/admin/academico/respaldo');
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { contenido, historial = [], aviso } = resultado.datos.data;

  return (
    <div className="space-y-6">
      <Alerta tipo="aviso" titulo="No es una copia de seguridad restaurable">
        {aviso} Para una copia completa de PostgreSQL que permita restaurar todo el servidor, solicítala al equipo técnico.
      </Alerta>

      <div className="grid gap-6 xl:grid-cols-3">
        <div className="xl:col-span-2">
          <ExportarRespaldo secciones={contenido} />
        </div>

        <div className="space-y-6">
          <Seccion
            id="titulo-ultimas-exportaciones"
            titulo="Últimas exportaciones"
            descripcion="Registro reciente de descargas ZIP."
          >
            {historial.length === 0 ? (
              <p className="text-sm text-panel-muted">Todavía no se ha descargado ninguna exportación.</p>
            ) : (
              <ul className="divide-y divide-panel-border text-sm">
                {historial.map((item) => (
                  <li key={item.id} className="flex items-start gap-2.5 py-2.5 first:pt-0 last:pb-0">
                    <History className="mt-0.5 h-4 w-4 shrink-0 text-panel-muted" aria-hidden="true" />
                    <div className="min-w-0">
                      <p className="font-medium text-panel-text">{item.usuario}</p>
                      <p className="text-xs text-panel-muted">
                        {item.fecha_local}
                        {item.tablas !== null ? ` · ${item.tablas} tablas` : ''}
                      </p>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Seccion>

          <Seccion
            id="titulo-recuperar"
            titulo="¿Necesitas restaurar un registro?"
            descripcion="Recupera de forma segura elementos desactivados o eliminados sin tocar la base de datos."
          >
            <ul className="space-y-2 text-sm">
              <li>
                <Link
                  href="/admin/academico/estudiantes?eliminados=1"
                  className="flex items-center gap-2 rounded-lg border border-panel-border bg-panel-bg px-3 py-2 font-medium text-panel-text transition hover:border-academico-300 hover:text-academico-800"
                >
                  <RotateCcw className="h-4 w-4 text-academico-700" aria-hidden="true" />
                  Estudiantes desactivados
                </Link>
              </li>
              <li>
                <Link
                  href="/admin/academico/cursos?eliminados=1"
                  className="flex items-center gap-2 rounded-lg border border-panel-border bg-panel-bg px-3 py-2 font-medium text-panel-text transition hover:border-academico-300 hover:text-academico-800"
                >
                  <RotateCcw className="h-4 w-4 text-academico-700" aria-hidden="true" />
                  Cursos archivados
                </Link>
              </li>
              <li>
                <Link
                  href="/admin/academico/matriculas?eliminados=1"
                  className="flex items-center gap-2 rounded-lg border border-panel-border bg-panel-bg px-3 py-2 font-medium text-panel-text transition hover:border-academico-300 hover:text-academico-800"
                >
                  <RotateCcw className="h-4 w-4 text-academico-700" aria-hidden="true" />
                  Matrículas eliminadas
                </Link>
              </li>
            </ul>
          </Seccion>
        </div>
      </div>
    </div>
  );
}
