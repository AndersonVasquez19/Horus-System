'use client';

import { Mail } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Alerta } from '@/components/shared/ui/alerta';
import { errorDeCampo, llamarBff } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';
import { BotonAcceso, CampoAcceso, EnlaceAcceso } from '../primitivas-acceso';

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
      <div className="space-y-5">
        <Alerta tipo="exito">{confirmacion}</Alerta>
        <p className="text-sm leading-6 text-panel-muted">El enlace vence en 60 minutos. Si no lo ves, revisa la carpeta de spam.</p>
        <EnlaceVolver />
      </div>
    );
  }

  const errorEmail = errorDeCampo(error, 'email');

  return (
    <form onSubmit={enviar} noValidate className="space-y-5">
      {error && !errorEmail ? <Alerta tipo="error">{error.message}</Alerta> : null}
      <CampoAcceso
        etiqueta="Correo electrónico"
        type="email"
        name="email"
        autoComplete="email"
        inputMode="email"
        required
        maxLength={255}
        placeholder="tu@correo.com"
        value={email}
        onChange={(evento) => setEmail(evento.target.value)}
        error={errorEmail}
        icono={<Mail className="h-4 w-4" />}
      />
      <BotonAcceso type="submit" cargando={enviando} icono={<Mail className="h-4 w-4" />}>
        {enviando ? 'Enviando…' : 'Enviar enlace'}
      </BotonAcceso>
      <EnlaceVolver />
    </form>
  );
}

function EnlaceVolver() {
  return (
    <p className="pt-1 text-center text-sm">
      <EnlaceAcceso href="/login">Volver a iniciar sesión</EnlaceAcceso>
    </p>
  );
}
