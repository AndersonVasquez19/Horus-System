'use client';

import { useState, type FormEvent } from 'react';
import { useRouter } from 'next/navigation';
import { Award, Ban, FileDown, Mail } from 'lucide-react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { CampoArea } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import { BotonAccionFila, BotonCopiarDato } from '@/components/shared/ui/acciones-celda';

/** Acciones directas por fila de certificado (sin menú de 3 puntos): copiar código, descargar PDF y anular. */
export function AccionesFilaCertificado({
  identificador,
  codigo,
  anulado,
}: {
  identificador: string;
  codigo: string;
  anulado: boolean;
}) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar, descargar } = useOperacionPanel();
  const puedeAnular = usePuede('certificados.anular') && !anulado;
  const puedeEnviar = usePuede('certificados.emitir') && !anulado;

  const [abiertoAnular, setAbiertoAnular] = useState(false);
  const [motivo, setMotivo] = useState('');
  const [enviando, setEnviando] = useState(false);
  const [descargando, setDescargando] = useState(false);
  const [enviandoCorreo, setEnviandoCorreo] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function bajarPdf() {
    setDescargando(true);
    const r = await descargar(`academico/certificados/${encodeURIComponent(identificador)}/pdf`, 'GET');
    setDescargando(false);
    if (!r.ok) mostrar('error', r.error.message);
  }

  async function enviarCorreo() {
    setEnviandoCorreo(true);
    const r = await ejecutar<{ message?: string }>(`academico/certificados/${encodeURIComponent(identificador)}/enviar-correo`, 'POST');
    setEnviandoCorreo(false);
    mostrar(r.ok ? 'exito' : 'error', r.ok ? (r.datos?.message ?? 'El certificado se enviará por correo.') : r.error.message);
  }

  async function anular(e: FormEvent) {
    e.preventDefault();
    if (motivo.trim().length < 5) {
      setError('Indica un motivo de al menos 5 caracteres.');
      return;
    }
    setEnviando(true);
    setError(null);
    const r = await ejecutar<{ message?: string }>(`academico/certificados/${encodeURIComponent(identificador)}/anular`, 'POST', {
      motivo: motivo.trim(),
    });
    setEnviando(false);
    if (!r.ok) {
      setError(r.error.message);
      return;
    }
    setAbiertoAnular(false);
    setMotivo('');
    mostrar('exito', r.datos?.message || `Certificado ${codigo} anulado.`);
    router.refresh();
  }

  return (
    <div className="flex items-center justify-end gap-1">
      <BotonCopiarDato valor={codigo} etiqueta={`Copiar código ${codigo}`} />
      <BotonAccionFila
        icono={<FileDown className="h-3.5 w-3.5" aria-hidden="true" />}
        etiqueta={`Descargar PDF ${codigo}`}
        soloIcono
        disabled={descargando}
        onClick={bajarPdf}
      />
      {puedeEnviar ? (
        <BotonAccionFila
          icono={<Mail className="h-3.5 w-3.5" aria-hidden="true" />}
          etiqueta={`Enviar por correo ${codigo}`}
          soloIcono
          disabled={enviandoCorreo}
          onClick={enviarCorreo}
        />
      ) : null}
      {puedeAnular ? (
        <BotonAccionFila
          icono={<Ban className="h-3.5 w-3.5" aria-hidden="true" />}
          etiqueta={`Anular ${codigo}`}
          soloIcono
          tono="peligro"
          onClick={() => {
            setError(null);
            setMotivo('');
            setAbiertoAnular(true);
          }}
        />
      ) : null}

      <Dialogo
        abierto={abiertoAnular}
        onCerrar={() => setAbiertoAnular(false)}
        titulo={`Anular certificado ${codigo}`}
        descripcion="La anulación queda registrada en auditoría y el código pasará a figurar como inválido en la verificación pública."
      >
        <form onSubmit={anular} className="space-y-4">
          <CampoArea
            etiqueta="Motivo de anulación"
            rows={3}
            maxLength={300}
            value={motivo}
            onChange={(e) => setMotivo(e.target.value)}
            placeholder="Ejemplo: Error en el nombre del estudiante o corrección de nota."
            error={error ?? undefined}
          />
          <div className="flex justify-end gap-2">
            <Boton variante="secundario" type="button" onClick={() => setAbiertoAnular(false)} disabled={enviando}>
              Cancelar
            </Boton>
            <Boton variante="peligro" type="submit" cargando={enviando}>
              Anular certificado
            </Boton>
          </div>
        </form>
      </Dialogo>
    </div>
  );
}

/** Botón para emitir en lote todos los certificados elegibles de una edición. */
export function BotonEmitirLoteCertificados({
  convocatoriaId,
  codigoEdicion,
}: {
  convocatoriaId: string;
  codigoEdicion: string;
}) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puedeEmitir = usePuede('certificados.emitir');
  const [ocupado, setOcupado] = useState(false);

  if (!puedeEmitir) return null;

  async function emitirLote() {
    setOcupado(true);
    const r = await ejecutar<{ message?: string; data?: { emitidos: number; ya_emitidos: number; omitidos: number; omitidos_por_deuda?: number } }>(
      `academico/convocatorias/${encodeURIComponent(convocatoriaId)}/certificados/emitir-lote`,
      'POST',
    );
    setOcupado(false);
    if (!r.ok) {
      mostrar('error', r.error.message);
      return;
    }
    const d = r.datos?.data;
    const detalle = d
      ? `Emitidos: ${d.emitidos} · Ya emitidos: ${d.ya_emitidos} · Omitidos por requisitos: ${d.omitidos}${d.omitidos_por_deuda ? ` (${d.omitidos_por_deuda} por deuda)` : ''}.`
      : r.datos?.message || `Lote procesado para ${codigoEdicion}.`;
    mostrar(d && d.emitidos > 0 ? 'exito' : 'aviso', detalle);
    router.refresh();
  }

  return (
    <Boton
      variante="primario"
      icono={<Award className="h-4 w-4" aria-hidden="true" />}
      onClick={emitirLote}
      cargando={ocupado}
    >
      Emitir certificados elegibles
    </Boton>
  );
}

/** Botón directo para emitir un certificado individual desde la ficha de una matrícula. */
export function BotonEmitirCertificadoMatricula({ matriculaId }: { matriculaId: number }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puedeEmitir = usePuede('certificados.emitir');
  const [ocupado, setOcupado] = useState(false);
  const [bloqueos, setBloqueos] = useState<{ codigo: string; mensaje: string }[] | null>(null);
  const [puedeExcepcion, setPuedeExcepcion] = useState(false);
  const [motivo, setMotivo] = useState('');
  const [error, setError] = useState<string | null>(null);

  if (!puedeEmitir) return null;

  async function emitir(motivoExcepcion?: string) {
    setOcupado(true);
    setError(null);
    const r = await ejecutar<{ message?: string }>(`academico/matriculas/${matriculaId}/certificado`, 'POST', motivoExcepcion ? { motivo_excepcion: motivoExcepcion } : {});
    setOcupado(false);
    if (!r.ok) {
      if (bloqueos) setError(r.error.message);
      else mostrar('error', r.error.message);
      return;
    }
    setBloqueos(null);
    mostrar('exito', r.datos?.message || 'Certificado emitido correctamente.');
    router.refresh();
  }

  // Antes de emitir se consultan los requisitos: si hay bloqueos (deuda o edición sin cerrar) se advierten; solo admin puede seguir con motivo.
  async function revisar() {
    setOcupado(true);
    const r = await ejecutar<{ data: { elegible: boolean; bloqueos: { codigo: string; mensaje: string }[]; puede_excepcion: boolean } }>(`academico/matriculas/${matriculaId}/certificado/elegibilidad`, 'GET');
    setOcupado(false);
    if (!r.ok) {
      mostrar('error', r.error.message);
      return;
    }
    if (r.datos.data.bloqueos.length === 0) {
      await emitir();
      return;
    }
    setMotivo('');
    setError(null);
    setPuedeExcepcion(r.datos.data.puede_excepcion);
    setBloqueos(r.datos.data.bloqueos);
  }

  return (
    <>
      <Boton variante="secundario" icono={<Award className="h-4 w-4" aria-hidden="true" />} onClick={() => void revisar()} cargando={ocupado && bloqueos === null}>
        Emitir certificado
      </Boton>
      <Dialogo
        abierto={bloqueos !== null}
        onCerrar={() => setBloqueos(null)}
        titulo="Esta matrícula no cumple los requisitos"
        descripcion={puedeExcepcion ? 'Puedes emitir igualmente como administrador indicando un motivo; quedará registrado.' : 'Solo un administrador puede emitir con una excepción.'}
        bloqueado={ocupado}
      >
        <div className="space-y-4">
          <ul className="list-disc space-y-1 pl-5 text-sm text-panel-text">
            {(bloqueos ?? []).map((b) => (
              <li key={b.codigo}>{b.mensaje}</li>
            ))}
          </ul>
          {puedeExcepcion ? (
            <>
              <CampoArea etiqueta="Motivo de la excepción" rows={3} maxLength={300} value={motivo} onChange={(e) => setMotivo(e.target.value)} error={error ?? undefined} />
              <div className="flex justify-end gap-2">
                <Boton variante="secundario" type="button" onClick={() => setBloqueos(null)} disabled={ocupado}>Cancelar</Boton>
                <Boton
                  variante="peligro"
                  type="button"
                  cargando={ocupado}
                  onClick={() => (motivo.trim().length < 5 ? setError('Indica un motivo de al menos 5 caracteres.') : void emitir(motivo.trim()))}
                >
                  Emitir con excepción
                </Boton>
              </div>
            </>
          ) : (
            <div className="flex justify-end">
              <Boton variante="secundario" type="button" onClick={() => setBloqueos(null)}>Entendido</Boton>
            </div>
          )}
        </div>
      </Dialogo>
    </>
  );
}
