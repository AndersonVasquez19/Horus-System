import Link from 'next/link';
import { AlertTriangle, Inbox, SearchX, ShieldAlert } from 'lucide-react';
import type { ReactNode } from 'react';

interface EstadoProps {
  titulo: string;
  texto?: string;
  accion?: ReactNode;
}

function Estado({ icono, titulo, texto, accion, rol }: EstadoProps & { icono: ReactNode; rol?: 'alert' | 'status' }) {
  return (
    <div role={rol} className="flex flex-col items-center justify-center rounded-xl border border-dashed border-panel-border bg-panel-surface px-6 py-14 text-center">
      <span className="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-panel-bg text-panel-muted" aria-hidden="true">
        {icono}
      </span>
      <p className="font-heading text-base font-semibold text-panel-text">{titulo}</p>
      {texto ? <p className="mt-1 max-w-md text-sm text-panel-muted">{texto}</p> : null}
      {accion ? <div className="mt-5">{accion}</div> : null}
    </div>
  );
}

export function EstadoVacio(props: EstadoProps) {
  return <Estado icono={<Inbox className="h-6 w-6" />} rol="status" {...props} />;
}

export function EstadoSinResultados(props: EstadoProps) {
  return <Estado icono={<SearchX className="h-6 w-6" />} rol="status" {...props} />;
}

export function EstadoError({ titulo = 'No se pudo cargar la información', ...props }: Partial<EstadoProps>) {
  return <Estado icono={<AlertTriangle className="h-6 w-6" />} rol="alert" titulo={titulo} {...props} />;
}

export function EstadoProhibido({ volver }: { volver: string }) {
  return (
    <Estado
      icono={<ShieldAlert className="h-6 w-6" />}
      rol="alert"
      titulo="No tienes permiso para ver esta sección"
      texto="Si necesitas acceso, solicítalo a un administrador."
      accion={
        <Link href={volver} className="text-sm font-semibold text-panel-text underline underline-offset-4">
          Volver al inicio del panel
        </Link>
      }
    />
  );
}
