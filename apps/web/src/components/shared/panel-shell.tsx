import Link from 'next/link';
import { redirect } from 'next/navigation';
import { connection } from 'next/server';
import { ShieldAlert, WifiOff } from 'lucide-react';
import { Suspense, type ReactNode } from 'react';
import { LogoHorus, MarcaHorus } from './marca-horus';
import { obtenerSesion } from '@/lib/panel/sesion';
import { urlLogin } from '@/lib/panel/redireccion';
import { BotonCerrarSesion } from './boton-cerrar-sesion';
import { CambioClaveObligatorio } from './cambio-clave-obligatorio';
import { CampanaNotificaciones, CampanaSinConteo, obtenerInsigniasNavegacion } from './campana-notificaciones';
import { MarcaPanel } from './marca-panel';
import { MenuUsuarioPanel } from './menu-usuario-panel';
import { PanelNavegacion } from './panel-navegacion';
import { PaletaComandos } from './paleta-comandos';
import { filtrarNavegacion, type ModeloNavegacion, type TonoPanel } from './panel-tipos';
import { RenovadorSesion } from './renovador-sesion';
import { ProveedorSesionPanel } from './sesion-panel-contexto';
import { ProveedorAvisos } from './ui/avisos';

/*
 * Las clases se escriben completas (no se componen) para que Tailwind las detecte.
 * Texto blanco sobre 800; foco con 700 (ver globals.css para los contrastes).
 */
const ESTILO_PANEL = {
  foco: 'focus-visible:outline-academico-700',
  enlace: 'text-academico-700 focus-visible:outline-academico-700',
} as const;

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
  return (
    <MarcaPanel atributo="data-panel-tono" valor={tono} className="flex min-h-screen flex-col">
      <a
        href="#contenido-panel"
        className={`sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-md focus:bg-panel-surface focus:px-3 focus:py-2 focus:text-panel-text focus:shadow focus-visible:outline-2 focus-visible:outline-offset-2 ${ESTILO_PANEL.foco}`}
      >
        Saltar al contenido
      </a>

      <Suspense fallback={<EsqueletoPanel titulo={titulo} />}>
        <GuardiaPanel tono={tono} titulo={titulo} navegacion={navegacion}>
          {children}
        </GuardiaPanel>
      </Suspense>
    </MarcaPanel>
  );
}

async function GuardiaPanel({ tono, titulo, navegacion, children }: PanelShellProps) {
  await connection();
  const resultado = await obtenerSesion();

  if (resultado.estado === 'anonimo') redirect(urlLogin(`/admin/${tono}`));
  if (resultado.estado === 'no_disponible') {
    return (
      <Franja titulo={titulo}>
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
      <Franja titulo={titulo} acciones={<BotonCerrarSesion className="text-panel-text hover:bg-panel-bg focus-visible:outline-academico-700" />}>
        <EstadoPagina
          icono={<ShieldAlert className="h-6 w-6" aria-hidden="true" />}
          titulo="No tienes acceso a este panel"
          texto="Tu cuenta no tiene permiso para entrar aquí. Si crees que es un error, contacta con un administrador."
          enlace={{ href: '/', texto: 'Ir al sitio público', clase: ESTILO_PANEL.enlace }}
        />
      </Franja>
    );
  }

  const modeloFiltrado = filtrarNavegacion(navegacion, sesion.permisos);
  const bloqueadoPorClave = Boolean(sesion.debe_cambiar_clave);

  if (bloqueadoPorClave) {
    return (
      <ProveedorSesionPanel sesion={sesion}>
        <ProveedorAvisos>
          <Franja titulo={titulo} acciones={<BotonCerrarSesion className="text-panel-text hover:bg-panel-bg focus-visible:outline-academico-700" />}>
            <RenovadorSesion />
            <div id="contenido-panel" tabIndex={-1} className={`min-w-0 flex-1 p-4 sm:p-6 ${ESTILO_PANEL.foco}`}>
              <CambioClaveObligatorio sesion={sesion} tono={tono} />
            </div>
          </Franja>
        </ProveedorAvisos>
      </ProveedorSesionPanel>
    );
  }

  return (
    <ProveedorSesionPanel sesion={sesion}>
      <ProveedorAvisos>
        <Franja
          titulo={titulo}
          acciones={
            <>
              <PaletaComandos />
              <Suspense fallback={<CampanaSinConteo tono={tono} />}>
                <CampanaNotificaciones tono={tono} />
              </Suspense>
              <MenuUsuarioPanel usuario={sesion.usuario} tono={tono} />
            </>
          }
          riel={
            <Suspense fallback={<PanelNavegacion titulo={titulo} modelo={modeloFiltrado} />}>
              <RielConInsignias titulo={titulo} modelo={modeloFiltrado} />
            </Suspense>
          }
        >
          <RenovadorSesion />
          <div
            id="contenido-panel"
            tabIndex={-1}
            className={`min-w-0 flex-1 p-4 outline-none focus-visible:outline-2 focus-visible:-outline-offset-2 sm:p-6 lg:p-8 ${ESTILO_PANEL.foco}`}
          >
            {children}
          </div>
        </Franja>
      </ProveedorAvisos>
    </ProveedorSesionPanel>
  );
}

async function RielConInsignias({ titulo, modelo }: { titulo: string; modelo: ModeloNavegacion }) {
  const insignias = await obtenerInsigniasNavegacion();
  return <PanelNavegacion titulo={titulo} modelo={modelo} insignias={insignias} />;
}

function Franja({ titulo, acciones, riel, children }: { titulo: string; acciones?: ReactNode; riel?: ReactNode; children: ReactNode }) {
  return (
    <div className="flex flex-1 flex-col md:min-h-screen md:flex-row">
      {riel ? (
        <div className="bg-tinta text-white md:sticky md:top-0 md:h-screen md:w-64 md:shrink-0 md:overflow-y-auto">
          <div className="px-4 pb-1 pt-4 md:px-5 md:pt-6">
            <MarcaHorus subtitulo={titulo} />
          </div>
          {riel}
        </div>
      ) : null}
      <div className="flex min-w-0 flex-1 flex-col">
        <div className="flex items-center justify-between gap-3 border-b border-panel-border bg-panel-surface px-4 py-2.5 sm:px-6">
          {riel ? (
            <p className="min-w-0 truncate font-heading text-sm font-semibold text-panel-text">{titulo}</p>
          ) : (
            <MarcaHorusClara subtitulo={titulo} />
          )}
          {acciones ? <div className="flex shrink-0 items-center gap-1 sm:gap-2">{acciones}</div> : null}
        </div>
        {children}
      </div>
    </div>
  );
}

/** Marca sobre fondo claro, para los estados sin riel (sin sesión, sin acceso). */
function MarcaHorusClara({ subtitulo }: { subtitulo: string }) {
  return (
    <div className="flex items-center gap-2 text-tinta">
      <LogoHorus className="h-6 w-auto shrink-0" />
      <p className="font-heading text-sm font-semibold">Horus Group <span className="font-normal text-panel-muted">{subtitulo}</span></p>
    </div>
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

/** Riel lateral de carga: oscuro como el real, con barras neutras (aún no se sabe qué módulos puede usar la persona). */
function RielEsqueleto() {
  return (
    <div className="space-y-2 px-4 pb-6 pt-4 md:px-5" aria-hidden="true">
      {[0, 1, 2, 3, 4, 5, 6, 7].map((n) => (
        <div key={n} className="h-8 animate-pulse rounded-md bg-white/10" />
      ))}
    </div>
  );
}

/**
 * Lo primero que se pinta al entrar o recargar, hasta que Laravel confirma la sesión. Lleva el riel oscuro y la franja
 * superior del panel real: sin riel era una pantalla clara casi vacía con el título «Gestión académica».
 */
function EsqueletoPanel({ titulo }: { titulo: string }) {
  return (
    <Franja titulo={titulo} riel={<RielEsqueleto />}>
      <div className="flex flex-1 flex-col" aria-busy="true" aria-label="Cargando el panel">
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
