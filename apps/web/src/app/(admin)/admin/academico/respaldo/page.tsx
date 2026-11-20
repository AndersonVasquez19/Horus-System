import { Suspense } from 'react';
import { ExportarRespaldo, type SeccionRespaldo } from '@/components/compartido/exportar-respaldo';
import { Alerta } from '@/components/shared/ui/alerta';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido } from '@/components/shared/ui/estados';
import { EsqueletoFicha } from '@/components/shared/ui/tabla';
import { consultarPanel } from '@/lib/panel/api-servidor';

export const metadata = { title: 'Exportar datos' };

export default function RespaldoPage() {
  return (
    <>
      <EncabezadoPagina
        titulo="Exportar datos"
        descripcion="Descarga un archivo ZIP con hojas CSV de las secciones seleccionadas del sistema."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Exportar datos' }]}
      />
      <Suspense fallback={<EsqueletoFicha etiqueta="Cargando" />}>
        <Contenido />
      </Suspense>
    </>
  );
}

async function Contenido() {
  const resultado = await consultarPanel<{ data: { contenido: SeccionRespaldo[]; aviso: string } }>('compartido/respaldo', '/admin/academico/respaldo');
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." />;

  const { contenido, aviso } = resultado.datos.data;

  return (
    <div className="max-w-3xl space-y-6">
      <Alerta tipo="aviso" titulo="No es una copia de seguridad">
        {aviso} Para una copia de seguridad completa que permita restaurar todo, pídesela al equipo técnico.
      </Alerta>

      <ExportarRespaldo secciones={contenido} />
    </div>
  );
}
