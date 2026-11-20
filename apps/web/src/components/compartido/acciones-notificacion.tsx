'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { CheckCheck } from 'lucide-react';
import { useState } from 'react';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';

/** Marca una notificación como leída. La lectura de una general es propia de quien la marca (lo decide Laravel). */
export function BotonMarcarLeida({ id, tono }: { id: number; tono: TonoUi }) {
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [cargando, setCargando] = useState(false);

  async function marcar() {
    setCargando(true);
    const resultado = await ejecutar(`compartido/notificaciones/${id}/leida`, 'POST');
    setCargando(false);
    if (!resultado.ok) {
      mostrar('error', resultado.error.message);
      return;
    }
    router.refresh();
  }

  return (
    <Boton tono={tono} variante="fantasma" cargando={cargando} onClick={marcar} className="px-2 py-1 text-xs">
      Marcar como leída
    </Boton>
  );
}

/** Enlace de una notificación: al abrirlo, si estaba sin leer, la marca como leída (sin esperar a la respuesta). */
export function EnlaceNotificacion({ id, href, texto, leida }: { id: number; href: string; texto: string; leida: boolean }) {
  const { ejecutar } = useOperacionPanel();
  return (
    <Link
      href={href}
      onClick={() => {
        if (!leida) void ejecutar(`compartido/notificaciones/${id}/leida`, 'POST');
      }}
      className="text-xs font-semibold text-panel-text underline"
    >
      {texto}
    </Link>
  );
}

/** Marca como leídas todas las pendientes del usuario; no afecta a lo que ven los demás. */
export function BotonMarcarTodas({ tono, pendientes }: { tono: TonoUi; pendientes: number }) {
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [cargando, setCargando] = useState(false);

  if (pendientes === 0) return null;

  async function marcar() {
    setCargando(true);
    const resultado = await ejecutar<{ message?: string }>('compartido/notificaciones/leidas', 'POST');
    setCargando(false);
    if (!resultado.ok) {
      mostrar('error', resultado.error.message);
      return;
    }
    mostrar('exito', resultado.datos.message ?? 'Notificaciones marcadas como leídas.');
    router.refresh();
  }

  return (
    <Boton tono={tono} variante="secundario" cargando={cargando} onClick={marcar} icono={<CheckCheck className="h-4 w-4" aria-hidden="true" />}>
      Marcar todas como leídas
    </Boton>
  );
}
