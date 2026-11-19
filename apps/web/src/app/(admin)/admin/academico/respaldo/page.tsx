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
        descripcion="Descarga un archivo con hojas de cálculo de la información principal del sistema."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Exportar datos' }]}
      />
      <Suspense fallback={<EsqueletoFicha etiqueta="Cargando" />}>
        <Contenido />
      </Suspense>
    </>
  );
}

async function Contenido() {
  const resultado = await consultarPanel<{ data: { contenido: { clave: string; etiqueta: string }[]; aviso: string } }>('compartido/respaldo', '/admin/academico/respaldo');
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { contenido, aviso } = resultado.datos.data;

  return (
    <div className="max-w-3xl space-y-6">
      <Alerta tipo="aviso" titulo="No es una copia de seguridad">
        {aviso} Para una copia de seguridad completa que permita restaurar todo, pídesela al equipo técnico.
      </Alerta>

      <Seccion id="titulo-tablas" titulo="Qué incluye" descripcion="Una hoja de cálculo por cada una de estas secciones, más un resumen de lo incluido y lo omitido.">
        <ul className="grid gap-2 sm:grid-cols-2">
          {contenido.map((seccion) => (
            <li key={seccion.clave} className="rounded-lg border border-panel-border bg-panel-bg px-3 py-2 text-sm text-panel-text">
              {seccion.etiqueta}
            </li>
          ))}
        </ul>
        <p className="mt-4 text-sm text-panel-muted">
          No se incluyen contraseñas ni claves de acceso, y los datos reservados de la configuración se ocultan. El archivo se prepara al momento, no se guarda en el servidor y la descarga queda anotada en el historial de actividad.
        </p>
      </Seccion>

      <ExportarRespaldo />
    </div>
  );
}
