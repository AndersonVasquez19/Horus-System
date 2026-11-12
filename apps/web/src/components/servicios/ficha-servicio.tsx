'use client';

import { Globe, GlobeLock, PenLine } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoArea } from '@/components/shared/ui/campo';
import { Dialogo, DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import type { ServicioDetalle } from '@/features/servicios/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { etiqueta, fecha } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

type Respuesta = { message: string; data: ServicioDetalle };
type Accion = 'borrador' | 'autorizar' | 'retirar' | null;

const CAMPOS = ['titulo', 'resumen', 'descripcion', 'categoria', 'vigente_desde', 'vigente_hasta', 'titulo_tarjetas', 'imagen_principal_alt'] as const;
type Datos = Record<(typeof CAMPOS)[number], string>;

/**
 * Ficha pública del servicio: borrador editorial, autorización y retiro, siempre con la revisión vigente (`_revision`).
 * Publicar exige un servicio activo con ficha (lo comprueba Laravel). La imagen principal y las tarjetas se gestionan
 * en `ImagenesFichaServicio` (multipart por el BFF).
 */
export function FichaServicioPanel({ servicio }: { servicio: ServicioDetalle }) {
  const puedeEditar = usePuede('servicios.editar');
  const puedePublicar = usePuede('servicios.publicar');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const ficha = servicio.ficha;
  const [accion, setAccion] = useState<Accion>(null);
  const [datos, setDatos] = useState<Datos>(() => inicial(servicio));
  const [confirmada, setConfirmada] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);

  const publicada = servicio.estado_editorial === 'publicada' || servicio.estado_editorial === 'programada' || servicio.estado_editorial === 'caducada';

  async function enviar(evento?: FormEvent<HTMLFormElement>) {
    evento?.preventDefault();
    setGuardando(true);
    setError(null);
    const base = `servicios/servicios/${servicio.id}/publicacion`;
    const resultado = accion === 'borrador'
      ? await ejecutar<Respuesta>(base, 'PUT', {
        ...Object.fromEntries(CAMPOS.map((campo) => [campo, datos[campo].trim() === '' ? null : datos[campo].trim()])),
        _revision: servicio.revision,
      })
      : accion === 'autorizar'
        ? await ejecutar<Respuesta>(`${base}/autorizar`, 'POST', { confirmacion_publica: confirmada, _revision: servicio.revision })
        : await ejecutar<Respuesta>(`${base}/retirar`, 'POST', { _revision: servicio.revision });
    setGuardando(false);
    if (!resultado.ok) {
      setError(resultado.error);
      if (resultado.estado === 412) router.refresh();
      return;
    }
    setAccion(null);
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  const abrir = (siguiente: Accion) => {
    setDatos(inicial(servicio));
    setConfirmada(false);
    setError(null);
    setAccion(siguiente);
  };
  const cambiar = (campo: keyof Datos) => (evento: { target: { value: string } }) => setDatos((actuales) => ({ ...actuales, [campo]: evento.target.value }));
  const err = (campo: string) => errorDeCampo(error, campo);

  return (
    <Seccion
      id="titulo-ficha"
      titulo="Ficha pública"
      descripcion="Lo que se muestra en el sitio web. Publicar es una autorización editorial distinta de activar el servicio."
      acciones={
        <div className="flex flex-wrap gap-2">
          {puedeEditar && !publicada ? (
            <Boton tono="servicios" variante="secundario" icono={<PenLine className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('borrador')}>
              {ficha ? 'Editar borrador' : 'Preparar ficha'}
            </Boton>
          ) : null}
          {puedePublicar && publicada ? (
            <Boton tono="servicios" variante="secundario" icono={<GlobeLock className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('retirar')}>Retirar</Boton>
          ) : null}
          {puedePublicar && ficha && !publicada ? (
            <Boton tono="servicios" icono={<Globe className="h-4 w-4" aria-hidden="true" />} onClick={() => abrir('autorizar')}>Autorizar publicación</Boton>
          ) : null}
        </div>
      }
    >
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <Insignia color={colorEstado(servicio.estado_editorial === 'caducada' || servicio.estado_editorial === 'programada' ? 'borrador' : servicio.estado_editorial)}>
          {etiqueta('estadoEditorial', servicio.estado_editorial)}
        </Insignia>
        {publicada ? <span className="text-xs text-panel-muted">Para editar el contenido, primero retira la ficha.</span> : null}
      </div>

      {ficha ? (
        <ListaDatos
          filas={[
            ['Título', ficha.titulo],
            ['Resumen', ficha.resumen],
            ['Descripción', <span key="d" className="whitespace-pre-line">{ficha.descripcion}</span>],
            ['Vigencia', `${fecha(ficha.vigente_desde)} – ${fecha(ficha.vigente_hasta)}`],
            ['Título de tarjetas', ficha.titulo_tarjetas ?? '—'],
            ['Imagen principal', ficha.con_imagen ? (ficha.imagen_principal_alt ?? 'Sin texto alternativo') : 'Sin imagen'],
            ['Tarjetas', String(ficha.tarjetas)],
            ['Autorizada', fecha(ficha.autorizado_en)],
          ]}
        />
      ) : (
        <p className="py-6 text-center text-sm text-panel-muted">Este servicio todavía no tiene ficha pública.</p>
      )}

      <Dialogo abierto={accion === 'borrador'} titulo={ficha ? 'Editar borrador de la ficha' : 'Preparar ficha pública'} descripcion={`${servicio.codigo} · ${servicio.nombre}`} onCerrar={() => setAccion(null)} bloqueado={guardando}>
        <form onSubmit={enviar} noValidate className="space-y-4">
          {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
          <div className="grid gap-4 sm:grid-cols-2">
            <Campo tono="servicios" etiqueta="Título público" value={datos.titulo} onChange={cambiar('titulo')} error={err('titulo')} required maxLength={120} className="sm:col-span-2" />
            <CampoArea tono="servicios" etiqueta="Resumen" value={datos.resumen} onChange={cambiar('resumen')} error={err('resumen')} required rows={2} maxLength={500} className="sm:col-span-2" />
            <CampoArea tono="servicios" etiqueta="Descripción" value={datos.descripcion} onChange={cambiar('descripcion')} error={err('descripcion')} required rows={5} maxLength={5000} className="sm:col-span-2" />
            <Campo tono="servicios" etiqueta="Vigente desde" type="date" value={datos.vigente_desde} onChange={cambiar('vigente_desde')} error={err('vigente_desde')} required />
            <Campo tono="servicios" etiqueta="Vigente hasta" type="date" value={datos.vigente_hasta} onChange={cambiar('vigente_hasta')} error={err('vigente_hasta')} required />
            <Campo tono="servicios" etiqueta="Categoría (opcional)" value={datos.categoria} onChange={cambiar('categoria')} error={err('categoria')} maxLength={80} />
            <Campo tono="servicios" etiqueta="Título de las tarjetas (opcional)" value={datos.titulo_tarjetas} onChange={cambiar('titulo_tarjetas')} error={err('titulo_tarjetas')} maxLength={120} />
            <Campo tono="servicios" etiqueta="Texto alternativo de la imagen (opcional)" value={datos.imagen_principal_alt} onChange={cambiar('imagen_principal_alt')} error={err('imagen_principal_alt')} maxLength={160} className="sm:col-span-2" />
          </div>
          <p className="text-xs text-panel-muted">Guardar deja la ficha como borrador y retira cualquier autorización anterior; hay que autorizarla de nuevo.</p>
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton tono="servicios" variante="secundario" onClick={() => setAccion(null)} disabled={guardando}>Cancelar</Boton>
            <Boton tono="servicios" type="submit" cargando={guardando}>Guardar borrador</Boton>
          </div>
        </form>
      </Dialogo>

      <DialogoConfirmacion
        abierto={accion === 'autorizar'}
        tono="servicios"
        titulo="¿Autorizar la publicación?"
        texto={
          <div className="space-y-3">
            <p>El contenido de la ficha podrá mostrarse en el sitio web dentro de su vigencia, mientras el servicio esté activo.</p>
            <label className="flex items-start gap-2 text-panel-text">
              <input type="checkbox" checked={confirmada} onChange={(evento) => {
              setConfirmada(evento.target.checked);
              setError((previo) => (previo?.errors?.confirmacion_publica ? { ...previo, errors: { ...previo.errors, confirmacion_publica: [] } } : previo));
            }} className="mt-0.5 h-4 w-4 accent-servicios-700" />
              Confirmo que este contenido puede mostrarse públicamente.
            </label>
            {err('confirmacion_publica') ? <p className="text-estado-error">{err('confirmacion_publica')}</p> : null}
          </div>
        }
        confirmar="Autorizar"
        cargando={guardando}
        error={error && accion === 'autorizar' && error.code !== 'validacion' ? error.message : null}
        onConfirmar={() => void enviar()}
        onCerrar={() => setAccion(null)}
      />

      <DialogoConfirmacion
        abierto={accion === 'retirar'}
        tono="servicios"
        titulo="¿Retirar la ficha pública?"
        texto={<p>El servicio dejará de mostrarse en el sitio web. Podrás editarla y autorizarla de nuevo.</p>}
        confirmar="Retirar"
        peligro
        cargando={guardando}
        error={error && accion === 'retirar' ? error.message : null}
        onConfirmar={() => void enviar()}
        onCerrar={() => setAccion(null)}
      />
    </Seccion>
  );
}

function inicial(servicio: ServicioDetalle): Datos {
  const ficha = servicio.ficha;
  return {
    titulo: ficha?.titulo ?? servicio.nombre,
    resumen: ficha?.resumen ?? '',
    descripcion: ficha?.descripcion ?? '',
    categoria: ficha?.categoria ?? servicio.categoria ?? '',
    vigente_desde: ficha?.vigente_desde ?? '',
    vigente_hasta: ficha?.vigente_hasta ?? '',
    titulo_tarjetas: ficha?.titulo_tarjetas ?? '',
    imagen_principal_alt: ficha?.imagen_principal_alt ?? '',
  };
}
