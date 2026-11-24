'use client';

import { LockOpen } from 'lucide-react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { CampoArea } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';

/**
 * Reapertura de un acta cerrada (`convocatorias.cerrar`): primero se solicita a Gerencia con un motivo; cuando la aprueban (en
 * Aprobaciones), «Reabrir acta» la deja en curso. Cada aprobación sirve para una sola reapertura.
 */
export function AccionesReaperturaActa({ identificador }: { identificador: string }) {
  const puede = usePuede('convocatorias.cerrar');
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [motivo, setMotivo] = useState('');
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (!puede) return null;

  const base = `academico/convocatorias/${encodeURIComponent(identificador)}/acta`;

  async function solicitar() {
    if (motivo.trim().length < 10) {
      setError('Explica el motivo (mínimo 10 caracteres).');
      return;
    }
    setOcupado(true);
    setError(null);
    const r = await ejecutar<{ message?: string }>(`${base}/reapertura`, 'POST', { motivo: motivo.trim() });
    setOcupado(false);
    if (!r.ok) {
      setError(r.error.message);
      return;
    }
    setAbierto(false);
    setMotivo('');
    mostrar('exito', r.datos?.message ?? 'Solicitud enviada a Gerencia.');
  }

  async function reabrir() {
    setOcupado(true);
    const r = await ejecutar<{ message?: string }>(`${base}/reabrir`, 'POST', {});
    setOcupado(false);
    if (!r.ok) {
      mostrar('error', r.error.message);
      return;
    }
    mostrar('exito', r.datos?.message ?? 'Acta reabierta.');
    router.refresh();
  }

  return (
    <>
      <Boton variante="secundario" icono={<LockOpen className="h-4 w-4" aria-hidden="true" />} onClick={() => { setError(null); setAbierto(true); }}>
        Solicitar reapertura
      </Boton>
      <Boton variante="secundario" cargando={ocupado && !abierto} onClick={() => void reabrir()}>
        Reabrir acta aprobada
      </Boton>
      <Dialogo
        abierto={abierto}
        onCerrar={() => setAbierto(false)}
        titulo="Solicitar la reapertura del acta"
        descripcion="Gerencia debe aprobarla en Aprobaciones. Al reabrir se borran las notas finales y se recalculan al cerrar de nuevo."
        bloqueado={ocupado}
      >
        <div className="space-y-4">
          <Alerta tipo="aviso">
            Los certificados ya emitidos no se anulan solos: si cambian las notas, anúlalos y reemítelos.{' '}
            <Link href="/admin/academico/aprobaciones" className="underline">Ver aprobaciones</Link>
          </Alerta>
          <CampoArea etiqueta="Motivo de la reapertura" rows={3} maxLength={500} value={motivo} onChange={(e) => setMotivo(e.target.value)} error={error ?? undefined} />
          <div className="flex justify-end gap-2">
            <Boton variante="secundario" type="button" onClick={() => setAbierto(false)} disabled={ocupado}>Cancelar</Boton>
            <Boton type="button" cargando={ocupado} onClick={() => void solicitar()}>Enviar solicitud</Boton>
          </div>
        </div>
      </Dialogo>
    </>
  );
}
