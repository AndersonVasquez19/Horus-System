import { Suspense } from 'react';
import { obtenerCursos } from '@/lib/horus-api';
import CursosView, { type EstadoCatalogo } from './cursos-view';

/**
 * El catálogo sale de Laravel (solo fichas publicadas, vigentes y autorizadas) y se pide en cada visita desde el
 * servidor de Next: el navegador nunca ve el token ni la URL de la API. Mientras llega se envía el armazón con el
 * aviso «cargando»; si Laravel no responde, la página lo dice y sigue ofreciendo contacto.
 */
async function CatalogoDesdeLaravel() {
  const resultado = await obtenerCursos();

  let estado: EstadoCatalogo = 'ok';
  if (!resultado.ok) estado = 'no_disponible';
  else if (!resultado.data.habilitado) estado = 'apagado';

  return <CursosView cursos={resultado.ok ? resultado.data.cursos : []} estado={estado} />;
}

export default function CursosPage() {
  return (
    <Suspense fallback={<CursosView cursos={[]} estado="cargando" />}>
      <CatalogoDesdeLaravel />
    </Suspense>
  );
}
