import { NextRequest, NextResponse } from 'next/server';

export async function POST(req: NextRequest, context: { params: Promise<{ token: string }> }) {
  const { token } = await context.params;
  const base = (process.env.HORUS_API_URL || '').replace(/\/+$/, '');
  const serviceToken = process.env.HORUS_WEB_SERVICE_TOKEN || '';

  if (!base || serviceToken.length < 32) {
    return NextResponse.json({ message: 'Servicio no disponible.' }, { status: 503 });
  }

  let body: unknown;
  try {
    body = await req.json();
  } catch {
    return NextResponse.json({ message: 'Solicitud inválida.' }, { status: 400 });
  }

  const upstream = await fetch(`${base}/api/public/v1/encuestas/${encodeURIComponent(token)}`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Horus-Web-Token': serviceToken,
    },
    body: JSON.stringify(body),
    cache: 'no-store',
  });

  const data = await upstream.json().catch(() => ({ message: 'Error al registrar.' }));
  return NextResponse.json(data, { status: upstream.status });
}
