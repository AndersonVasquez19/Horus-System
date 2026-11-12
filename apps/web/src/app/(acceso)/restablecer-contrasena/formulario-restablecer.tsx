'use client';

import Link from 'next/link';
import { KeyRound } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { Alerta } from '@/components/shared/ui/alerta';
import { Boton } from '@/components/shared/ui/boton';
import { Campo } from '@/components/shared/ui/campo';
import { errorDeCampo, llamarBff } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';

interface Enlace {
  token: string;
  email: string;
}

/** Lee token y correo del fragmento (#) y los retira de la barra de direcciones y del historial. */
function leerEnlace(): Enlace | null {
  const parametros = new URLSearchParams(window.location.hash.replace(/^#/, ''));
  const token = parametros.get('token') ?? '';
  const email = parametros.get('email') ?? '';
  if (window.location.hash) window.history.replaceState(null, '', window.location.pathname);
  return token && email ? { token, email } : null;
}

const CLASE_ENLACE =
  'rounded font-medium text-academico-700 underline-offset-4 outline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-academico-700';

export function FormularioRestablecer() {
  const [enlace, setEnlace] = useState<Enlace | null | undefined>(undefined);
  const [password, setPassword] = useState('');
  const [confirmacion, setConfirmacion] = useState('');
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [listo, setListo] = useState<string | null>(null);

  useEffect(() => {
    // El fragmento solo existe en el navegador: se lee una vez al montar.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setEnlace(leerEnlace());
  }, []);

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    if (!enlace || enviando) return;
    setEnviando(true);
    setError(null);

    const resultado = await llamarBff<{ message: string }>('/api/bff/auth/contrasena/restablecer', {
      cuerpo: { ...enlace, password, password_confirmation: confirmacion },
    });
    setEnviando(false);
    if (resultado.ok) {
      setPassword('');
      setConfirmacion('');
      setListo(resultado.datos.message);
      return;
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

  return (
    <form onSubmit={enviar} noValidate className="mt-6 space-y-5">
      {error && !errorClave ? <Alerta tipo="error">{error.message}</Alerta> : null}
      <p className="text-sm text-panel-muted">
        Cuenta: <span className="font-medium text-panel-text">{enlace.email}</span>
      </p>
      <input type="email" name="username" autoComplete="username" value={enlace.email} readOnly hidden />
      <Campo
        etiqueta="Contraseña nueva"
        type="password"
        name="password"
        autoComplete="new-password"
        required
        value={password}
        onChange={(evento) => setPassword(evento.target.value)}
        error={errorClave}
        ayuda="Entre 10 y 72 caracteres, con mayúsculas, minúsculas y al menos un número."
        maxLength={72}
      />
      <Campo
        etiqueta="Repite la contraseña"
        type="password"
        name="password_confirmation"
        autoComplete="new-password"
        required
        value={confirmacion}
        onChange={(evento) => setConfirmacion(evento.target.value)}
      />
      <Boton type="submit" cargando={enviando} icono={<KeyRound className="h-4 w-4" aria-hidden="true" />} className="w-full">
        {enviando ? 'Guardando…' : 'Guardar contraseña'}
      </Boton>
    </form>
  );
}
