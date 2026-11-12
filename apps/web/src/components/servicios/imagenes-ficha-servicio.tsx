'use client';

import { ImagePlus, Pencil, Plus, Trash2 } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useEffect, useId, useState, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoArea, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo, DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import { Insignia } from '@/components/shared/ui/insignia';
import { Seccion } from '@/components/shared/ui/tabla';
import type { ElementoFicha, ServicioDetalle } from '@/features/servicios/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';

type Respuesta = { message: string; data: ServicioDetalle };

const TIPOS = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_BYTES = 3 * 1024 * 1024;
const MAX_TARJETAS = 30;
const VARIANTES = { '': 'Sin definir', producto: 'Producto (recorte)', escena: 'Escena (completa)' };

/** Validación previa en el navegador (comodidad): Laravel y el BFF vuelven a comprobar tipo real y tamaño. */
function problemaDeArchivo(archivo: File): string | null {
  if (!TIPOS.includes(archivo.type)) return 'Solo se admiten imágenes JPG, PNG o WebP.';
  if (archivo.size > MAX_BYTES) return 'La imagen no puede superar los 3 MB.';
  return null;
}

const urlPrincipal = (servicio: ServicioDetalle) => `/api/bff/panel/servicios/servicios/${servicio.id}/imagen`;
const urlTarjeta = (servicio: ServicioDetalle, elemento: ElementoFicha) => `/api/bff/panel/servicios/servicios/${servicio.id}/elementos/${elemento.id}/imagen`;

/** Selector con vista previa. La vista previa usa una URL local del archivo elegido; nada se envía hasta guardar. */
function SelectorImagen({ archivo, onCambio, error, actual }: { archivo: File | null; onCambio: (archivo: File | null) => void; error?: string; actual?: { url: string; alt: string } | null }) {
  const id = useId();
  const [local, setLocal] = useState<string | null>(null);
  const [aviso, setAviso] = useState<string | null>(null);
  const [ilegible, setIlegible] = useState(false);

  useEffect(() => {
    if (!archivo) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setLocal(null);
      return undefined;
    }
    const url = URL.createObjectURL(archivo);
    setLocal(url);
    return () => URL.revokeObjectURL(url);
  }, [archivo]);

  const mostrado = ilegible ? null : (local ?? actual?.url ?? null);
  const mensaje = aviso ?? error;

  return (
    <div>
      <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-panel-text">Imagen (JPG, PNG o WebP, hasta 3 MB)</label>
      {mostrado ? (
        // eslint-disable-next-line @next/next/no-img-element -- imagen autenticada servida por el BFF; next/image no aplica.
        <img src={mostrado} onError={() => setIlegible(true)} alt={actual?.alt || 'Vista previa de la imagen'} className="mb-3 max-h-48 w-full rounded-lg border border-panel-border bg-panel-bg object-contain" />
      ) : null}
      <input
        id={id}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        aria-invalid={mensaje ? true : undefined}
        onChange={(evento) => {
          const elegido = evento.target.files?.[0] ?? null;
          const problema = elegido ? problemaDeArchivo(elegido) : null;
          setAviso(problema);
          setIlegible(false);
          if (problema) evento.target.value = '';
          onCambio(problema ? null : elegido);
        }}
        className="block w-full text-sm text-panel-text file:mr-3 file:rounded-lg file:border-0 file:bg-servicios-700 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-servicios-800"
      />
      {mensaje ? <p role="alert" className="mt-1.5 text-sm text-estado-error">{mensaje}</p> : null}
    </div>
  );
}

type Dialogos =
  | { tipo: 'principal' }
  | { tipo: 'quitar-principal' }
  | { tipo: 'tarjeta'; elemento: ElementoFicha | null }
  | { tipo: 'eliminar-tarjeta'; elemento: ElementoFicha }
  | null;

/**
 * Imagen principal y tarjetas de la ficha pública. Todo viaja por el BFF (`/api/bff/panel/...`, multipart con una
 * sola imagen) hacia Laravel, que valida el contenido real, guarda con nombre generado y mantiene la consistencia entre
 * archivo y registro. Con la ficha publicada o sin borrador no se puede editar (lo exige Laravel; aquí se avisa).
 */
export function ImagenesFichaServicio({ servicio }: { servicio: ServicioDetalle }) {
  const puedeEditar = usePuede('servicios.editar');
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar, subir } = useOperacionPanel();
  const ficha = servicio.ficha;
  const [dialogo, setDialogo] = useState<Dialogos>(null);
  const [archivo, setArchivo] = useState<File | null>(null);
  const [alt, setAlt] = useState('');
  const [tarjeta, setTarjeta] = useState({ titulo: '', descripcion: '', caracteristicas: '', variante_imagen: '', imagen_alt: '', quitar_imagen: false });
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [ocupado, setOcupado] = useState(false);

  if (!ficha) return null;

  const publicada = servicio.estado_editorial === 'publicada' || servicio.estado_editorial === 'programada' || servicio.estado_editorial === 'caducada';
  const editable = puedeEditar && !publicada;
  const err = (campo: string) => errorDeCampo(error, campo);

  const elegirArchivo = (elegido: File | null) => {
    setArchivo(elegido);
    setError(null);
  };

  const cerrar = () => {
    setDialogo(null);
    setArchivo(null);
    setError(null);
  };

  async function terminar(resultado: Awaited<ReturnType<typeof ejecutar<Respuesta>>>) {
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error);
      if (resultado.estado === 412) router.refresh();
      return;
    }
    cerrar();
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  function abrirPrincipal() {
    setAlt(ficha?.imagen_principal_alt ?? '');
    setArchivo(null);
    setError(null);
    setDialogo({ tipo: 'principal' });
  }

  function abrirTarjeta(elemento: ElementoFicha | null) {
    setTarjeta(elemento
      ? { titulo: elemento.titulo, descripcion: elemento.descripcion, caracteristicas: elemento.caracteristicas.join('\n'), variante_imagen: elemento.variante_imagen ?? '', imagen_alt: elemento.imagen_alt ?? '', quitar_imagen: false }
      : { titulo: '', descripcion: '', caracteristicas: '', variante_imagen: '', imagen_alt: '', quitar_imagen: false });
    setArchivo(null);
    setError(null);
    setDialogo({ tipo: 'tarjeta', elemento });
  }

  async function guardarPrincipal(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    if (!archivo) {
      setError({ message: 'Elige una imagen.', code: 'validacion', errors: { imagen: ['Elige una imagen.'] } });
      return;
    }
    setOcupado(true);
    setError(null);
    const formulario = new FormData();
    formulario.set('imagen', archivo);
    formulario.set('imagen_principal_alt', alt.trim());
    formulario.set('_revision', servicio.revision);
    await terminar(await subir<Respuesta>(`servicios/servicios/${servicio.id}/imagen`, formulario));
  }

  async function guardarTarjeta(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    if (dialogo?.tipo !== 'tarjeta') return;
    setOcupado(true);
    setError(null);
    const formulario = new FormData();
    formulario.set('titulo', tarjeta.titulo.trim());
    formulario.set('descripcion', tarjeta.descripcion.trim());
    formulario.set('caracteristicas', tarjeta.caracteristicas);
    formulario.set('variante_imagen', tarjeta.variante_imagen);
    formulario.set('imagen_alt', tarjeta.imagen_alt.trim());
    if (archivo) formulario.set('imagen', archivo);
    if (tarjeta.quitar_imagen && !archivo) formulario.set('quitar_imagen', '1');
    formulario.set('_revision', servicio.revision);
    const elemento = dialogo.elemento;
    await terminar(await subir<Respuesta>(elemento ? `servicios/servicios/${servicio.id}/elementos/${elemento.id}` : `servicios/servicios/${servicio.id}/elementos`, formulario));
  }

  async function quitarPrincipal() {
    setOcupado(true);
    setError(null);
    await terminar(await ejecutar<Respuesta>(`servicios/servicios/${servicio.id}/imagen/quitar`, 'POST', { _revision: servicio.revision }));
  }

  async function eliminarTarjeta() {
    if (dialogo?.tipo !== 'eliminar-tarjeta') return;
    setOcupado(true);
    setError(null);
    await terminar(await ejecutar<Respuesta>(`servicios/servicios/${servicio.id}/elementos/${dialogo.elemento.id}/eliminar`, 'POST', { _revision: servicio.revision }));
  }

  const editandoTarjeta = dialogo?.tipo === 'tarjeta' ? dialogo.elemento : null;
  const cambiarTarjeta = (campo: 'titulo' | 'descripcion' | 'caracteristicas' | 'variante_imagen' | 'imagen_alt') => (evento: { target: { value: string } }) => setTarjeta((actual) => ({ ...actual, [campo]: evento.target.value }));

  return (
    <Seccion
      id="titulo-imagenes"
      titulo="Imagen principal y tarjetas"
      descripcion="Fotografías de la ficha pública. Se guardan en el servidor con un nombre generado; no se publican hasta autorizar la ficha."
      acciones={
        editable ? (
          <Boton tono="servicios" variante="secundario" icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => abrirTarjeta(null)} disabled={ficha.tarjetas >= MAX_TARJETAS}>
            Nueva tarjeta
          </Boton>
        ) : null
      }
    >
      {publicada ? <p className="mb-4 rounded-lg bg-panel-bg px-3 py-2 text-sm text-panel-muted">La ficha está publicada. Para cambiar imágenes o tarjetas, primero retírala.</p> : null}

      <div className="grid gap-4 md:grid-cols-[minmax(0,16rem)_1fr]">
        <div>
          <h3 className="mb-2 text-sm font-semibold text-panel-text">Imagen principal</h3>
          {ficha.con_imagen ? (
            // eslint-disable-next-line @next/next/no-img-element -- imagen autenticada servida por el BFF; next/image no aplica.
            <img key={servicio.revision} src={urlPrincipal(servicio)} alt={ficha.imagen_principal_alt ?? 'Imagen principal de la ficha'} className="aspect-video w-full rounded-lg border border-panel-border bg-panel-bg object-cover" />
          ) : (
            <div className="flex aspect-video w-full items-center justify-center rounded-lg border border-dashed border-panel-border bg-panel-bg text-sm text-panel-muted">Sin imagen</div>
          )}
          {editable ? (
            <div className="mt-3 flex flex-wrap gap-2">
              <Boton tono="servicios" variante="secundario" icono={<ImagePlus className="h-4 w-4" aria-hidden="true" />} onClick={abrirPrincipal}>{ficha.con_imagen ? 'Reemplazar' : 'Subir imagen'}</Boton>
              {ficha.con_imagen ? <Boton tono="servicios" variante="fantasma" icono={<Trash2 className="h-4 w-4" aria-hidden="true" />} onClick={() => { setError(null); setDialogo({ tipo: 'quitar-principal' }); }}>Quitar</Boton> : null}
            </div>
          ) : null}
        </div>

        <div>
          <h3 className="mb-2 text-sm font-semibold text-panel-text">Tarjetas ({ficha.tarjetas})</h3>
          {ficha.elementos.length === 0 ? (
            <p className="rounded-lg border border-dashed border-panel-border p-6 text-center text-sm text-panel-muted">Esta ficha todavía no tiene tarjetas.</p>
          ) : (
            <ul className="grid gap-3 sm:grid-cols-2">
              {ficha.elementos.map((elemento) => (
                <li key={elemento.id} className="flex flex-col overflow-hidden rounded-lg border border-panel-border bg-panel-surface">
                  {elemento.con_imagen ? (
                    // eslint-disable-next-line @next/next/no-img-element -- imagen autenticada servida por el BFF; next/image no aplica.
                    <img key={servicio.revision} src={urlTarjeta(servicio, elemento)} alt={elemento.imagen_alt ?? elemento.titulo} className="aspect-video w-full bg-panel-bg object-cover" />
                  ) : null}
                  <div className="flex flex-1 flex-col gap-1 p-3">
                    <div className="flex items-start justify-between gap-2">
                      <p className="text-sm font-semibold text-panel-text">{elemento.titulo}</p>
                      {!elemento.activo ? <Insignia color="gris">Oculta</Insignia> : null}
                    </div>
                    <p className="line-clamp-3 text-xs text-panel-muted">{elemento.descripcion}</p>
                    {editable ? (
                      <div className="mt-auto flex flex-wrap gap-1 pt-2">
                        <Boton tono="servicios" variante="fantasma" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => abrirTarjeta(elemento)}>Editar</Boton>
                        <Boton tono="servicios" variante="fantasma" icono={<Trash2 className="h-4 w-4" aria-hidden="true" />} onClick={() => { setError(null); setDialogo({ tipo: 'eliminar-tarjeta', elemento }); }}>Eliminar</Boton>
                      </div>
                    ) : null}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      <Dialogo abierto={dialogo?.tipo === 'principal'} titulo={ficha.con_imagen ? 'Reemplazar la imagen principal' : 'Subir la imagen principal'} descripcion={`${servicio.codigo} · ${servicio.nombre}`} onCerrar={cerrar} bloqueado={ocupado}>
        <form onSubmit={guardarPrincipal} noValidate className="space-y-4">
          {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
          <SelectorImagen archivo={archivo} onCambio={elegirArchivo} error={err('imagen')} actual={ficha.con_imagen ? { url: urlPrincipal(servicio), alt: 'Imagen actual' } : null} />
          <Campo tono="servicios" etiqueta="Texto alternativo (opcional)" value={alt} onChange={(evento) => setAlt(evento.target.value)} error={err('imagen_principal_alt')} maxLength={160} ayuda="Describe la imagen para personas que no pueden verla." />
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton tono="servicios" variante="secundario" onClick={cerrar} disabled={ocupado}>Cancelar</Boton>
            <Boton tono="servicios" type="submit" cargando={ocupado}>{ocupado ? 'Subiendo…' : 'Guardar imagen'}</Boton>
          </div>
        </form>
      </Dialogo>

      <Dialogo abierto={dialogo?.tipo === 'tarjeta'} titulo={editandoTarjeta ? 'Editar tarjeta' : 'Nueva tarjeta'} descripcion={`${servicio.codigo} · ${servicio.nombre}`} onCerrar={cerrar} bloqueado={ocupado}>
        <form onSubmit={guardarTarjeta} noValidate className="space-y-4">
          {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
          <Campo tono="servicios" etiqueta="Título" value={tarjeta.titulo} onChange={cambiarTarjeta('titulo')} error={err('titulo')} required maxLength={120} />
          <CampoArea tono="servicios" etiqueta="Descripción" value={tarjeta.descripcion} onChange={cambiarTarjeta('descripcion')} error={err('descripcion')} required rows={3} maxLength={1000} />
          <CampoArea tono="servicios" etiqueta="Características (una por línea, opcional)" value={tarjeta.caracteristicas} onChange={cambiarTarjeta('caracteristicas')} error={err('caracteristicas') ?? err('caracteristicas.0')} rows={3} />
          <CampoSeleccion tono="servicios" etiqueta="Presentación de la imagen" opciones={VARIANTES} value={tarjeta.variante_imagen} onChange={cambiarTarjeta('variante_imagen')} error={err('variante_imagen')} />
          <SelectorImagen
            archivo={archivo}
            onCambio={elegirArchivo}
            error={err('imagen')}
            actual={editandoTarjeta?.con_imagen && !tarjeta.quitar_imagen ? { url: urlTarjeta(servicio, editandoTarjeta), alt: 'Imagen actual' } : null}
          />
          {editandoTarjeta?.con_imagen ? (
            <label className="flex items-center gap-2 text-sm text-panel-text">
              <input type="checkbox" checked={tarjeta.quitar_imagen} onChange={(evento) => setTarjeta((actual) => ({ ...actual, quitar_imagen: evento.target.checked }))} className="h-4 w-4 accent-servicios-700" />
              Quitar la imagen actual (si no subes otra)
            </label>
          ) : null}
          <Campo tono="servicios" etiqueta="Texto alternativo de la imagen (opcional)" value={tarjeta.imagen_alt} onChange={cambiarTarjeta('imagen_alt')} error={err('imagen_alt')} maxLength={160} />
          <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
            <Boton tono="servicios" variante="secundario" onClick={cerrar} disabled={ocupado}>Cancelar</Boton>
            <Boton tono="servicios" type="submit" cargando={ocupado}>{ocupado ? 'Guardando…' : 'Guardar tarjeta'}</Boton>
          </div>
        </form>
      </Dialogo>

      <DialogoConfirmacion
        abierto={dialogo?.tipo === 'quitar-principal'}
        tono="servicios"
        titulo="¿Quitar la imagen principal?"
        texto={<p>La imagen se eliminará del servidor si ninguna otra parte de la ficha la usa. Podrás subir otra después.</p>}
        confirmar="Quitar imagen"
        peligro
        cargando={ocupado}
        error={dialogo?.tipo === 'quitar-principal' && error ? error.message : null}
        onConfirmar={() => void quitarPrincipal()}
        onCerrar={cerrar}
      />

      <DialogoConfirmacion
        abierto={dialogo?.tipo === 'eliminar-tarjeta'}
        tono="servicios"
        titulo="¿Eliminar la tarjeta?"
        texto={<p>Se eliminará la tarjeta{dialogo?.tipo === 'eliminar-tarjeta' ? ` «${dialogo.elemento.titulo}»` : ''} y su imagen. Esta acción no se puede deshacer.</p>}
        confirmar="Eliminar tarjeta"
        peligro
        cargando={ocupado}
        error={dialogo?.tipo === 'eliminar-tarjeta' && error ? error.message : null}
        onConfirmar={() => void eliminarTarjeta()}
        onCerrar={cerrar}
      />
    </Seccion>
  );
}
