'use client';

import { Lock, Mail, User, UserPlus } from 'lucide-react';
import { useId, useState, type FormEvent } from 'react';
import { Alerta } from '@/components/shared/ui/alerta';
import { errorDeCampo, llamarBff } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';
import { BotonAcceso, CampoAcceso, CampoClaveAcceso, EnlaceAcceso } from '../primitivas-acceso';

export function FormularioRegistro() {
  const idTerminos = useId();
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

    const errores: Record<string, string[]> = {};
    const nombreLimpio = nombre.trim();
    const emailLimpio = email.trim();
    if (nombreLimpio.length < 2) {
      errores.name = ['Escribe tu nombre completo (mínimo 2 caracteres).'];
    } else if (/[<>]/.test(nombreLimpio)) {
      errores.name = ['El nombre contiene caracteres no permitidos.'];
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailLimpio)) {
      errores.email = ['Ingresa un correo electrónico válido.'];
    }
    if (password.length < 10) {
      errores.password = ['La contraseña debe tener al menos 10 caracteres.'];
    }
    if (password !== confirmacion) {
      errores.password_confirmation = ['La confirmación de la contraseña no coincide.'];
    }
    if (!acepta) {
      errores.terms = ['Debes aceptar el aviso de privacidad para crear tu cuenta.'];
    }
    if (Object.keys(errores).length > 0) {
      setError({ message: 'Revisa los campos marcados.', code: 'validacion', errors: errores });
      return;
    }

    setEnviando(true);
    setError(null);

    const resultado = await llamarBff<{ message: string }>('/api/bff/auth/registro', {
      cuerpo: { name: nombreLimpio, email: emailLimpio, password, password_confirmation: confirmacion, terms: acepta ? '1' : '' },
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
      <div className="space-y-5">
        <Alerta tipo="exito">{creada}</Alerta>
        <p className="text-sm leading-6 text-panel-muted">
          Cuando un administrador habilite tu acceso podrás iniciar sesión con tu correo y la contraseña que acabas de crear.
        </p>
        <EnlaceLogin />
      </div>
    );
  }

  const errorTerminos = errorDeCampo(error, 'terms');
  const errorNombre = errorDeCampo(error, 'name');
  const errorEmail = errorDeCampo(error, 'email');
  const errorClave = errorDeCampo(error, 'password');
  const errorConfirmacion = errorDeCampo(error, 'password_confirmation');
  const errorGeneral = error && !errorNombre && !errorEmail && !errorClave && !errorConfirmacion && !errorTerminos ? error.message : null;

  return (
    <form onSubmit={enviar} noValidate className="space-y-4">
      {errorGeneral ? <Alerta tipo="error">{errorGeneral}</Alerta> : null}

      <CampoAcceso
        etiqueta="Nombre completo"
        name="name"
        autoComplete="name"
        required
        maxLength={120}
        placeholder="Nombres y apellidos"
        value={nombre}
        onChange={(e) => setNombre(e.target.value)}
        error={errorNombre}
        icono={<User className="h-4 w-4" />}
      />

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
        onChange={(e) => setEmail(e.target.value)}
        error={errorEmail}
        icono={<Mail className="h-4 w-4" />}
      />

      <CampoClaveAcceso
        etiqueta="Contraseña"
        name="password"
        autoComplete="new-password"
        required
        minLength={10}
        maxLength={72}
        placeholder="Crea una contraseña segura"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        error={errorClave}
        politica
        icono={<Lock className="h-4 w-4" />}
      />

      <CampoClaveAcceso
        etiqueta="Confirma tu contraseña"
        name="password_confirmation"
        autoComplete="new-password"
        required
        maxLength={72}
        placeholder="Repite tu contraseña"
        value={confirmacion}
        onChange={(e) => setConfirmacion(e.target.value)}
        error={errorConfirmacion}
        icono={<Lock className="h-4 w-4" />}
      />

      <div className="pt-1">
        <div className="flex items-start gap-3">
          <input
            id={idTerminos}
            type="checkbox"
            name="terms"
            checked={acepta}
            onChange={(e) => setAcepta(e.target.checked)}
            aria-invalid={errorTerminos ? true : undefined}
            aria-describedby={errorTerminos ? 'terms-error' : undefined}
            className="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-panel-border accent-academico-700"
          />
          <p className="text-sm leading-5 text-panel-text">
            <label htmlFor={idTerminos} className="cursor-pointer">
              He leído y acepto el{' '}
            </label>
            <a
              href="/legales/politicas-privacidad"
              target="_blank"
              rel="noopener noreferrer"
              className="rounded font-medium text-academico-700 underline underline-offset-4 outline-offset-2 hover:text-amber-800 focus-visible:outline-2 focus-visible:outline-academico-700"
            >
              aviso de privacidad
            </a>
            .
          </p>
        </div>
        {errorTerminos ? (
          <p id="terms-error" role="alert" className="mt-1.5 text-sm text-estado-error">
            {errorTerminos}
          </p>
        ) : null}
      </div>

      <BotonAcceso type="submit" cargando={enviando} icono={<UserPlus className="h-4 w-4" />}>
        {enviando ? 'Creando…' : 'Crear cuenta'}
      </BotonAcceso>

      <EnlaceLogin />
    </form>
  );
}

function EnlaceLogin() {
  return (
    <p className="pt-2 text-center text-sm text-panel-muted">
      ¿Ya tienes cuenta? <EnlaceAcceso href="/login">Inicia sesión</EnlaceAcceso>
    </p>
  );
}
