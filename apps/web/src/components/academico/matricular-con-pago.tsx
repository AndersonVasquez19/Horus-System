'use client';

import { Copy, HandCoins } from 'lucide-react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useRef, useState } from 'react';
import { z } from 'zod';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useFormularioPanel } from '@/components/shared/use-formulario-panel';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import { SelectorRemoto, type OpcionRemota } from '@/components/shared/ui/selector-remoto';
import { IMPORTE, MAX_CAPTURA } from '@/lib/panel/captura';
import { hoyLima } from '@/lib/panel/formato';
import { copiarAlPortapapeles, mensajeBienvenidaMatricula } from '@/lib/panel/whatsapp-plantillas';

const esquema = z.object({
  monto: z.string().refine((v) => v === '' || (IMPORTE.test(v) && Number(v) > 0), 'Importe válido (mayor que cero, hasta 2 decimales) o déjalo vacío para usar el precio del curso.'),
  fecha_pago: z.string().min(1, 'Indica la fecha del pago.'),
  referencia: z.string().max(80, 'Hasta 80 caracteres.'),
});

interface Props {
  /** Identificador (UUID) de la edición en la que se matricula. */
  convocatoria: string;
  /** Solo se ofrece si la edición admite matrículas (abierta o en curso). */
  disponible: boolean;
  /** Texto de apoyo con el precio del curso, p. ej. «S/ 250.00». */
  precio?: string;
}

function validarCaptura(archivo: File | null): string | null {
  if (archivo === null) return 'Adjunta la captura del comprobante de Yape.';
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(archivo.type)) return 'Sube una captura en JPG, PNG o WebP.';
  if (archivo.size > MAX_CAPTURA) return 'La captura no puede pasar de 3 MB.';
  return null;
}

/**
 * Caja del administrador: matricula al estudiante y registra su pago de Yape (con la captura del comprobante) en un solo paso.
 * Laravel crea todo en una transacción: si algo falla no queda nada. Solo se muestra a quien tiene los permisos de crear
 * matrículas y de planificar, registrar y confirmar pagos (hoy, solo el administrador).
 */
export function BotonMatricularConPago({ convocatoria, disponible, precio }: Props) {
  const puede = [usePuede('matriculas.crear'), usePuede('pagos.planificar'), usePuede('pagos.registrar'), usePuede('pagos.confirmar')].every(Boolean);
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  if (!puede || !disponible) return null;

  return (
    <>
      <Boton icono={<HandCoins className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>Matricular con pago</Boton>
      <Dialogo abierto={abierto} titulo="Matricular con pago" descripcion="Se registra la matrícula, el pago de Yape y su comprobante en un solo paso." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        {abierto ? <Formulario convocatoria={convocatoria} precio={precio} onOcupado={setOcupado} onCerrar={() => setAbierto(false)} /> : null}
      </Dialogo>
    </>
  );
}

function Formulario({ convocatoria, precio, onOcupado, onCerrar }: { convocatoria: string; precio?: string; onOcupado: (b: boolean) => void; onCerrar: () => void }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar, subir } = useOperacionPanel();
  const [estudiante, setEstudiante] = useState<OpcionRemota | null>(null);
  const [errorEstudiante, setErrorEstudiante] = useState<string | undefined>();
  const [captura, setCaptura] = useState<File | null>(null);
  const [errorCaptura, setErrorCaptura] = useState<string | undefined>();
  const [alertaReferencia, setAlertaReferencia] = useState<string | null>(null);
  const [creada, setCreada] = useState<{ obligacion: string } | null>(null);
  // Una intención = una clave: si la red falla y se reintenta, Laravel devuelve lo ya creado sin duplicar nada.
  const clave = useRef(crypto.randomUUID());

  const buscarEstudiantes = useCallback(
    async (texto: string): Promise<OpcionRemota[] | null> => {
      const parametros = new URLSearchParams({ contexto: 'crear' });
      if (texto) parametros.set('q', texto);
      const r = await ejecutar<{ data: OpcionRemota[] }>(`academico/matriculas/opciones/estudiantes?${parametros}`);
      return r.ok ? r.datos.data : null;
    },
    [ejecutar],
  );

  const f = useFormularioPanel({
    esquema,
    valores: { monto: '', fecha_pago: hoyLima(), referencia: '' },
    onOcupado,
    enviar: async (d) => {
      setErrorEstudiante(undefined);
      setErrorCaptura(undefined);
      const sinEstudiante = estudiante === null ? 'Selecciona el estudiante.' : undefined;
      const sinCaptura = validarCaptura(captura) ?? undefined;
      if (sinEstudiante || sinCaptura || estudiante === null || captura === null) {
        setErrorEstudiante(sinEstudiante);
        setErrorCaptura(sinCaptura);
        return { ok: false as const, estado: 422, error: { message: 'Revisa los datos del formulario.', code: 'cliente' } };
      }
      const formulario = new FormData();
      formulario.append('estudiante_id', String(estudiante.id));
      formulario.append('convocatoria', convocatoria);
      if (d.monto !== '') formulario.append('monto', d.monto);
      formulario.append('fecha_pago', d.fecha_pago);
      if (d.referencia !== '') formulario.append('referencia', d.referencia);
      formulario.append('_clave', clave.current);
      formulario.append('imagen', captura);
      return subir<{ message: string; data: { obligacion: string } }>('academico/matriculas/con-pago', formulario);
    },
    onExito: (r) => {
      mostrar('exito', r.message);
      setCreada({ obligacion: r.data.obligacion });
      router.refresh();
    },
  });

  const refActual = f.form.watch('referencia');
  useEffect(() => {
    const limpio = (refActual ?? '').trim();
    if (limpio.length < 3) {
      setAlertaReferencia(null);
      return;
    }
    const timer = setTimeout(async () => {
      const r = await ejecutar<{ data: { duplicada: boolean; coincidencias: { matricula_id: number; fecha_pago: string }[] } }>(
        `academico/pagos/verificar-referencia?referencia=${encodeURIComponent(limpio)}`,
        'GET',
      );
      if (r.ok && r.datos.data.duplicada) {
        const primera = r.datos.data.coincidencias[0];
        setAlertaReferencia(
          primera
            ? `Advertencia: este código de operación ya se registró en la matrícula #${primera.matricula_id} (${primera.fecha_pago}).`
            : 'Advertencia: este código de operación ya fue registrado anteriormente.',
        );
      } else {
        setAlertaReferencia(null);
      }
    }, 350);
    return () => clearTimeout(timer);
  }, [ejecutar, refActual]);

  if (creada) {
    return (
      <div className="space-y-4">
        <Alerta tipo="exito">La matrícula quedó registrada, pagada y con su comprobante.</Alerta>
        <div className="flex flex-wrap items-center justify-end gap-2">
          <Boton
            variante="fantasma"
            icono={<Copy className="h-4 w-4" aria-hidden="true" />}
            onClick={async () => {
              const ok = await copiarAlPortapapeles(
                mensajeBienvenidaMatricula({
                  estudiante: estudiante?.texto ?? 'estudiante',
                  curso: 'tu curso en Horus Group',
                }),
              );
              mostrar(ok ? 'exito' : 'error', ok ? 'Mensaje de bienvenida copiado para WhatsApp.' : 'No se pudo copiar.');
            }}
          >
            Copiar bienvenida
          </Boton>
          <Link href={`/admin/academico/pagos/${creada.obligacion}`} className="text-sm font-semibold text-academico-700 hover:underline sm:self-center">Ver el pago</Link>
          <Boton onClick={onCerrar}>Cerrar</Boton>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      <SelectorRemoto
        etiqueta="Estudiante"
        valor={estudiante}
        onCambiar={setEstudiante}
        buscar={buscarEstudiantes}
        marcador="Busca por DNI, nombres o apellidos"
        error={errorEstudiante ?? f.error('estudiante_id' as never)}
      />
      <div className="grid gap-4 sm:grid-cols-2">
        <Campo etiqueta="Importe (S/)" inputMode="decimal" {...f.registro('monto')} error={f.error('monto')} ayuda={precio ? `Vacío = precio del curso (${precio}).` : 'Vacío = precio del curso.'} />
        <Campo etiqueta="Fecha del pago" type="date" {...f.registro('fecha_pago')} error={f.error('fecha_pago')} />
      </div>
      <Campo etiqueta="Código de operación Yape (opcional)" {...f.registro('referencia')} error={f.error('referencia')} />
      {alertaReferencia ? <Alerta tipo="aviso">{alertaReferencia}</Alerta> : null}
      <Campo
        etiqueta="Captura del comprobante de Yape"
        type="file"
        accept="image/jpeg,image/png,image/webp"
        onChange={(e) => setCaptura(e.target.files?.[0] ?? null)}
        error={errorCaptura}
        ayuda="JPG, PNG o WebP de hasta 3 MB. Es obligatoria."
      />
      <div className="pie-acciones">
        <Boton variante="secundario" onClick={onCerrar} disabled={f.guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={f.guardando}>Matricular y registrar pago</Boton>
      </div>
    </form>
  );
}
