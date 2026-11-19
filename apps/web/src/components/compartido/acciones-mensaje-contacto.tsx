'use client';

import { useRouter } from 'next/navigation';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';

/** Marca un mensaje de «Contáctanos» como atendido (permiso `reclamaciones.gestionar`; Laravel lo vuelve a comprobar). */
export function BotonAtenderMensaje({ id, tono }: { id: string; tono: TonoUi }) {
  const router = useRouter();
  const puede = usePuede('reclamaciones.gestionar');
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [cargando, setCargando] = useState(false);

  if (!puede) return null;

  async function atender() {
    setCargando(true);
    const resultado = await ejecutar<{ message: string }>(`compartido/reclamaciones-mensajes/${id}/atender`, 'POST');
    setCargando(false);
    if (!resultado.ok) {
      mostrar('error', resultado.error.message);
      return;
    }
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  return (
    <Boton tono={tono} variante="secundario" cargando={cargando} onClick={atender} icono={<Check className="h-4 w-4" aria-hidden="true" />}>
      Marcar como atendido
    </Boton>
  );
}

/** Marca en bloque los mensajes nuevos visibles en la página actual como atendidos. */
export function BotonAtenderVisibles({ identificadores, tono }: { identificadores: string[]; tono: TonoUi }) {
  const router = useRouter();
  const puede = usePuede('reclamaciones.gestionar');
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [cargando, setCargando] = useState(false);

  if (!puede || identificadores.length === 0) return null;

  async function atenderTodos() {
    setCargando(true);
    const resultado = await ejecutar<{ message: string }>('compartido/reclamaciones-mensajes/atender-lote', 'POST', { identificadores });
    setCargando(false);
    if (!resultado.ok) {
      mostrar('error', resultado.error.message);
      return;
    }
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  return (
    <Boton tono={tono} variante="secundario" cargando={cargando} onClick={atenderTodos} icono={<Check className="h-4 w-4" aria-hidden="true" />}>
      Marcar visibles como atendidos ({identificadores.length})
    </Boton>
  );
}
