import { NextResponse } from 'next/server';
import { obtenerCursos } from '@/lib/horus-api';

/**
 * Cursos publicados para el menú del orientador del chat. Solo título, modalidad y fin de vigencia: es la misma
 * información que ya muestra /educacion/cursos. El token de servicio no sale del servidor y, si Laravel no responde,
 * el navegador recibe `cursos: null` (distinto de «sin cursos»), nunca una lista inventada.
 */
export async function GET() {
  const resultado = await obtenerCursos();
  const cursos = resultado.ok
    ? resultado.data.cursos.map((c) => ({ titulo: c.titulo, modalidad: c.modalidad, vigente_hasta: c.vigente_hasta }))
    : null;
  return NextResponse.json({ cursos }, { headers: { 'Cache-Control': 'no-store' } });
}
