'use client';

import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { Eye, EyeOff, LogIn } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Alerta } from '@/components/shared/ui/alerta';
import { Boton } from '@/components/shared/ui/boton';
import { Campo } from '@/components/shared/ui/campo';
import { errorDeCampo, llamarBff } from '@/lib/panel/cliente';
import { destinoSeguro } from '@/lib/panel/redireccion';
import type { ErrorPanel, SesionPanel } from '@/lib/panel/tipos';

export function FormularioLogin() {
  const router = useRouter();
  const parametros = useSearchParams();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [verClave, setVerClave] = useState(false);
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    if (enviando) return;
    setEnviando(true);
    setError(null);

    const resultado = await llamarBff<{ data: SesionPanel }>('/api/bff/auth/login', { cuerpo: { email, password } });
    if (resultado.ok) {
      setPassword('');
      router.replace(destinoSeguro(parametros.get('next')));
      router.refresh();
      return;
    }

    setPassword('');
    setError(resultado.error);
    setEnviando(false);
  }

  const errorEmail = errorDeCampo(error, 'email');
  const errorClave = errorDeCampo(error, 'password');
  const errorGeneral = error && !errorEmail && !errorClave ? error.message : null;

  return (
    <form onSubmit={enviar} noValidate className="mt-6 space-y-5">
      {errorGeneral ? <Alerta tipo="error">{errorGeneral}</Alerta> : null}

      <Campo
        etiqueta="Correo electrónico"
        type="email"
        name="email"
        autoComplete="username"
        inputMode="email"
        required
        maxLength={255}
        value={email}
        onChange={(evento) => setEmail(evento.target.value)}
        error={errorEmail}
      />

      <Campo
        etiqueta="Contraseña"
        type={verClave ? 'text' : 'password'}
        name="password"
        autoComplete="current-password"
        required
        maxLength={1024}
        value={password}
        onChange={(evento) => setPassword(evento.target.value)}
        error={errorClave}
        accesorio={
          <button
            type="button"
            onClick={() => setVerClave((valor) => !valor)}
            aria-label={verClave ? 'Ocultar contraseña' : 'Mostrar contraseña'}
            aria-pressed={verClave}
            className="rounded-md p-2 text-panel-muted outline-offset-2 hover:text-panel-text focus-visible:outline-2 focus-visible:outline-academico-700"
          >
            {verClave ? <EyeOff className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
          </button>
        }
      />

      <div className="flex justify-end">
        <Link
          href="/recuperar-contrasena"
          className="rounded text-sm font-medium text-academico-700 underline-offset-4 outline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-academico-700"
        >
          ¿Olvidaste tu contraseña?
        </Link>
      </div>

      <Boton type="submit" cargando={enviando} icono={<LogIn className="h-4 w-4" aria-hidden="true" />} className="w-full">
        {enviando ? 'Verificando…' : 'Iniciar sesión'}
      </Boton>
    </form>
  );
}
