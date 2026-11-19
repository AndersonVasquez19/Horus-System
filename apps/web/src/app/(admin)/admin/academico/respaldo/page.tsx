import { Suspense } from 'react';
import { ExportarRespaldo } from '@/components/compartido/exportar-respaldo';
import { Alerta } from '@/components/shared/ui/alerta';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido } from '@/components/shared/ui/estados';
import { EsqueletoFicha, Seccion } from '@/components/shared/ui/tabla';
import { consultarPanel } from '@/lib/panel/api-servidor';

export const metadata = { title: 'Exportar datos' };

export default function RespaldoPage() {
  return (
    <>
      <EncabezadoPagina
        titulo="Exportar datos"
        descripcion="Descarga una exportación de las tablas principales en archivos CSV."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Exportar datos' }]}
      />
      <Suspense fallback={<EsqueletoFicha etiqueta="Cargando" />}>
        <Contenido />
      </Suspense>
    </>
  );
}

async function Contenido() {
  const resultado = await consultarPanel<{ data: { tablas: string[]; aviso: string } }>('compartido/respaldo', '/admin/academico/respaldo');
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { tablas, aviso } = resultado.datos.data;

  return (
    <div className="max-w-3xl space-y-6">
      <Alerta tipo="aviso" titulo="No es una copia de seguridad">
        {aviso} Para un respaldo completo y restaurable se usa la copia de la base de datos en el servidor.
      </Alerta>

      <Seccion id="titulo-tablas" titulo="Qué incluye" descripcion="Un archivo CSV por tabla, más un resumen (manifest.json) de lo exportado y lo omitido.">
        <ul className="grid gap-2 sm:grid-cols-2">
          {tablas.map((tabla) => (
            <li key={tabla} className="rounded-lg border border-panel-border bg-panel-bg px-3 py-2 font-mono text-sm text-panel-text">
              {tabla}.csv
            </li>
          ))}
        </ul>
        <p className="mt-4 text-sm text-panel-muted">
          Se omiten las columnas de acceso (contraseñas, tokens) y los ajustes secretos se enmascaran. El archivo se genera al momento, no se guarda en el servidor y la descarga queda registrada en la auditoría.
        </p>
      </Seccion>

      <ExportarRespaldo />
    </div>
  );
}
