import Link from 'next/link';
import { redirect } from 'next/navigation';
import { ArrowLeftRight, ShieldAlert, WifiOff } from 'lucide-react';
import { Suspense, type ReactNode } from 'react';
import { obtenerSesion } from '@/lib/panel/sesion';
import { urlLogin } from '@/lib/panel/redireccion';
import { BotonCerrarSesion } from './boton-cerrar-sesion';
import { MarcaPanel } from './marca-panel';
import { PanelNavegacion } from './panel-navegacion';
import { filtrarNavegacion, type ModeloNavegacion, type TonoPanel } from './panel-tipos';
import { RenovadorSesion } from './renovador-sesion';
import { ProveedorSesionPanel } from './sesion-panel-contexto';
import { ProveedorAvisos } from './ui/avisos';

/*
 * Las clases se escriben completas (no se componen) para que Tailwind las detecte.
 * Texto blanco sobre 800; foco con 700 (ver globals.css para los contrastes).
 */
const TONOS: Record<TonoPanel, { franja: string; foco: string; enlace: string; otro: TonoPanel; otroTitulo: string }> = {
  academico: {
    franja: 'bg-academico-800',
    foco: 'focus-visible:outline-academico-700',
    enlace: 'text-academico-700 focus-visible:outline-academico-700',
    otro: 'servicios',
    otroTitulo: 'Servicios tecnológicos',
  },
  servicios: {
    franja: 'bg-servicios-800',
    foco: 'focus-visible:outline-servicios-700',
    enlace: 'text-servicios-700 focus-visible:outline-servicios-700',
    otro: 'academico',
    otroTitulo: 'Gestión académica',
  },
};

interface PanelShellProps {
  tono: TonoPanel;
  titulo: string;
  navegacion: ModeloNavegacion;
  children: ReactNode;
}

/**
 * Estructura común de un panel: cabecera, barra lateral y contenido.
 *
 * Solo comparte la estructura; cada panel decide su navegación y sus módulos.
 *
 * Seguridad: el contenido (`children`) solo se renderiza después de que Laravel confirme la sesión y el permiso
 * de entrada del panel (GuardiaPanel). El menú se filtra por permisos como ayuda visual: cada endpoint vuelve a
 * comprobarlo. La guardia lee cookies, por eso está dentro de un `<Suspense>` (Cache Components).
 *
 * Semántica: el layout raíz aporta el único `<main>`; aquí no se declara otro `<main>`, `<header>` ni `<aside>`.
 */
export function PanelShell({ tono, titulo, navegacion, children }: PanelShellProps) {
  const t = TONOS[tono];

  return (
    <MarcaPanel atributo="data-panel-tono" valor={tono} className="flex min-h-screen flex-col">
      <a
        href="#contenido-panel"
        className={`sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-md focus:bg-panel-surface focus:px-3 focus:py-2 focus:text-panel-text focus:shadow focus-visible:outline-2 focus-visible:outline-offset-2 ${t.foco}`}
      >
        Saltar al contenido
      </a>

      <Suspense fallback={<EsqueletoPanel tono={tono} titulo={titulo} />}>
        <GuardiaPanel tono={tono} titulo={titulo} navegacion={navegacion}>
          {children}
        </GuardiaPanel>
      </Suspense>
    </MarcaPanel>
  );
}

async function GuardiaPanel({ tono, titulo, navegacion, children }: PanelShellProps) {
  const t = TONOS[tono];
  const resultado = await obtenerSesion();

  if (resultado.estado === 'anonimo') redirect(urlLogin(`/admin/${tono}`));
  if (resultado.estado === 'no_disponible') {
    return (
      <Franja tono={tono} titulo={titulo}>
        <EstadoPagina
          icono={<WifiOff className="h-6 w-6" aria-hidden="true" />}
          titulo="El panel no está disponible"
          texto="No se pudo verificar tu sesión porque el servicio no responde. Inténtalo de nuevo en unos minutos."
        />
      </Franja>
    );
  }

  const { sesion } = resultado;
  if (!sesion.paneles[tono]) {
    return (
      <Franja tono={tono} titulo={titulo} acciones={<BotonCerrarSesion className="text-white hover:bg-white/10 focus-visible:outline-white" />}>
        <EstadoPagina
          icono={<ShieldAlert className="h-6 w-6" aria-hidden="true" />}
          titulo="No tienes acceso a este panel"
          texto="Tu cuenta no tiene permiso para entrar aquí. Si crees que es un error, contacta con un administrador."
          enlace={{ href: '/admin', texto: 'Ir a mis paneles', clase: t.enlace }}
        />
      </Franja>
    );
  }

  const otroPanel = sesion.paneles[t.otro];

  return (
    <ProveedorSesionPanel sesion={sesion}>
      <ProveedorAvisos>
        <RenovadorSesion />
        <Franja
          tono={tono}
          titulo={titulo}
          acciones={
            <>
              {otroPanel ? (
                <Link
                  href={`/admin/${t.otro}`}
                  className="hidden items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium text-white outline-offset-2 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-white sm:inline-flex"
                >
                  <ArrowLeftRight className="h-4 w-4" aria-hidden="true" />
                  {t.otroTitulo}
                </Link>
              ) : null}
              <span className="hidden max-w-48 truncate text-sm text-white/90 md:inline" title={sesion.usuario.email}>
                {sesion.usuario.nombre}
              </span>
              <BotonCerrarSesion className="text-white hover:bg-white/10 focus-visible:outline-white" />
            </>
          }
        >
          <div className="flex flex-1 flex-col md:flex-row">
            <div className="border-b border-panel-border bg-panel-surface md:w-64 md:shrink-0 md:border-b-0 md:border-r">
              <PanelNavegacion tono={tono} titulo={titulo} modelo={filtrarNavegacion(navegacion, sesion.permisos)} />
            </div>

            <div
              id="contenido-panel"
              tabIndex={-1}
              className={`min-w-0 flex-1 p-4 outline-none focus-visible:outline-2 focus-visible:-outline-offset-2 sm:p-6 lg:p-8 ${t.foco}`}
            >
              {children}
            </div>
          </div>
        </Franja>
      </ProveedorAvisos>
    </ProveedorSesionPanel>
  );
}

function Franja({ tono, titulo, acciones, children }: { tono: TonoPanel; titulo: string; acciones?: ReactNode; children: ReactNode }) {
  return (
    <>
      <div className={`${TONOS[tono].franja} flex items-center justify-between gap-3 px-4 py-3 text-white sm:px-6`}>
        <p className="font-heading text-sm font-semibold tracking-wide">
          Horus Group <span aria-hidden="true">·</span> {titulo}
        </p>
        {acciones ? <div className="flex items-center gap-1 sm:gap-2">{acciones}</div> : null}
      </div>
      {children}
    </>
  );
}

function EstadoPagina({
  icono,
  titulo,
  texto,
  enlace,
}: {
  icono: ReactNode;
  titulo: string;
  texto: string;
  enlace?: { href: string; texto: string; clase: string };
}) {
  return (
    <div className="flex flex-1 items-center justify-center p-6">
      <div className="max-w-md rounded-2xl border border-panel-border bg-panel-surface p-8 text-center shadow-sm">
        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-panel-bg text-panel-muted">{icono}</div>
        <h1 className="font-heading text-lg font-semibold text-panel-text">{titulo}</h1>
        <p className="mt-2 text-sm text-panel-muted">{texto}</p>
        {enlace ? (
          <Link
            href={enlace.href}
            className={`mt-5 inline-block rounded font-medium underline-offset-4 outline-offset-2 hover:underline focus-visible:outline-2 ${enlace.clase}`}
          >
            {enlace.texto}
          </Link>
        ) : null}
      </div>
    </div>
  );
}

function EsqueletoPanel({ tono, titulo }: { tono: TonoPanel; titulo: string }) {
  return (
    <Franja tono={tono} titulo={titulo}>
      <div className="flex flex-1 flex-col md:flex-row" aria-busy="true" aria-label="Cargando el panel">
        <div className="space-y-2 border-b border-panel-border bg-panel-surface p-3 md:w-64 md:shrink-0 md:border-b-0 md:border-r">
          {[0, 1, 2, 3].map((n) => (
            <div key={n} className="h-9 animate-pulse rounded-md bg-panel-bg" />
          ))}
        </div>
        <div className="flex-1 space-y-4 p-4 sm:p-6 lg:p-8">
          <div className="h-8 w-56 animate-pulse rounded-md bg-panel-border" />
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {[0, 1, 2, 3].map((n) => (
              <div key={n} className="h-28 animate-pulse rounded-xl bg-panel-surface shadow-sm" />
            ))}
          </div>
          <div className="h-72 animate-pulse rounded-xl bg-panel-surface shadow-sm" />
        </div>
      </div>
    </Franja>
  );
}
