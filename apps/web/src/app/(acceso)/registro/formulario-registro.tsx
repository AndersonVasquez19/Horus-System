'use client';

import Link from 'next/link';
import { UserPlus } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Alerta } from '@/components/shared/ui/alerta';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoClave } from '@/components/shared/ui/campo';
import { errorDeCampo, llamarBff } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';

export function FormularioRegistro() {
  const [nombre, setNombre] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmacion, setConfirmacion] = useState('');
  const [acepta, setAcepta] = useState(false);
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [creada, setCreada] = useState<string | null>(null);

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    if (enviando) return;
    setEnviando(true);
    setError(null);

    const resultado = await llamarBff<{ message: string }>('/api/bff/auth/registro', {
      cuerpo: { name: nombre, email, password, password_confirmation: confirmacion, terms: acepta ? '1' : '' },
    });
    setEnviando(false);
    if (resultado.ok) {
      // Solo se limpian al terminar con éxito: en un 422 (p. ej. correo duplicado) el usuario conserva lo escrito.
      setPassword('');
      setConfirmacion('');
      setCreada(resultado.datos.message);
      return;
    }
    setError(resultado.error);
  }

  if (creada) {
    return (
      <div className="mt-6 space-y-5">
        <Alerta tipo="exito">{creada}</Alerta>
        <p className="text-sm text-panel-muted">Cuando tengas acceso podrás iniciar sesión con tu correo y la contraseña que acabas de crear.</p>
        <EnlaceLogin />
      </div>
    );
  }

  const errorTerminos = errorDeCampo(error, 'terms');
  const errorNombre = errorDeCampo(error, 'name');
  const errorEmail = errorDeCampo(error, 'email');
  const errorClave = errorDeCampo(error, 'password');
  const errorGeneral = error && !errorNombre && !errorEmail && !errorClave && !errorTerminos ? error.message : null;

  return (
    <form onSubmit={enviar} noValidate className="mt-6 space-y-5">
      {errorGeneral ? <Alerta tipo="error">{errorGeneral}</Alerta> : null}

      <Campo etiqueta="Nombre completo" name="name" autoComplete="name" required maxLength={120} value={nombre} onChange={(e) => setNombre(e.target.value)} error={errorNombre} />
      <Campo etiqueta="Correo electrónico" type="email" name="email" autoComplete="email" inputMode="email" required maxLength={255} value={email} onChange={(e) => setEmail(e.target.value)} error={errorEmail} />
      <CampoClave
        etiqueta="Contraseña"
        name="password"
        autoComplete="new-password"
        required
        minLength={10}
        maxLength={72}
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        error={errorClave}
        ayuda="Al menos 10 caracteres, con mayúsculas, minúsculas y un número."
      />
      <CampoClave etiqueta="Confirma tu contraseña" name="password_confirmation" autoComplete="new-password" required maxLength={72} value={confirmacion} onChange={(e) => setConfirmacion(e.target.value)} />

      <div>
        <label className="flex items-start gap-3 text-sm text-panel-text">
          <input
            type="checkbox"
            name="terms"
            checked={acepta}
            onChange={(e) => setAcepta(e.target.checked)}
            aria-invalid={errorTerminos ? true : undefined}
            aria-describedby={errorTerminos ? 'terms-error' : undefined}
            className="mt-0.5 h-4 w-4 rounded border-slate-400 accent-academico-700"
          />
          <span>
            He leído y acepto el{' '}
            <a href="/legales/politicas-privacidad" target="_blank" rel="noopener" className="font-medium text-academico-700 underline underline-offset-4">
              aviso de privacidad
            </a>
            .
          </span>
        </label>
        {errorTerminos ? (
          <p id="terms-error" className="mt-1.5 text-sm text-estado-error">
            {errorTerminos}
          </p>
        ) : null}
      </div>

      <Boton type="submit" cargando={enviando} icono={<UserPlus className="h-4 w-4" aria-hidden="true" />} className="w-full">
        {enviando ? 'Creando…' : 'Crear cuenta'}
      </Boton>
      <EnlaceLogin />
    </form>
  );
}

function EnlaceLogin() {
  return (
    <p className="text-center text-sm text-panel-muted">
      ¿Ya tienes cuenta?{' '}
      <Link href="/login" className="rounded font-medium text-academico-700 underline-offset-4 outline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-academico-700">
        Inicia sesión
      </Link>
    </p>
  );
}
