'use client';

import { PhoneCall } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo, CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import type { SeguimientoCobranzaVista } from '@/features/academico/tipos';
import { IMPORTE, normalizarImporte } from '@/lib/panel/captura';
import { fecha, hoyLima, moneda } from '@/lib/panel/formato';

const CANALES = { llamada: 'Llamada', whatsapp: 'WhatsApp', correo: 'Correo', presencial: 'En persona', otro: 'Otro' } as const;
const RESULTADOS = { sin_respuesta: 'Sin respuesta', contactado: 'Contactado', promesa_pago: 'Promesa de pago', rechaza: 'Se niega a pagar' } as const;

/** Registra un contacto de cobro y consulta el historial de una obligación (`pagos.registrar` para registrar, `pagos.ver` para ver). */
export function AccionesFilaCobranza({ obligacion, estudiante }: { obligacion: string; estudiante: string }) {
  const puedeRegistrar = usePuede('pagos.registrar');
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [historial, setHistorial] = useState<SeguimientoCobranzaVista[] | null>(null);
  const [canal, setCanal] = useState<keyof typeof CANALES>('llamada');
  const [resultado, setResultado] = useState<keyof typeof RESULTADOS>('contactado');
  const [nota, setNota] = useState('');
  const [promesaFecha, setPromesaFecha] = useState('');
  const [promesaMonto, setPromesaMonto] = useState('');

  async function abrir() {
    setAbierto(true);
    setError(null);
    setHistorial(null);
    const r = await ejecutar<{ data: SeguimientoCobranzaVista[] }>(`academico/pagos/obligaciones/${obligacion}/seguimientos`, 'GET');
    if (r.ok) setHistorial(r.datos.data);
    else setError(r.error.message);
  }

  async function guardar() {
    setError(null);
    if (resultado === 'promesa_pago' && promesaFecha === '') {
      setError('Indica la fecha en que el estudiante promete pagar.');
      return;
    }
    if (promesaMonto !== '' && !IMPORTE.test(promesaMonto)) {
      setError('El monto prometido debe ser un importe válido (hasta 2 decimales).');
      return;
    }
    setOcupado(true);
    const esPromesa = resultado === 'promesa_pago';
    const r = await ejecutar(`academico/pagos/obligaciones/${obligacion}/seguimientos`, 'POST', {
      canal,
      resultado,
      nota: nota.trim() || null,
      promesa_fecha: esPromesa ? promesaFecha : null,
      promesa_monto: esPromesa && promesaMonto !== '' ? promesaMonto : null,
    });
    setOcupado(false);
    if (!r.ok) {
      setError(r.error.message);
      return;
    }
    mostrar('exito', 'Contacto registrado.');
    setAbierto(false);
    setNota('');
    setPromesaFecha('');
    setPromesaMonto('');
    router.refresh();
  }

  return (
    <>
      <Boton variante="secundario" icono={<PhoneCall className="h-4 w-4" aria-hidden="true" />} onClick={() => void abrir()}>
        Contacto
      </Boton>
      <Dialogo abierto={abierto} lado="derecha" titulo="Seguimiento de cobranza" descripcion={estudiante} onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        <div className="space-y-4">
          {error ? <Alerta tipo="error">{error}</Alerta> : null}
          {puedeRegistrar ? (
            <div className="space-y-3">
              <CampoSeleccion etiqueta="Canal" opciones={CANALES} value={canal} onChange={(e) => setCanal(e.target.value as keyof typeof CANALES)} />
              <CampoSeleccion etiqueta="Resultado" opciones={RESULTADOS} value={resultado} onChange={(e) => setResultado(e.target.value as keyof typeof RESULTADOS)} />
              {resultado === 'promesa_pago' ? (
                <>
                  <Campo etiqueta="Fecha prometida" type="date" min={hoyLima()} value={promesaFecha} onChange={(e) => setPromesaFecha(e.target.value)} />
                  <Campo etiqueta="Monto prometido (opcional)" inputMode="decimal" value={promesaMonto} onChange={(e) => setPromesaMonto(normalizarImporte(e.target.value))} />
                </>
              ) : null}
              <Campo etiqueta="Nota (opcional)" maxLength={300} value={nota} onChange={(e) => setNota(e.target.value)} />
              <Boton cargando={ocupado} onClick={() => void guardar()}>Registrar contacto</Boton>
            </div>
          ) : null}
          <div>
            <h3 className="mb-2 text-sm font-semibold text-panel-ink">Historial</h3>
            {historial === null ? (
              error ? null : <p className="text-sm text-panel-muted">Cargando…</p>
            ) : historial.length === 0 ? (
              <p className="text-sm text-panel-muted">Aún no hay contactos registrados.</p>
            ) : (
              <ul className="space-y-2">
                {historial.map((h) => (
                  <li key={h.identificador} className="rounded-lg border border-panel-border p-3 text-sm">
                    <div className="font-medium text-panel-ink">
                      {CANALES[h.canal as keyof typeof CANALES] ?? h.canal} · {RESULTADOS[h.resultado as keyof typeof RESULTADOS] ?? h.resultado}
                    </div>
                    {h.promesa_fecha ? (
                      <div className="text-panel-text">
                        Promete pagar el {fecha(h.promesa_fecha)}
                        {h.promesa_monto ? ` (${moneda(h.promesa_monto)})` : ''}
                      </div>
                    ) : null}
                    {h.nota ? <div className="text-panel-text">{h.nota}</div> : null}
                    <div className="text-xs text-panel-muted">
                      {h.creado ? fecha(h.creado) : ''}
                      {h.registrado_por ? ` · ${h.registrado_por}` : ''}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </Dialogo>
    </>
  );
}
