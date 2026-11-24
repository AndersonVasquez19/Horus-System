'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { CampoArea } from '@/components/shared/ui/campo';

/** Formulario para agregar una nota interna de seguimiento al estudiante (`estudiantes.crear` o `estudiantes.editar`). */
export function FormularioNotaSeguimiento({ estudianteId }: { estudianteId: number }) {
  const puedeCrear = usePuede('estudiantes.crear');
  const puedeEditar = usePuede('estudiantes.editar');
  const puede = puedeCrear || puedeEditar;
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [texto, setTexto] = useState('');
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (!puede) return null;

  async function guardar() {
    if (texto.trim().length < 3) {
      setError('La nota debe tener al menos 3 caracteres.');
      return;
    }
    setOcupado(true);
    setError(null);
    const r = await ejecutar(`academico/estudiantes/${estudianteId}/notas`, 'POST', { texto: texto.trim() });
    setOcupado(false);
    if (!r.ok) {
      setError(r.error.message);
      return;
    }
    setTexto('');
    mostrar('exito', 'Nota agregada al seguimiento.');
    router.refresh();
  }

  return (
    <div className="space-y-3">
      {error ? <Alerta tipo="error">{error}</Alerta> : null}
      <CampoArea etiqueta="Nueva nota interna" rows={2} maxLength={500} value={texto} onChange={(e) => setTexto(e.target.value)} ayuda="Solo la ve el personal del panel. No se puede editar ni borrar después." />
      <Boton cargando={ocupado} onClick={() => void guardar()}>Agregar nota</Boton>
    </div>
  );
}
