'use client';

import Link from 'next/link';
import { KeyRound } from 'lucide-react';
import { useState, useSyncExternalStore, type FormEvent } from 'react';
import { Alerta } from '@/components/shared/ui/alerta';
import { Boton } from '@/components/shared/ui/boton';
import { CampoClave } from '@/components/shared/ui/campo';
import { errorDeCampo, llamarBff } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';

interface Enlace {
  token: string;
  email: string;
}

const CLAVE_ENLACE = 'horus:restablecer-enlace';
let enlaceCache: Enlace | null | undefined;

function suscribirVacio() {
  return () => {};
}

function limpiarEnlaceGuardado(): void {
  enlaceCache = null;
  try {
    window.sessionStorage.removeItem(CLAVE_ENLACE);
  } catch {
    // sessionStorage puede no estar disponible en navegación privada estricta
  }
}

/**
 * Lee token y correo del fragmento (#), los resguarda en sessionStorage (solo esta pestaña) para tolerar
 * recargas accidentales (F5) y los retira de la barra de direcciones y del historial.
 */
function obtenerEnlaceCliente(): Enlace | null {
  if (enlaceCache !== undefined) return enlaceCache;

  const parametros = new URLSearchParams(window.location.hash.replace(/^#/, ''));
  const token = parametros.get('token') ?? '';
  const email = parametros.get('email') ?? '';
  if (window.location.hash) window.history.replaceState(null, '', window.location.pathname);

  if (token && email) {
    enlaceCache = { token, email };
    try {
      window.sessionStorage.setItem(CLAVE_ENLACE, JSON.stringify(enlaceCache));
    } catch {
      // ignora fallos de almacenamiento
    }
    return enlaceCache;
  }

  try {
    const guardado = window.sessionStorage.getItem(CLAVE_ENLACE);
    if (guardado) {
      const parsed = JSON.parse(guardado) as Partial<Enlace>;
      if (typeof parsed.token === 'string' && parsed.token && typeof parsed.email === 'string' && parsed.email) {
        enlaceCache = { token: parsed.token, email: parsed.email };
        return enlaceCache;
      }
    }
  } catch {
    // ignora JSON inválido
  }

  enlaceCache = null;
  return enlaceCache;
}

const CLASE_ENLACE =
  'rounded font-medium text-academico-700 underline-offset-4 outline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-academico-700';

export function FormularioRestablecer() {
  const enlace = useSyncExternalStore(suscribirVacio, obtenerEnlaceCliente, () => undefined);
  const [password, setPassword] = useState('');
  const [confirmacion, setConfirmacion] = useState('');
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [listo, setListo] = useState<string | null>(null);

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    if (!enlace || enviando) return;

    const errores: Record<string, string[]> = {};
    if (password.length < 10) {
      errores.password = ['La contraseña debe tener al menos 10 caracteres.'];
    }
    if (password !== confirmacion) {
      errores.password_confirmation = ['La confirmación de la contraseña no coincide.'];
    }
    if (Object.keys(errores).length > 0) {
      setError({ message: 'Revisa los campos marcados.', code: 'validacion', errors: errores });
      return;
    }

    setEnviando(true);
    setError(null);

    const resultado = await llamarBff<{ message: string }>('/api/bff/auth/contrasena/restablecer', {
      cuerpo: { ...enlace, password, password_confirmation: confirmacion },
    });
    setEnviando(false);
    if (resultado.ok) {
      limpiarEnlaceGuardado();
      setPassword('');
      setConfirmacion('');
      setListo(resultado.datos.message);
      return;
    }
    if (errorDeCampo(resultado.error, 'token') || errorDeCampo(resultado.error, 'email')) {
      limpiarEnlaceGuardado();
    }
    setError(resultado.error);
  }

  if (enlace === undefined) {
    return <div className="mt-6 h-48 animate-pulse rounded-lg bg-panel-bg" aria-hidden="true" />;
  }

  if (listo) {
    return (
      <div className="mt-6 space-y-5">
        <Alerta tipo="exito">{listo}</Alerta>
        <p className="text-center text-sm">
          <Link href="/login" className={CLASE_ENLACE}>
            Ir a iniciar sesión
          </Link>
        </p>
      </div>
    );
  }

  const errorToken = errorDeCampo(error, 'token') ?? errorDeCampo(error, 'email');
  if (!enlace || errorToken) {
    return (
      <div className="mt-6 space-y-5">
        <Alerta tipo="error">{errorToken ?? 'El enlace de recuperación no es válido o está incompleto.'}</Alerta>
        <p className="text-center text-sm">
          <Link href="/recuperar-contrasena" className={CLASE_ENLACE}>
            Solicitar un enlace nuevo
          </Link>
        </p>
      </div>
    );
  }

  const errorClave = errorDeCampo(error, 'password');
  const errorConfirmacion = errorDeCampo(error, 'password_confirmation');

  return (
    <form onSubmit={enviar} noValidate className="mt-6 space-y-5">
      {error && !errorClave && !errorConfirmacion ? <Alerta tipo="error">{error.message}</Alerta> : null}
      <p className="text-sm text-panel-muted">
        Cuenta: <span className="font-medium text-panel-text">{enlace.email}</span>
      </p>
      <input type="email" name="username" autoComplete="username" value={enlace.email} readOnly hidden />
      <CampoClave
        etiqueta="Contraseña nueva"
        name="password"
        autoComplete="new-password"
        required
        value={password}
        onChange={(evento) => setPassword(evento.target.value)}
        error={errorClave}
        ayuda="Entre 10 y 72 caracteres, con mayúsculas, minúsculas y al menos un número."
        maxLength={72}
      />
      <CampoClave
        etiqueta="Repite la contraseña"
        name="password_confirmation"
        autoComplete="new-password"
        required
        value={confirmacion}
        onChange={(evento) => setConfirmacion(evento.target.value)}
        error={errorConfirmacion}
      />
      <Boton type="submit" cargando={enviando} icono={<KeyRound className="h-4 w-4" aria-hidden="true" />} className="w-full">
        {enviando ? 'Guardando…' : 'Guardar contraseña'}
      </Boton>
    </form>
  );
}
