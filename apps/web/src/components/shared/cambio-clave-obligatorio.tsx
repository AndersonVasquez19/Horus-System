'use client';

import { KeyRound } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { Campo } from '@/components/shared/ui/campo';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { errorDeCampo } from '@/lib/panel/cliente';
import type { ErrorPanel, SesionPanel } from '@/lib/panel/tipos';

/**
 * Pantalla de bloqueo controlado cuando la cuenta tiene contraseña temporal (`debe_cambiar_clave = true`).
 * Usa el mismo endpoint `PUT compartido/perfil` que Mi perfil y exige la contraseña actual.
 */
export function CambioClaveObligatorio({ sesion, tono }: { sesion: SesionPanel; tono: TonoUi }) {
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [actual, setActual] = useState('');
  const [nueva, setNueva] = useState('');
  const [confirmacion, setConfirmacion] = useState('');
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);

  async function enviar(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setGuardando(true);
    setError(null);
    const r = await ejecutar<{ message?: string }>('compartido/perfil', 'PUT', {
      name: sesion.usuario.nombre,
      email: sesion.usuario.email,
      current_password: actual,
      password: nueva,
      password_confirmation: confirmacion,
    });
    setGuardando(false);
    if (!r.ok) {
      setError(r.error);
      return;
    }
    mostrar('exito', 'Contraseña actualizada. Ya puedes usar el panel.');
    router.refresh();
  }

  return (
    <div className="mx-auto my-8 w-full max-w-lg rounded-2xl border border-panel-border bg-panel-surface p-6 shadow-sm">
      <div className="flex items-start gap-3">
        <div className="rounded-xl bg-amber-100 p-2.5 text-amber-900">
          <KeyRound className="h-5 w-5" aria-hidden="true" />
        </div>
        <div>
          <h1 className="font-heading text-lg font-semibold text-panel-text">Actualiza tu contraseña temporal</h1>
          <p className="mt-1 text-sm text-panel-muted">
            Tu cuenta inició con una contraseña temporal. Define una contraseña nueva y personal antes de acceder a los módulos.
          </p>
        </div>
      </div>

      <form onSubmit={enviar} noValidate className="mt-5 space-y-4">
        {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
        <Campo
          tono={tono}
          etiqueta="Contraseña temporal actual"
          type="password"
          value={actual}
          onChange={(e) => setActual(e.target.value)}
          error={errorDeCampo(error, 'current_password')}
          autoComplete="current-password"
          required
          autoFocus
        />
        <Campo
          tono={tono}
          etiqueta="Nueva contraseña"
          type="password"
          value={nueva}
          onChange={(e) => setNueva(e.target.value)}
          error={errorDeCampo(error, 'password')}
          autoComplete="new-password"
          required
        />
        <Campo
          tono={tono}
          etiqueta="Confirmar nueva contraseña"
          type="password"
          value={confirmacion}
          onChange={(e) => setConfirmacion(e.target.value)}
          autoComplete="new-password"
          required
        />
        <div className="flex justify-end border-t border-panel-border pt-4">
          <Boton tono={tono} type="submit" cargando={guardando}>Guardar contraseña</Boton>
        </div>
      </form>
    </div>
  );
}
