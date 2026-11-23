'use client';

import Link from 'next/link';
import { Mail } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Alerta } from '@/components/shared/ui/alerta';
import { Boton } from '@/components/shared/ui/boton';
import { Campo } from '@/components/shared/ui/campo';
import { errorDeCampo, llamarBff } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';

export function FormularioRecuperar() {
  const [email, setEmail] = useState('');
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [confirmacion, setConfirmacion] = useState<string | null>(null);

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    if (enviando) return;

    const emailLimpio = email.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailLimpio)) {
      setError({
        message: 'Revisa los campos marcados.',
        code: 'validacion',
        errors: { email: ['Ingresa un correo electrónico válido.'] },
      });
      return;
    }

    setEnviando(true);
    setError(null);

    const resultado = await llamarBff<{ message: string }>('/api/bff/auth/contrasena/solicitar', { cuerpo: { email: emailLimpio } });
    setEnviando(false);
    if (resultado.ok) {
      setConfirmacion(resultado.datos.message);
      return;
    }
    setError(resultado.error);
  }

  if (confirmacion) {
    return (
      <div className="mt-6 space-y-5">
        <Alerta tipo="exito">{confirmacion}</Alerta>
        <p className="text-sm text-panel-muted">El enlace vence en 60 minutos. Si no lo ves, revisa la carpeta de spam.</p>
        <EnlaceVolver />
      </div>
    );
  }

  const errorEmail = errorDeCampo(error, 'email');

  return (
    <form onSubmit={enviar} noValidate className="mt-6 space-y-5">
      {error && !errorEmail ? <Alerta tipo="error">{error.message}</Alerta> : null}
      <Campo
        etiqueta="Correo electrónico"
        type="email"
        name="email"
        autoComplete="email"
        inputMode="email"
        required
        maxLength={255}
        value={email}
        onChange={(evento) => setEmail(evento.target.value)}
        error={errorEmail}
      />
      <Boton type="submit" cargando={enviando} icono={<Mail className="h-4 w-4" aria-hidden="true" />} className="w-full">
        {enviando ? 'Enviando…' : 'Enviar enlace'}
      </Boton>
      <EnlaceVolver />
    </form>
  );
}

function EnlaceVolver() {
  return (
    <p className="text-center text-sm">
      <Link
        href="/login"
        className="rounded font-medium text-academico-700 underline-offset-4 outline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-academico-700"
      >
        Volver a iniciar sesión
      </Link>
    </p>
  );
}
