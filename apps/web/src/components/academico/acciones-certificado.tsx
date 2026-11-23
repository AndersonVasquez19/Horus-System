'use client';

import { useState, type FormEvent } from 'react';
import { useRouter } from 'next/navigation';
import { Award, Ban, FileDown } from 'lucide-react';
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

  const [abiertoAnular, setAbiertoAnular] = useState(false);
  const [motivo, setMotivo] = useState('');
  const [enviando, setEnviando] = useState(false);
  const [descargando, setDescargando] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function bajarPdf() {
    setDescargando(true);
    const r = await descargar(`academico/certificados/${encodeURIComponent(identificador)}/pdf`, 'GET');
    setDescargando(false);
    if (!r.ok) mostrar('error', r.error.message);
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
        disabled={descargando}
        onClick={bajarPdf}
      />
      {puedeAnular ? (
        <BotonAccionFila
          icono={<Ban className="h-3.5 w-3.5" aria-hidden="true" />}
          etiqueta={`Anular ${codigo}`}
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
    const r = await ejecutar<{ message?: string; data?: { emitidos: number; ya_emitidos: number; omitidos: number } }>(
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
      ? `Emitidos: ${d.emitidos} · Ya emitidos: ${d.ya_emitidos} · Omitidos por requisitos: ${d.omitidos}.`
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

  if (!puedeEmitir) return null;

  async function emitir() {
    setOcupado(true);
    const r = await ejecutar<{ message?: string }>(`academico/matriculas/${matriculaId}/certificado`, 'POST', {});
    setOcupado(false);
    if (!r.ok) {
      mostrar('error', r.error.message);
      return;
    }
    mostrar('exito', r.datos?.message || 'Certificado emitido correctamente.');
    router.refresh();
  }

  return (
    <Boton
      variante="secundario"
      icono={<Award className="h-4 w-4" aria-hidden="true" />}
      onClick={emitir}
      cargando={ocupado}
    >
      Emitir certificado
    </Boton>
  );
}
