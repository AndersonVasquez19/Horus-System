'use client';

import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { ArrowRight, Eye, EyeOff, Loader2, Lock, Mail, ShieldCheck } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Alerta } from '@/components/shared/ui/alerta';
import { errorDeCampo, llamarBff } from '@/lib/panel/cliente';
import { destinoSeguro } from '@/lib/panel/redireccion';
import type { ErrorPanel, SesionPanel } from '@/lib/panel/tipos';

export function FormularioLogin() {
  const router = useRouter();
  const parametros = useSearchParams();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [codigo2fa, setCodigo2fa] = useState('');
  const [requiere2fa, setRequiere2fa] = useState(false);
  const [verClave, setVerClave] = useState(false);
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    if (enviando) return;
    setEnviando(true);
    setError(null);

    const cuerpo: Record<string, string> = { email, password };
    if (requiere2fa || codigo2fa.trim() !== '') cuerpo.codigo_2fa = codigo2fa.trim();

    const resultado = await llamarBff<{ data: SesionPanel }>('/api/bff/auth/login', { cuerpo });
    if (resultado.ok) {
      setPassword('');
      setCodigo2fa('');
      // Sin destino pedido se entra directo al panel académico: `/admin` solo resuelve la sesión y redirige, y pasar por él
      // dejaba ver un instante otro título antes del definitivo. Quien no tenga acceso verá el aviso de acceso denegado.
      const destino = destinoSeguro(parametros.get('next'));
      router.replace(destino === '/admin' ? '/admin/academico' : destino);
      router.refresh();
      return;
    }

    if (resultado.error.code === '2fa_requerido') {
      setRequiere2fa(true);
    } else {
      setPassword('');
    }
    setError(resultado.error);
    setEnviando(false);
  }

  const errorEmail = errorDeCampo(error, 'email');
  const errorClave = errorDeCampo(error, 'password');
  const error2fa = errorDeCampo(error, 'codigo_2fa');
  const errorGeneral = error && !errorEmail && !errorClave && !error2fa ? error.message : null;

  const claseInput = (conError: boolean) =>
    `w-full rounded-xl border bg-[#F9FAFC] py-3 pl-10 pr-3 text-sm text-tinta outline-none transition placeholder:text-slate-500 focus:bg-white focus:ring-4 ${
      conError ? 'border-estado-error focus:border-estado-error focus:ring-estado-error/15' : 'border-slate-300 focus:border-oro-500 focus:ring-oro-500/15'
    }`;

  return (
    <form onSubmit={enviar} noValidate className="mt-6 space-y-5">
      {errorGeneral ? <Alerta tipo="error">{errorGeneral}</Alerta> : null}

      <div>
        <label htmlFor="email" className="mb-1.5 block text-sm font-semibold text-tinta">
          Correo electrónico
        </label>
        <div className="group relative">
          <Mail className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500 transition group-focus-within:text-oro-700" aria-hidden="true" />
          <input
            id="email"
            type="email"
            name="email"
            autoComplete="username"
            inputMode="email"
            required
            maxLength={255}
            placeholder="tu@correo.com"
            value={email}
            onChange={(evento) => setEmail(evento.target.value)}
            aria-invalid={errorEmail ? true : undefined}
            aria-describedby={errorEmail ? 'email-error' : undefined}
            className={claseInput(Boolean(errorEmail))}
          />
        </div>
        {errorEmail ? (
          <p id="email-error" className="mt-1.5 text-sm text-estado-error">
            {errorEmail}
          </p>
        ) : null}
      </div>

      <div>
        <div className="mb-1.5 flex items-center justify-between gap-3">
          <label htmlFor="password" className="block text-sm font-semibold text-tinta">
            Contraseña
          </label>
          <Link
            href="/recuperar-contrasena"
            className="rounded text-sm font-medium text-academico-700 underline-offset-4 outline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-oro-500"
          >
            ¿La olvidaste?
          </Link>
        </div>
        <div className="group relative">
          <Lock className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500 transition group-focus-within:text-oro-700" aria-hidden="true" />
          <input
            id="password"
            type={verClave ? 'text' : 'password'}
            name="password"
            autoComplete="current-password"
            required
            maxLength={1024}
            placeholder="Tu contraseña"
            value={password}
            onChange={(evento) => setPassword(evento.target.value)}
            aria-invalid={errorClave ? true : undefined}
            aria-describedby={errorClave ? 'password-error' : undefined}
            className={`${claseInput(Boolean(errorClave))} pr-11`}
          />
          <button
            type="button"
            onClick={() => setVerClave((valor) => !valor)}
            aria-label={verClave ? 'Ocultar contraseña' : 'Mostrar contraseña'}
            aria-pressed={verClave}
            className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded-md p-2 text-slate-600 outline-offset-2 hover:text-tinta focus-visible:outline-2 focus-visible:outline-oro-500"
          >
            {verClave ? <EyeOff className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
          </button>
        </div>
        {errorClave ? (
          <p id="password-error" className="mt-1.5 text-sm text-estado-error">
            {errorClave}
          </p>
        ) : null}
      </div>

      {requiere2fa ? (
        <div>
          <label htmlFor="codigo_2fa" className="mb-1.5 block text-sm font-semibold text-tinta">
            Código de verificación en dos pasos (2FA)
          </label>
          <input
            id="codigo_2fa"
            type="text"
            name="codigo_2fa"
            autoComplete="one-time-code"
            inputMode="text"
            autoCapitalize="none"
            autoCorrect="off"
            spellCheck={false}
            maxLength={32}
            placeholder="000000 o código de recuperación"
            value={codigo2fa}
            onChange={(evento) => setCodigo2fa(evento.target.value)}
            aria-invalid={error2fa ? true : undefined}
            aria-describedby={error2fa ? 'codigo-2fa-error' : undefined}
            autoFocus
            className={`${claseInput(Boolean(error2fa))} pl-3.5 tracking-wider`}
          />
          {error2fa ? (
            <p id="codigo-2fa-error" className="mt-1.5 text-sm text-estado-error">
              {error2fa}
            </p>
          ) : (
            <p className="mt-1.5 text-xs text-panel-muted">Ingresa el código de 6 dígitos de tu aplicación autenticadora o un código de recuperación.</p>
          )}
        </div>
      ) : null}

      <button
        type="submit"
        disabled={enviando}
        aria-busy={enviando}
        className="group flex w-full items-center justify-between rounded-xl bg-tinta px-5 py-3.5 text-sm font-semibold text-white shadow-lg shadow-tinta/15 outline-offset-2 transition duration-300 hover:-translate-y-0.5 hover:bg-academico-800 hover:shadow-xl focus-visible:outline-2 focus-visible:outline-oro-500 disabled:cursor-not-allowed disabled:opacity-70 disabled:hover:translate-y-0 motion-reduce:transition-none motion-reduce:hover:translate-y-0"
      >
        <span>{enviando ? 'Verificando…' : 'Entrar al panel'}</span>
        <span className="flex h-7 w-7 items-center justify-center rounded-full bg-oro-400 text-tinta transition group-hover:translate-x-1 motion-reduce:transition-none motion-reduce:group-hover:translate-x-0" aria-hidden="true">
          {enviando ? <Loader2 className="h-4 w-4 animate-spin" /> : <ArrowRight className="h-4 w-4" />}
        </span>
      </button>

      <p className="flex items-center justify-center gap-2 border-t border-slate-200 pt-4 text-xs text-panel-muted">
        <ShieldCheck className="h-4 w-4 text-oro-700" aria-hidden="true" />
        Conexión protegida: tus datos permanecen seguros.
      </p>
    </form>
  );
}
