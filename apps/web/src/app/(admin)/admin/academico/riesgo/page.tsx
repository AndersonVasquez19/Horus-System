import Link from 'next/link';
import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoVacio } from '@/components/shared/ui/estados';
import { Insignia } from '@/components/shared/ui/insignia';
import { EsqueletoListado, Tabla } from '@/components/shared/ui/tabla';
import type { RiesgoDesercion } from '@/features/academico/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';

export const metadata = { title: 'Riesgo de deserción · Gestión académica' };

const RUTA = '/admin/academico/riesgo';

export default function RiesgoPage() {
  return (
    <>
      <EncabezadoPagina
        titulo="Riesgo de deserción"
        descripcion="Estudiantes de ediciones en curso con faltas seguidas, asistencia baja o deuda vencida. Es una ayuda para priorizar llamadas, no un juicio sobre la persona."
        migas={[{ etiqueta: 'Inicio', href: '/admin/academico' }, { etiqueta: 'Riesgo de deserción' }]}
      />
      <Suspense fallback={<EsqueletoListado etiqueta="Calculando riesgo de deserción" />}>
        <Lista />
      </Suspense>
    </>
  );
}

async function Lista() {
  const resultado = await consultarPanel<{ data: RiesgoDesercion }>('academico/riesgo-desercion', RUTA);

  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/academico" />;
  if (resultado.estado !== 'ok') return <EstadoError texto="No se pudo calcular el riesgo de deserción. Inténtalo de nuevo en unos minutos." />;

  const { rows, resumen } = resultado.datos.data;
  if (rows.length === 0) {
    return <EstadoVacio titulo="Sin estudiantes en riesgo" texto="Ningún matriculado de una edición en curso llega al umbral de riesgo medio." />;
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-panel-text">
        <strong>{resumen.alto}</strong> en riesgo alto · <strong>{resumen.medio}</strong> en riesgo medio.
      </p>
      <Tabla titulo={`Estudiantes en riesgo (${rows.length})`} columnas={['Estudiante', 'Edición', 'Riesgo', 'Por qué']}>
        {rows.map((f) => (
          <tr key={f.matricula_id} className="hover:bg-academico-50/50">
            <td className="px-4 py-3">
              <Link href={`/admin/academico/matriculas/${f.matricula_id}`} className="font-semibold text-academico-700 hover:underline">
                {f.estudiante}
              </Link>
            </td>
            <td className="whitespace-nowrap px-4 py-3 text-sm">
              <Link href={`/admin/academico/convocatorias/${f.edicion_identificador}`} className="hover:underline">
                {f.edicion}
              </Link>
            </td>
            <td className="whitespace-nowrap px-4 py-3">
              <Insignia color={f.nivel === 'alto' ? 'rojo' : 'ambar'}>
                {f.nivel === 'alto' ? 'Alto' : 'Medio'} · {f.puntaje}
              </Insignia>
            </td>
            <td className="px-4 py-3 text-sm text-panel-text">{f.factores.map((x) => x.texto).join(' · ')}</td>
          </tr>
        ))}
      </Tabla>
    </div>
  );
}
