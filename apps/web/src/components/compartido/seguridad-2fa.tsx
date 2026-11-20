'use client';

import { ShieldCheck, ShieldOff } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { Campo, CampoClave } from '@/components/shared/ui/campo';
import { Insignia } from '@/components/shared/ui/insignia';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { errorDeCampo } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';

/** Activación, confirmación y desactivación de 2FA TOTP + códigos de recuperación en Mi perfil. */
export function Seguridad2fa({ activo, tono }: { activo: boolean; tono: TonoUi }) {
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [paso, setPaso] = useState<'inactivo' | 'pendiente_confirmar' | 'desactivar'>('inactivo');
  const [clave, setClave] = useState('');
  const [codigo, setCodigo] = useState('');
  const [secreto, setSecreto] = useState<string | null>(null);
  const [uri, setUri] = useState<string | null>(null);
  const [codigosRecuperacion, setCodigosRecuperacion] = useState<string[] | null>(null);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);

  async function iniciar(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setOcupado(true);
    setError(null);
    const r = await ejecutar<{ message: string; data: { secreto: string; uri: string } }>('compartido/perfil/2fa/iniciar', 'POST', {
      current_password: clave,
    });
    setOcupado(false);
    if (!r.ok) {
      setError(r.error);
      return;
    }
    setClave('');
    setSecreto(r.datos.data.secreto);
    setUri(r.datos.data.uri);
    setPaso('pendiente_confirmar');
  }

  async function confirmar(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setOcupado(true);
    setError(null);
    const r = await ejecutar<{ message: string; data: { codigos_recuperacion: string[] } }>('compartido/perfil/2fa/confirmar', 'POST', {
      codigo: codigo.trim(),
    });
    setOcupado(false);
    if (!r.ok) {
      setError(r.error);
      return;
    }
    setCodigo('');
    setSecreto(null);
    setUri(null);
    setPaso('inactivo');
    setCodigosRecuperacion(r.datos.data.codigos_recuperacion);
    mostrar('exito', r.datos.message);
    router.refresh();
  }

  async function desactivar(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setOcupado(true);
    setError(null);
    const r = await ejecutar<{ message: string }>('compartido/perfil/2fa/desactivar', 'POST', {
      current_password: clave,
      codigo: codigo.trim(),
    });
    setOcupado(false);
    if (!r.ok) {
      setError(r.error);
      return;
    }
    setClave('');
    setCodigo('');
    setCodigosRecuperacion(null);
    setPaso('inactivo');
    mostrar('exito', r.datos.message);
    router.refresh();
  }

  return (
    <section aria-labelledby="titulo-2fa" className="space-y-4 rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          {activo ? <ShieldCheck className="h-4 w-4 text-emerald-700" aria-hidden="true" /> : <ShieldOff className="h-4 w-4 text-panel-muted" aria-hidden="true" />}
          <h2 id="titulo-2fa" className="font-heading text-base font-semibold text-panel-text">Verificación en dos pasos (2FA)</h2>
        </div>
        <Insignia color={activo ? 'verde' : 'gris'}>{activo ? 'Activa' : 'Inactiva'}</Insignia>
      </div>
      <p className="text-xs text-panel-muted">
        Protege tu cuenta solicitando un código temporal TOTP (Google Authenticator, Bitwarden, Authy) cada vez que inicies sesión.
      </p>

      {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}

      {codigosRecuperacion ? (
        <div className="space-y-2 rounded-lg border border-emerald-200 bg-emerald-50/70 p-4 text-xs text-emerald-950">
          <p className="font-semibold">Códigos de recuperación (guárdalos ahora; no volverán a mostrarse):</p>
          <div className="grid grid-cols-2 gap-1.5 font-mono text-xs sm:grid-cols-4">
            {codigosRecuperacion.map((c) => (
              <span key={c} className="rounded border border-emerald-300 bg-white px-2 py-1 text-center">{c}</span>
            ))}
          </div>
          <div className="flex justify-end pt-1">
            <Boton tono={tono} variante="secundario" type="button" onClick={() => setCodigosRecuperacion(null)}>Listo, los guardé</Boton>
          </div>
        </div>
      ) : null}

      {!activo && paso === 'inactivo' ? (
        <form onSubmit={iniciar} noValidate className="space-y-3 border-t border-panel-border pt-3">
          <CampoClave
            tono={tono}
            etiqueta="Contraseña actual para configurar 2FA"
            value={clave}
            onChange={(e) => setClave(e.target.value)}
            error={errorDeCampo(error, 'current_password')}
            autoComplete="current-password"
            required
          />
          <div className="flex justify-end">
            <Boton tono={tono} type="submit" cargando={ocupado}>Configurar 2FA</Boton>
          </div>
        </form>
      ) : null}

      {paso === 'pendiente_confirmar' && secreto ? (
        <form onSubmit={confirmar} noValidate className="space-y-3 border-t border-panel-border pt-3">
          <div className="space-y-2 rounded-lg border border-panel-border bg-panel-bg p-3 text-xs">
            <p className="font-medium text-panel-text">1. Añade esta clave manual en tu app autenticadora (TOTP · SHA-1 · 6 dígitos):</p>
            <div className="flex flex-wrap items-center gap-2">
              <code className="rounded bg-white px-2 py-1 font-mono text-sm font-semibold text-panel-text">{secreto}</code>
              <button
                type="button"
                onClick={() => { void navigator.clipboard?.writeText(secreto); mostrar('exito', 'Clave copiada.'); }}
                className="font-semibold text-academico-700 hover:underline"
              >
                Copiar clave
              </button>
              {uri ? (
                <button
                  type="button"
                  onClick={() => { void navigator.clipboard?.writeText(uri); mostrar('exito', 'Enlace otpauth copiado.'); }}
                  className="font-semibold text-panel-muted hover:underline"
                >
                  Copiar enlace otpauth://
                </button>
              ) : null}
            </div>
          </div>
          <Campo
            tono={tono}
            etiqueta="2. Ingresa el código de 6 dígitos generado"
            inputMode="numeric"
            maxLength={6}
            value={codigo}
            onChange={(e) => setCodigo(e.target.value)}
            error={errorDeCampo(error, 'codigo')}
            required
          />
          <div className="flex justify-end gap-2">
            <Boton tono={tono} variante="secundario" type="button" onClick={() => { setPaso('inactivo'); setSecreto(null); setUri(null); }}>Cancelar</Boton>
            <Boton tono={tono} type="submit" cargando={ocupado}>Activar 2FA</Boton>
          </div>
        </form>
      ) : null}

      {activo && paso === 'inactivo' ? (
        <div className="flex justify-end border-t border-panel-border pt-3">
          <Boton tono={tono} variante="secundario" type="button" onClick={() => { setError(null); setPaso('desactivar'); }}>
            Desactivar 2FA
          </Boton>
        </div>
      ) : null}

      {activo && paso === 'desactivar' ? (
        <form onSubmit={desactivar} noValidate className="space-y-3 border-t border-panel-border pt-3">
          <CampoClave
            tono={tono}
            etiqueta="Contraseña actual"
            value={clave}
            onChange={(e) => setClave(e.target.value)}
            error={errorDeCampo(error, 'current_password')}
            autoComplete="current-password"
            required
          />
          <Campo
            tono={tono}
            etiqueta="Código 2FA o código de recuperación"
            value={codigo}
            onChange={(e) => setCodigo(e.target.value)}
            error={errorDeCampo(error, 'codigo')}
            required
          />
          <div className="flex justify-end gap-2">
            <Boton tono={tono} variante="secundario" type="button" onClick={() => setPaso('inactivo')}>Cancelar</Boton>
            <Boton tono={tono} type="submit" cargando={ocupado}>Confirmar desactivación</Boton>
          </div>
        </form>
      ) : null}
    </section>
  );
}
