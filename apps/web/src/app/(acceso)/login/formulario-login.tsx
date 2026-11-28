'use client';

import { Lock, Mail, ShieldCheck } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { Alerta } from '@/components/shared/ui/alerta';
import { errorDeCampo, llamarBff } from '@/lib/panel/cliente';
import { destinoSeguro } from '@/lib/panel/redireccion';
import type { ErrorPanel, SesionPanel } from '@/lib/panel/tipos';
import { BotonAcceso, CampoAcceso, CampoClaveAcceso, EnlaceAcceso } from '../primitivas-acceso';

export function FormularioLogin() {
  const router = useRouter();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [codigo2fa, setCodigo2fa] = useState('');
  const [requiere2fa, setRequiere2fa] = useState(false);
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    if (enviando) return;

    const errores: Record<string, string[]> = {};
    const emailLimpio = email.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailLimpio)) {
      errores.email = ['Ingresa un correo electrónico válido.'];
    }
    if (password === '') {
      errores.password = ['Ingresa tu contraseña.'];
    }
    if (requiere2fa && codigo2fa.trim() === '') {
      errores.codigo_2fa = ['Ingresa el código de verificación de 6 dígitos o uno de recuperación.'];
    }
    if (Object.keys(errores).length > 0) {
      setError({ message: 'Revisa los campos marcados.', code: 'validacion', errors: errores });
      return;
    }

    setEnviando(true);
    setError(null);

    const cuerpo: Record<string, string> = { email: emailLimpio, password };
    if (requiere2fa || codigo2fa.trim() !== '') cuerpo.codigo_2fa = codigo2fa.trim();

    const resultado = await llamarBff<{ data: SesionPanel }>('/api/bff/auth/login', { cuerpo });
    if (resultado.ok) {
      setPassword('');
      setCodigo2fa('');
      // Sin destino pedido se entra directo al panel académico: `/admin` solo resuelve la sesión y redirige, y pasar por él
      // dejaba ver un instante otro título antes del definitivo. Quien no tenga acceso verá el aviso de acceso denegado.
      const next = new URLSearchParams(window.location.search).get('next');
      const destino = destinoSeguro(next);
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

  return (
    <form onSubmit={enviar} noValidate className="space-y-5">
      {errorGeneral ? <Alerta tipo="error">{errorGeneral}</Alerta> : null}

      <CampoAcceso
        etiqueta="Correo electrónico"
        type="email"
        name="email"
        autoComplete="username"
        inputMode="email"
        required
        maxLength={255}
        placeholder="tu@correo.com"
        value={email}
        onChange={(evento) => setEmail(evento.target.value)}
        error={errorEmail}
        icono={<Mail className="h-4 w-4" />}
      />

      <CampoClaveAcceso
        etiqueta="Contraseña"
        accionEtiqueta={
          <span className="text-xs">
            <EnlaceAcceso href="/recuperar-contrasena">¿Olvidaste tu contraseña?</EnlaceAcceso>
          </span>
        }
        name="password"
        autoComplete="current-password"
        required
        maxLength={1024}
        placeholder="Tu contraseña"
        value={password}
        onChange={(evento) => setPassword(evento.target.value)}
        error={errorClave}
        icono={<Lock className="h-4 w-4" />}
      />

      {requiere2fa ? (
        <CampoAcceso
          etiqueta="Código de verificación en dos pasos (2FA)"
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
          error={error2fa}
          ayuda="Ingresa el código de 6 dígitos de tu aplicación autenticadora o un código de recuperación."
          autoFocus
        />
      ) : null}

      <BotonAcceso type="submit" cargando={enviando}>
        {enviando ? 'Verificando…' : 'Entrar al panel'}
      </BotonAcceso>

      <p className="flex items-center justify-center gap-2 border-t border-panel-border/80 pt-4 text-xs text-panel-muted">
        <ShieldCheck className="h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
        Conexión protegida: tus datos permanecen seguros.
      </p>
    </form>
  );
}
