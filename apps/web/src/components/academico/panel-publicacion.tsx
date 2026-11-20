'use client';

import { Globe, GlobeLock, ImagePlus, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, useSyncExternalStore, type ChangeEvent, type FormEvent } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoArea, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo, DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import { Insignia } from '@/components/shared/ui/insignia';
import { ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import type { CursoDetalle, PublicacionCurso } from '@/features/academico/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { ETIQUETAS, fecha, hoyLima, sumarDias } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

type Respuesta = { message?: string; data: PublicacionCurso };

/** Vigencia de una ficha frente a hoy (Lima). Las fechas guardadas son AAAA-MM-DD y se comparan como texto. */
function vigenciaDe(ficha: { vigente_desde: string; vigente_hasta: string } | null, hoy: string): 'vigente' | 'programada' | 'vencida' | null {
  if (!ficha) return null;
  if (ficha.vigente_hasta < hoy) return 'vencida';
  if (ficha.vigente_desde > hoy) return 'programada';
  return 'vigente';
}

const suscribirVacio = () => () => {};

/** Días de vigencia que se sugieren al autorizar una ficha nueva. */
const VIGENCIA_SUGERIDA_DIAS = 30;
const MAX_IMAGEN_BYTES = 2 * 1024 * 1024;
const TIPOS_IMAGEN = new Set(['image/jpeg', 'image/png', 'image/webp']);

function rutaRelativaStorage(url: string | null | undefined): string | null {
  if (!url) return null;
  try {
    const parsed = new URL(url, 'http://localhost');
    if (parsed.pathname.startsWith('/storage/')) return parsed.pathname;
  } catch {
    // ignorar URL inválida
  }
  return url;
}

/**
 * Ficha pública del curso (sección del detalle). Solo se monta con `cursos.publicar`. La revisión (ETag) llega con
 * cada lectura y se devuelve al escribir: si otra persona cambió la ficha, Laravel responde 412 y no se guarda nada.
 * La visibilidad real depende además de la vigencia y de que el curso siga activo (lo decide el catálogo público).
 */
export function PanelPublicacion({ curso }: { curso: CursoDetalle }) {
  const puede = usePuede('cursos.publicar');
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [estado, setEstado] = useState<{ tipo: 'cargando' } | { tipo: 'error'; texto: string } | { tipo: 'ok'; datos: PublicacionCurso }>({ tipo: 'cargando' });
  const [editando, setEditando] = useState(false);
  const [retirando, setRetirando] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [errorRetiro, setErrorRetiro] = useState<string | null>(null);

  // Cada cambio de `lectura` vuelve a pedir la ficha (reintento o revisión caducada).
  const [lectura, setLectura] = useState(0);
  const hoy = useSyncExternalStore(suscribirVacio, hoyLima, () => null);
  const cargar = useCallback(() => setLectura((n) => n + 1), []);

  useEffect(() => {
    if (!puede) return;
    let vigente = true;
    void ejecutar<{ data: PublicacionCurso }>(`academico/cursos/${curso.id}/publicacion`).then((resultado) => {
      if (vigente) setEstado(resultado.ok ? { tipo: 'ok', datos: resultado.datos.data } : { tipo: 'error', texto: resultado.error.message });
    });
    return () => {
      vigente = false;
    };
  }, [puede, curso.id, ejecutar, lectura]);

  if (!puede) return null;

  async function retirar(revision: string) {
    setOcupado(true);
    setErrorRetiro(null);
    const resultado = await ejecutar<Respuesta>(`academico/cursos/${curso.id}/publicacion/retirar`, 'POST', { _revision: revision });
    setOcupado(false);
    if (!resultado.ok) {
      setErrorRetiro(resultado.error.message);
      if (resultado.estado === 412) cargar();
      return;
    }
    setRetirando(false);
    mostrar('exito', resultado.datos.message ?? 'La ficha dejó de estar publicada.');
    setEstado({ tipo: 'ok', datos: resultado.datos.data });
  }

  const datos = estado.tipo === 'ok' ? estado.datos : null;
  const ficha = datos?.ficha ?? null;
  const archivado = curso.eliminado;
  const vigencia = ficha?.publicada && hoy ? vigenciaDe(ficha, hoy) : null;

  return (
    <Seccion
      id="titulo-ficha"
      titulo="Ficha pública"
      descripcion="Lo que se muestra en el sitio web. Publicar es una autorización editorial distinta de activar el curso."
      acciones={
        datos ? (
          <div className="flex flex-wrap gap-2">
            {ficha?.publicada ? (
              <Boton variante="secundario" icono={<GlobeLock className="h-4 w-4" aria-hidden="true" />} onClick={() => { setErrorRetiro(null); setRetirando(true); }}>
                Retirar
              </Boton>
            ) : null}
            <Boton icono={<Globe className="h-4 w-4" aria-hidden="true" />} onClick={() => setEditando(true)} disabled={!datos.publicable}>
              {ficha ? 'Editar y publicar' : 'Autorizar ficha'}
            </Boton>
          </div>
        ) : null
      }
    >
      {estado.tipo === 'cargando' ? <p className="py-6 text-center text-sm text-panel-muted" aria-busy="true">Cargando la ficha…</p> : null}
      {estado.tipo === 'error' ? (
        <Alerta tipo="error">
          {estado.texto}{' '}
          <button type="button" onClick={() => { setEstado({ tipo: 'cargando' }); cargar(); }} className="font-semibold underline">Reintentar</button>
        </Alerta>
      ) : null}

      {datos && !datos.publicable ? (
        <Alerta tipo="aviso" className="mb-4">
          {archivado ? 'El curso está archivado.' : 'El curso no está activo.'} Solo se puede publicar un curso activo y no archivado.
        </Alerta>
      ) : null}

      {vigencia === 'programada' && ficha && hoy ? (
        <Alerta tipo="aviso" className="mb-4">
          <strong>Publicación programada:</strong> se mostrará desde el {fecha(ficha.vigente_desde)}. Para mostrarla hoy, cambia la fecha de inicio a {fecha(hoy)} y vuelve a publicar.
        </Alerta>
      ) : null}
      {vigencia === 'vencida' && ficha ? (
        <Alerta tipo="aviso" className="mb-4">
          <strong>Publicación vencida:</strong> dejó de mostrarse después del {fecha(ficha.vigente_hasta)}. Actualiza la vigencia y vuelve a publicar para mostrarla de nuevo.
        </Alerta>
      ) : null}
      {vigencia === 'vigente' && ficha ? (
        <p className="mb-3 text-sm text-panel-muted">Ficha autorizada y vigente hasta el {fecha(ficha.vigente_hasta)}, mientras el curso siga activo.</p>
      ) : null}

      {datos && ficha === null ? <p className="py-6 text-center text-sm text-panel-muted">Este curso todavía no tiene ficha pública.</p> : null}

      {ficha ? (
        <>
          <div className="mb-3 flex items-center justify-between gap-3">
            <Insignia color={ficha.publicada ? 'verde' : 'gris'}>{ficha.publicada ? 'Autorizada' : 'Retirada'}</Insignia>
          </div>
          {rutaRelativaStorage(ficha.imagen_url) ? (
            <div className="mb-4 overflow-hidden rounded-lg border border-panel-border bg-stone-50">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={rutaRelativaStorage(ficha.imagen_url) ?? ''}
                alt={`Imagen referencial de ${ficha.titulo}`}
                className="h-44 w-full object-cover sm:max-w-sm"
              />
            </div>
          ) : null}
          <ListaDatos
            filas={[
              ['Título', ficha.titulo],
              ['Resumen', ficha.resumen],
              ['Imagen referencial', ficha.imagen_url ? 'Cargada' : 'Sin imagen'],
              ['Modalidad', ETIQUETAS.modalidad[ficha.modalidad as keyof typeof ETIQUETAS.modalidad] ?? ficha.modalidad],
              ['Fecha de inicio', ficha.fecha_inicio ? fecha(ficha.fecha_inicio) : '—'],
              ['Duración', ficha.duracion ?? '—'],
              ['Horario', ficha.horario ?? '—'],
              ['Certificación', ficha.certificacion ?? '—'],
              ['Temario', ficha.temario ?? '—'],
              ['Inscripción', ficha.inscripcion ?? '—'],
              ['Vigencia', `${fecha(ficha.vigente_desde)} – ${fecha(ficha.vigente_hasta)}`],
              ['Autorizada', fecha(ficha.autorizado_en)],
            ]}
          />
        </>
      ) : null}

      {datos ? (
        <>
          <Dialogo abierto={editando} titulo={ficha ? 'Editar ficha pública' : 'Autorizar ficha pública'} descripcion={`${curso.codigo} · ${curso.nombre}`} onCerrar={() => setEditando(false)} bloqueado={ocupado}>
            {editando ? (
              <FormularioFicha
                curso={curso}
                datos={datos}
                onOcupado={setOcupado}
                onCancelar={() => setEditando(false)}
                onExpirada={cargar}
                onGuardado={(nuevo, mensaje) => {
                  setEditando(false);
                  mostrar('exito', mensaje);
                  setEstado({ tipo: 'ok', datos: nuevo });
                }}
              />
            ) : null}
          </Dialogo>

          <DialogoConfirmacion
            abierto={retirando}
            titulo="¿Retirar la ficha pública?"
            texto={<p>El curso dejará de mostrarse en el sitio web. Puedes volver a autorizarla después.</p>}
            confirmar="Retirar"
            peligro
            cargando={ocupado}
            error={errorRetiro}
            onConfirmar={() => void retirar(datos.revision)}
            onCerrar={() => setRetirando(false)}
          />
        </>
      ) : null}
    </Seccion>
  );
}

const CAMPOS = ['titulo', 'resumen', 'modalidad', 'horario', 'fecha_inicio', 'duracion', 'certificacion', 'temario', 'inscripcion', 'vigente_desde', 'vigente_hasta'] as const;
type DatosFicha = Record<(typeof CAMPOS)[number], string>;

function FormularioFicha({ curso, datos, onGuardado, onCancelar, onOcupado, onExpirada }: {
  curso: CursoDetalle;
  datos: PublicacionCurso;
  onGuardado: (datos: PublicacionCurso, mensaje: string) => void;
  onCancelar: () => void;
  onOcupado: (ocupado: boolean) => void;
  onExpirada: () => void;
}) {
  const { ejecutar, subir } = useOperacionPanel();
  const ficha = datos.ficha;
  // Hoy se calcula una vez al abrir el formulario; con ficha nueva se sugieren hoy y 30 días de vigencia.
  const [hoy] = useState(() => hoyLima());
  const aviso = vigenciaDe(ficha, hoy);
  const [valores, setValores] = useState<DatosFicha>({
    titulo: ficha?.titulo ?? curso.nombre,
    resumen: ficha?.resumen ?? '',
    modalidad: ficha?.modalidad ?? curso.modalidad,
    horario: ficha?.horario ?? curso.horario ?? '',
    fecha_inicio: ficha?.fecha_inicio ?? '',
    duracion: ficha?.duracion ?? '',
    certificacion: ficha?.certificacion ?? '',
    temario: ficha?.temario ?? '',
    inscripcion: ficha?.inscripcion ?? '',
    vigente_desde: ficha?.vigente_desde ?? hoy,
    vigente_hasta: ficha?.vigente_hasta ?? sumarDias(hoy, VIGENCIA_SUGERIDA_DIAS),
  });
  const [confirmada, setConfirmada] = useState(false);
  const [imagenArchivo, setImagenArchivo] = useState<File | null>(null);
  const [vistaPrevia, setVistaPrevia] = useState<string | null>(() => rutaRelativaStorage(ficha?.imagen_url));
  const [quitarImagen, setQuitarImagen] = useState(false);
  const [errorImagen, setErrorImagen] = useState<string | null>(null);
  const inputImagen = useRef<HTMLInputElement | null>(null);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);

  useEffect(() => {
    return () => {
      if (vistaPrevia?.startsWith('blob:')) URL.revokeObjectURL(vistaPrevia);
    };
  }, [vistaPrevia]);

  const cambiar = (campo: keyof DatosFicha) => (evento: { target: { value: string } }) =>
    setValores((actuales) => ({ ...actuales, [campo]: evento.target.value }));

  function alElegirImagen(evento: ChangeEvent<HTMLInputElement>) {
    const archivo = evento.target.files?.[0];
    if (!archivo) return;
    if (!TIPOS_IMAGEN.has(archivo.type)) {
      setErrorImagen('Solo se admiten imágenes JPG, PNG o WebP.');
      evento.target.value = '';
      return;
    }
    if (archivo.size > MAX_IMAGEN_BYTES) {
      setErrorImagen('La imagen no puede superar los 2 MB.');
      evento.target.value = '';
      return;
    }
    setErrorImagen(null);
    setQuitarImagen(false);
    setImagenArchivo(archivo);
    setVistaPrevia((previa) => {
      if (previa?.startsWith('blob:')) URL.revokeObjectURL(previa);
      return URL.createObjectURL(archivo);
    });
  }

  function alQuitarImagen() {
    setErrorImagen(null);
    setImagenArchivo(null);
    setQuitarImagen(true);
    setVistaPrevia((previa) => {
      if (previa?.startsWith('blob:')) URL.revokeObjectURL(previa);
      return null;
    });
    if (inputImagen.current) inputImagen.current.value = '';
  }

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    if (errorImagen) return;

    setGuardando(true);
    onOcupado(true);
    setError(null);

    const cuerpo = {
      ...Object.fromEntries(CAMPOS.map((campo) => [campo, valores[campo].trim() === '' ? null : valores[campo].trim()])),
      confirmacion_publica: confirmada,
      _revision: datos.revision,
    };
    const resultado = await ejecutar<Respuesta>(`academico/cursos/${curso.id}/publicacion`, 'PUT', cuerpo);

    if (!resultado.ok) {
      setGuardando(false);
      onOcupado(false);
      setError(resultado.error);
      if (resultado.estado === 412) onExpirada();
      return;
    }

    let datosFinales = resultado.datos.data;
    const mensajeBase = resultado.datos.message ?? 'La ficha pública fue autorizada.';

    if (imagenArchivo) {
      const formulario = new FormData();
      formulario.append('imagen', imagenArchivo);
      formulario.append('_revision', datosFinales.revision);
      const subido = await subir<Respuesta>(`academico/cursos/${curso.id}/publicacion/imagen`, formulario);
      if (!subido.ok) {
        setGuardando(false);
        onOcupado(false);
        setError(subido.error);
        if (subido.estado === 412) onExpirada();
        return;
      }
      datosFinales = subido.datos.data;
    } else if (quitarImagen && ficha?.imagen_url) {
      const eliminado = await ejecutar<Respuesta>(`academico/cursos/${curso.id}/publicacion/imagen/eliminar`, 'POST', {
        _revision: datosFinales.revision,
      });
      if (!eliminado.ok) {
        setGuardando(false);
        onOcupado(false);
        setError(eliminado.error);
        if (eliminado.estado === 412) onExpirada();
        return;
      }
      datosFinales = eliminado.datos.data;
    }

    setGuardando(false);
    onOcupado(false);
    onGuardado(datosFinales, mensajeBase);
  }

  const err = (campo: string) => errorDeCampo(error, campo);

  return (
    <form onSubmit={enviar} noValidate className="space-y-4">
      {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
      {error?.code === 'validacion' ? <Alerta tipo="error">{err('revision') ?? 'Revisa los campos marcados.'}</Alerta> : null}

      {aviso === 'programada' || aviso === 'vencida' ? (
        <Alerta tipo="aviso">Las fechas guardadas están {aviso === 'vencida' ? 'vencidas' : 'programadas para el futuro'}. Revísalas antes de publicar.</Alerta>
      ) : null}
      {ficha === null ? <p className="text-sm text-panel-muted">Para una publicación nueva se sugieren hoy y {VIGENCIA_SUGERIDA_DIAS} días de vigencia. Puedes ajustar las fechas.</p> : null}

      <div className="grid gap-4 sm:grid-cols-2">
        <Campo etiqueta="Título público" value={valores.titulo} onChange={cambiar('titulo')} error={err('titulo')} required maxLength={120} className="sm:col-span-2" />
        <CampoArea etiqueta="Resumen" value={valores.resumen} onChange={cambiar('resumen')} error={err('resumen')} required rows={3} maxLength={500} className="sm:col-span-2" />
        <CampoSeleccion etiqueta="Modalidad" value={valores.modalidad} onChange={cambiar('modalidad')} error={err('modalidad')} opciones={ETIQUETAS.modalidad} />
        <Campo etiqueta="Horario (opcional)" value={valores.horario} onChange={cambiar('horario')} error={err('horario')} maxLength={200} />
        <Campo etiqueta="Fecha de inicio (opcional)" type="date" value={valores.fecha_inicio} onChange={cambiar('fecha_inicio')} error={err('fecha_inicio')} />
        <Campo etiqueta="Duración (opcional)" value={valores.duracion} onChange={cambiar('duracion')} error={err('duracion')} maxLength={120} placeholder="Ej. 24 horas · 4 semanas" />
        <Campo etiqueta="Certificación (opcional)" value={valores.certificacion} onChange={cambiar('certificacion')} error={err('certificacion')} maxLength={200} placeholder="Ej. Certificado físico y digital" className="sm:col-span-2" />
        <CampoArea etiqueta="Temario / lo que aprenderás (opcional)" value={valores.temario} onChange={cambiar('temario')} error={err('temario')} rows={4} maxLength={1500} placeholder="Escribe un tema o requisito por línea" className="sm:col-span-2" />
        <Campo etiqueta="Cómo inscribirse (opcional)" value={valores.inscripcion} onChange={cambiar('inscripcion')} error={err('inscripcion')} maxLength={400} className="sm:col-span-2" />
        <Campo etiqueta="Vigente desde" type="date" value={valores.vigente_desde} onChange={cambiar('vigente_desde')} error={err('vigente_desde')} required />
        <Campo etiqueta="Vigente hasta" type="date" value={valores.vigente_hasta} onChange={cambiar('vigente_hasta')} error={err('vigente_hasta')} required />
      </div>

      <div className="rounded-lg border border-panel-border bg-stone-50/60 p-3.5">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="text-sm font-medium text-panel-text">Imagen referencial (opcional)</p>
            <p className="text-xs text-panel-muted">JPG, PNG o WebP de hasta 2 MB. Se muestra en la tarjeta pública del curso.</p>
          </div>
          <div className="flex items-center gap-2">
            <input
              ref={inputImagen}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="sr-only"
              aria-label="Seleccionar imagen referencial del curso"
              onChange={alElegirImagen}
            />
            <Boton
              variante="secundario"
              type="button"
              icono={<ImagePlus className="h-4 w-4" aria-hidden="true" />}
              onClick={() => inputImagen.current?.click()}
              disabled={guardando}
            >
              {vistaPrevia ? 'Cambiar imagen' : 'Subir imagen'}
            </Boton>
            {vistaPrevia ? (
              <Boton
                variante="fantasma"
                type="button"
                icono={<Trash2 className="h-4 w-4" aria-hidden="true" />}
                onClick={alQuitarImagen}
                disabled={guardando}
              >
                Quitar
              </Boton>
            ) : null}
          </div>
        </div>
        {vistaPrevia ? (
          <div className="mt-3 overflow-hidden rounded-lg border border-panel-border bg-white">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={vistaPrevia} alt="Vista previa de la imagen referencial" className="h-40 w-full object-cover" />
          </div>
        ) : null}
        {errorImagen || err('imagen') ? (
          <p className="mt-2 text-sm text-estado-error">{errorImagen ?? err('imagen')}</p>
        ) : null}
      </div>

      <div>
        <label className="flex items-start gap-2 text-sm text-panel-text">
          <input type="checkbox" checked={confirmada} onChange={(evento) => {
              setConfirmada(evento.target.checked);
              setError((previo) => (previo?.errors?.confirmacion_publica ? { ...previo, errors: { ...previo.errors, confirmacion_publica: [] } } : previo));
            }} className="mt-0.5 h-4 w-4 rounded border-panel-border accent-academico-700" />
          Confirmo que este contenido puede mostrarse públicamente en el sitio web.
        </label>
        {err('confirmacion_publica') ? <p className="mt-1.5 text-sm text-estado-error">{err('confirmacion_publica')}</p> : null}
      </div>

      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton variante="secundario" onClick={onCancelar} disabled={guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={guardando}>Autorizar y publicar</Boton>
      </div>
    </form>
  );
}
