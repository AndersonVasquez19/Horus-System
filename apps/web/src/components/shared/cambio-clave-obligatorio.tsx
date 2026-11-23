'use client';

import { KeyRound, LogOut } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { CampoClave } from '@/components/shared/ui/campo';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { errorDeCampo, llamarBff } from '@/lib/panel/cliente';
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
  const [saliendo, setSaliendo] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);

  async function cerrarSesion() {
    if (saliendo) return;
    setSaliendo(true);
    await llamarBff('/api/bff/auth/logout', { cuerpo: {} });
    router.replace('/login');
    router.refresh();
  }

  async function enviar(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (guardando) return;

    const errores: Record<string, string[]> = {};
    if (actual === '') {
      errores.current_password = ['Escribe tu contraseña temporal actual.'];
    }
    if (nueva.length < 10) {
      errores.password = ['La nueva contraseña debe tener al menos 10 caracteres.'];
    }
    if (nueva !== confirmacion) {
      errores.password_confirmation = ['La confirmación de la contraseña no coincide.'];
    }
    if (Object.keys(errores).length > 0) {
      setError({ message: 'Revisa los campos marcados.', code: 'validacion', errors: errores });
      return;
    }

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
        <CampoClave
          tono={tono}
          etiqueta="Contraseña temporal actual"
          value={actual}
          onChange={(e) => setActual(e.target.value)}
          error={errorDeCampo(error, 'current_password')}
          autoComplete="current-password"
          required
          autoFocus
        />
        <CampoClave
          tono={tono}
          etiqueta="Nueva contraseña"
          politica
          value={nueva}
          onChange={(e) => setNueva(e.target.value)}
          error={errorDeCampo(error, 'password')}
          autoComplete="new-password"
          required
        />
        <CampoClave
          tono={tono}
          etiqueta="Confirmar nueva contraseña"
          value={confirmacion}
          onChange={(e) => setConfirmacion(e.target.value)}
          error={errorDeCampo(error, 'password_confirmation')}
          autoComplete="new-password"
          required
        />
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-panel-border pt-4">
          <Boton
            tono={tono}
            variante="fantasma"
            type="button"
            cargando={saliendo}
            icono={<LogOut className="h-4 w-4" aria-hidden="true" />}
            onClick={cerrarSesion}
          >
            Cerrar sesión
          </Boton>
          <Boton tono={tono} type="submit" cargando={guardando}>Guardar contraseña</Boton>
        </div>
      </form>
    </div>
  );
}
