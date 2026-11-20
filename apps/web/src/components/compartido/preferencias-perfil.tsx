'use client';

import { BellRing } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton, type TonoUi } from '@/components/shared/ui/boton';
import { Campo } from '@/components/shared/ui/campo';
import { Casilla } from '@/components/shared/ui/casilla';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import type { PreferenciasNotificacion } from '@/features/notificaciones/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';

const DEFECTO: PreferenciasNotificacion = {
  interno: true,
  email: false,
  whatsapp: false,
  whatsapp_consentimiento: false,
  recordatorio_cuotas: true,
  recordatorio_sesiones: true,
  recordatorio_reclamaciones: true,
  alerta_accesos: true,
};

/** Preferencias de notificación por canal y tipo en Mi perfil (incluye consentimiento Ley N.º 29733 para WhatsApp). */
export function PreferenciasPerfil({
  telefonoInicial,
  inicial,
  tono,
}: {
  telefonoInicial?: string | null;
  inicial?: PreferenciasNotificacion;
  tono: TonoUi;
}) {
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [telefono, setTelefono] = useState(telefonoInicial ?? '');
  const [prefs, setPrefs] = useState<PreferenciasNotificacion>(inicial ?? DEFECTO);
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);

  const alternar = (clave: keyof PreferenciasNotificacion) =>
    setPrefs((p) => ({ ...p, [clave]: !p[clave] }));

  async function guardar(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setGuardando(true);
    setError(null);
    const r = await ejecutar<{ message?: string }>('compartido/perfil/preferencias', 'PUT', {
      telefono: telefono.trim() === '' ? null : telefono.trim(),
      ...prefs,
    });
    setGuardando(false);
    if (!r.ok) {
      setError(r.error);
      return;
    }
    mostrar('exito', r.datos.message ?? 'Preferencias guardadas.');
    router.refresh();
  }

  return (
    <form onSubmit={guardar} noValidate className="space-y-4 rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm">
      <div className="flex items-center gap-2">
        <BellRing className="h-4 w-4 text-panel-muted" aria-hidden="true" />
        <h2 className="font-heading text-base font-semibold text-panel-text">Preferencias de notificación</h2>
      </div>
      <p className="text-xs text-panel-muted">
        Elige por qué canales y eventos deseas recibir recordatorios del sistema. Los canales externos solo envían cuando la institución los habilita.
      </p>
      {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}

      <Campo
        tono={tono}
        etiqueta="Teléfono móvil (opcional, para WhatsApp)"
        placeholder="+51 999 000 111"
        value={telefono}
        onChange={(e) => setTelefono(e.target.value)}
        error={errorDeCampo(error, 'telefono')}
        maxLength={20}
      />

      <fieldset className="space-y-2">
        <legend className="text-xs font-semibold uppercase tracking-wide text-panel-muted">Canales</legend>
        <div className="grid gap-2 sm:grid-cols-3 text-sm">
          <Casilla etiqueta="Campana del panel" checked={prefs.interno} onChange={() => alternar('interno')} />
          <Casilla etiqueta="Correo electrónico" checked={prefs.email} onChange={() => alternar('email')} />
          <Casilla etiqueta="WhatsApp" checked={prefs.whatsapp} onChange={() => alternar('whatsapp')} />
        </div>
      </fieldset>

      {prefs.whatsapp ? (
        <div className="rounded-lg border border-amber-200 bg-amber-50/70 p-3 text-xs text-amber-950">
          <Casilla
            etiqueta="Autorizo recibir recordatorios académicos/operativos vía WhatsApp conforme a la Ley N.º 29733 de Protección de Datos Personales."
            checked={prefs.whatsapp_consentimiento}
            onChange={() => alternar('whatsapp_consentimiento')}
          />
          {errorDeCampo(error, 'whatsapp_consentimiento') ? (
            <p role="alert" className="mt-1 font-medium text-red-700">{errorDeCampo(error, 'whatsapp_consentimiento')}</p>
          ) : null}
        </div>
      ) : null}

      <fieldset className="space-y-2">
        <legend className="text-xs font-semibold uppercase tracking-wide text-panel-muted">Eventos</legend>
        <div className="grid gap-2 sm:grid-cols-2 text-sm">
          {([
            ['recordatorio_cuotas', 'Cuotas por vencer / vencidas'],
            ['recordatorio_sesiones', 'Clases del día'],
            ['recordatorio_reclamaciones', 'Plazos y mensajes de reclamaciones'],
            ['alerta_accesos', 'Alertas de intentos de acceso'],
          ] as const).map(([clave, etiqueta]) => (
            <Casilla key={clave} etiqueta={etiqueta} checked={prefs[clave]} onChange={() => alternar(clave)} />
          ))}
        </div>
      </fieldset>

      <div className="flex justify-end border-t border-panel-border pt-4">
        <Boton tono={tono} type="submit" cargando={guardando}>Guardar preferencias</Boton>
      </div>
    </form>
  );
}
