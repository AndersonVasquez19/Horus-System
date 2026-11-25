import { NextResponse } from 'next/server';
import { obtenerCursos } from '@/lib/horus-api';

/**
 * Cursos publicados para el menú del orientador del chat. Solo título, modalidad y fin de vigencia: es la misma
 * información que ya muestra /educacion/cursos. El token de servicio no sale del servidor y, si Laravel no responde,
 * el navegador recibe `cursos: null` (distinto de «sin cursos»), nunca una lista inventada.
 *
 * Una respuesta correcta se reutiliza 60 s en memoria del proceso: el menú no necesita más frescura y así repetir el
 * botón no gasta el límite `publico-web` de Laravel que comparte todo el sitio (auditoría del 25/11/2026). Los fallos
 * no se guardan, para que el siguiente intento vuelva a preguntar.
 */
type CursoMenu = { titulo: string; modalidad: unknown; vigente_hasta: unknown };

const VIGENCIA_MS = 60_000;
let guardado: { cursos: CursoMenu[]; hasta: number } | null = null;

export async function GET() {
  const ahora = Date.now();
  if (guardado === null || guardado.hasta <= ahora) {
    const resultado = await obtenerCursos();
    if (!resultado.ok) {
      return NextResponse.json({ cursos: null }, { headers: { 'Cache-Control': 'no-store' } });
    }
    guardado = {
      cursos: resultado.data.cursos.map((c) => ({ titulo: c.titulo, modalidad: c.modalidad, vigente_hasta: c.vigente_hasta })),
      hasta: ahora + VIGENCIA_MS,
    };
  }
  return NextResponse.json({ cursos: guardado.cursos }, { headers: { 'Cache-Control': 'no-store' } });
}
