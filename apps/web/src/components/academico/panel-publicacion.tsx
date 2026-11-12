'use client';

import { Globe, GlobeLock } from 'lucide-react';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
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
import { ETIQUETAS, fecha } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

type Respuesta = { message?: string; data: PublicacionCurso };

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

      {datos && ficha === null ? <p className="py-6 text-center text-sm text-panel-muted">Este curso todavía no tiene ficha pública.</p> : null}

      {ficha ? (
        <>
          <div className="mb-3">
            <Insignia color={ficha.publicada ? 'verde' : 'gris'}>{ficha.publicada ? 'Autorizada' : 'Retirada'}</Insignia>
          </div>
          <ListaDatos
            filas={[
              ['Título', ficha.titulo],
              ['Resumen', ficha.resumen],
              ['Modalidad', ETIQUETAS.modalidad[ficha.modalidad as keyof typeof ETIQUETAS.modalidad] ?? ficha.modalidad],
              ['Horario', ficha.horario ?? '—'],
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

const CAMPOS = ['titulo', 'resumen', 'modalidad', 'horario', 'inscripcion', 'vigente_desde', 'vigente_hasta'] as const;
type DatosFicha = Record<(typeof CAMPOS)[number], string>;

function FormularioFicha({ curso, datos, onGuardado, onCancelar, onOcupado, onExpirada }: {
  curso: CursoDetalle;
  datos: PublicacionCurso;
  onGuardado: (datos: PublicacionCurso, mensaje: string) => void;
  onCancelar: () => void;
  onOcupado: (ocupado: boolean) => void;
  onExpirada: () => void;
}) {
  const { ejecutar } = useOperacionPanel();
  const ficha = datos.ficha;
  const [valores, setValores] = useState<DatosFicha>({
    titulo: ficha?.titulo ?? curso.nombre,
    resumen: ficha?.resumen ?? '',
    modalidad: ficha?.modalidad ?? curso.modalidad,
    horario: ficha?.horario ?? curso.horario ?? '',
    inscripcion: ficha?.inscripcion ?? '',
    vigente_desde: ficha?.vigente_desde ?? '',
    vigente_hasta: ficha?.vigente_hasta ?? '',
  });
  const [confirmada, setConfirmada] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);

  const cambiar = (campo: keyof DatosFicha) => (evento: { target: { value: string } }) =>
    setValores((actuales) => ({ ...actuales, [campo]: evento.target.value }));

  async function enviar(evento: FormEvent<HTMLFormElement>) {
    evento.preventDefault();
    setGuardando(true);
    onOcupado(true);
    setError(null);

    const cuerpo = {
      ...Object.fromEntries(CAMPOS.map((campo) => [campo, valores[campo].trim() === '' ? null : valores[campo].trim()])),
      confirmacion_publica: confirmada,
      _revision: datos.revision,
    };
    const resultado = await ejecutar<Respuesta>(`academico/cursos/${curso.id}/publicacion`, 'PUT', cuerpo);

    setGuardando(false);
    onOcupado(false);
    if (resultado.ok) {
      onGuardado(resultado.datos.data, resultado.datos.message ?? 'La ficha pública fue autorizada.');
      return;
    }
    setError(resultado.error);
    if (resultado.estado === 412) onExpirada();
  }

  const err = (campo: string) => errorDeCampo(error, campo);

  return (
    <form onSubmit={enviar} noValidate className="space-y-4">
      {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
      {error?.code === 'validacion' ? <Alerta tipo="error">{err('revision') ?? 'Revisa los campos marcados.'}</Alerta> : null}

      <div className="grid gap-4 sm:grid-cols-2">
        <Campo etiqueta="Título público" value={valores.titulo} onChange={cambiar('titulo')} error={err('titulo')} required maxLength={120} className="sm:col-span-2" />
        <CampoArea etiqueta="Resumen" value={valores.resumen} onChange={cambiar('resumen')} error={err('resumen')} required rows={3} maxLength={500} className="sm:col-span-2" />
        <CampoSeleccion etiqueta="Modalidad" value={valores.modalidad} onChange={cambiar('modalidad')} error={err('modalidad')} opciones={ETIQUETAS.modalidad} />
        <Campo etiqueta="Horario (opcional)" value={valores.horario} onChange={cambiar('horario')} error={err('horario')} maxLength={200} />
        <Campo etiqueta="Cómo inscribirse (opcional)" value={valores.inscripcion} onChange={cambiar('inscripcion')} error={err('inscripcion')} maxLength={400} className="sm:col-span-2" />
        <Campo etiqueta="Vigente desde" type="date" value={valores.vigente_desde} onChange={cambiar('vigente_desde')} error={err('vigente_desde')} required />
        <Campo etiqueta="Vigente hasta" type="date" value={valores.vigente_hasta} onChange={cambiar('vigente_hasta')} error={err('vigente_hasta')} required />
      </div>

      <div>
        <label className="flex items-start gap-2 text-sm text-panel-text">
          <input type="checkbox" checked={confirmada} onChange={(evento) => setConfirmada(evento.target.checked)} className="mt-0.5 h-4 w-4 rounded border-panel-border accent-academico-700" />
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
