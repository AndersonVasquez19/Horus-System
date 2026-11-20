'use client';

import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { Campo, CampoClave } from '@/components/shared/ui/campo';
import { Insignia } from '@/components/shared/ui/insignia';
import type { PerfilPropio } from '@/features/notificaciones/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { etiqueta } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

/**
 * Perfil propio. La contraseña actual solo se pide al cambiar el correo o la contraseña (Laravel lo exige igual).
 * Tras cambiar la contraseña Laravel cierra las demás sesiones y renueva la actual.
 */
export function FormularioPerfil({ perfil, tono }: { perfil: PerfilPropio; tono: TonoUi }) {
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [nombre, setNombre] = useState(perfil.nombre);
  const [email, setEmail] = useState(perfil.email);
  const [clave, setClave] = useState('');
  const [confirmacion, setConfirmacion] = useState('');
  const [actual, setActual] = useState('');
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);

  const cambiaCredenciales = email.trim().toLowerCase() !== perfil.email.toLowerCase() || clave !== '';

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setGuardando(true);
    setError(null);
    const cuerpo: Record<string, string> = { name: nombre.trim(), email: email.trim() };
    if (clave !== '') {
      cuerpo.password = clave;
      cuerpo.password_confirmation = confirmacion;
    }
    if (actual !== '') cuerpo.current_password = actual;

    const resultado = await ejecutar<{ message?: string }>('compartido/perfil', 'PUT', cuerpo);
    setGuardando(false);
    if (!resultado.ok) {
      setError(resultado.error);
      return;
    }
    setClave('');
    setConfirmacion('');
    setActual('');
    mostrar('exito', resultado.datos.message ?? 'Tu perfil se actualizó correctamente.');
    router.refresh();
  }

  const err = (campo: string) => errorDeCampo(error, campo);

  return (
    <form onSubmit={enviar} noValidate className="space-y-4 rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-heading text-base font-semibold text-panel-text">Datos de la cuenta</h2>
        {perfil.roles.length > 0 ? (
          <div className="flex flex-wrap items-center gap-1.5" aria-label="Roles asignados">
            {perfil.roles.map((r) => (
              <Insignia key={r} color={r === 'admin' ? 'azul' : 'gris'}>
                {etiqueta('rol', r)}
              </Insignia>
            ))}
          </div>
        ) : null}
      </div>
      {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo tono={tono} etiqueta="Nombre" value={nombre} onChange={(e) => setNombre(e.target.value)} error={err('name')} required maxLength={255} autoComplete="name" />
        <Campo tono={tono} etiqueta="Correo electrónico" type="email" value={email} onChange={(e) => setEmail(e.target.value)} error={err('email')} required maxLength={255} autoComplete="email" />
        <CampoClave tono={tono} etiqueta="Nueva contraseña (opcional)" politica value={clave} onChange={(e) => setClave(e.target.value)} error={err('password')} autoComplete="new-password" />
        <CampoClave tono={tono} etiqueta="Confirmar nueva contraseña" value={confirmacion} onChange={(e) => setConfirmacion(e.target.value)} autoComplete="new-password" />
        {cambiaCredenciales ? (
          <CampoClave
            tono={tono}
            etiqueta="Contraseña actual"
            value={actual}
            onChange={(e) => setActual(e.target.value)}
            error={err('current_password')}
            ayuda="Obligatoria para cambiar el correo o la contraseña."
            autoComplete="current-password"
            className="sm:col-span-2"
          />
        ) : null}
      </div>
      <div className="flex justify-end border-t border-panel-border pt-4">
        <Boton tono={tono} type="submit" cargando={guardando}>Guardar cambios</Boton>
      </div>
    </form>
  );
}
