'use client';

import { FileSpreadsheet } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import { Insignia, type ColorInsignia } from '@/components/shared/ui/insignia';
import { Tabla } from '@/components/shared/ui/tabla';
import type { MatriculaMasivaAnalisis, MatriculaMasivaResultado } from '@/features/academico/tipos';

const MAX_BYTES = 1024 * 1024;
const ACCIONES: Record<string, { texto: string; color: ColorInsignia }> = {
  matricular: { texto: 'Matricular', color: 'verde' },
  crear_y_matricular: { texto: 'Crear y matricular', color: 'azul' },
  omitir: { texto: 'Omitir', color: 'gris' },
  sin_cupo: { texto: 'Sin cupo', color: 'ambar' },
  error: { texto: 'Error', color: 'rojo' },
  matriculada: { texto: 'Matriculada', color: 'verde' },
};

/** Botón «Matrícula masiva»: planilla Excel de un convenio → vista previa → confirmación. Exige `matriculas.crear`. */
export function BotonMatriculaMasiva({ convocatoria, disponible }: { convocatoria: string; disponible: boolean }) {
  const puede = usePuede('matriculas.crear');
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  if (!puede || !disponible) return null;

  return (
    <>
      <Boton variante="secundario" icono={<FileSpreadsheet className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>Matrícula masiva</Boton>
      <Dialogo abierto={abierto} titulo="Matrícula masiva por convenio" descripcion="Sube la planilla Excel, revisa la vista previa y confirma. Nada se guarda hasta confirmar." onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        {abierto ? <Formulario convocatoria={convocatoria} onOcupado={setOcupado} onCerrar={() => setAbierto(false)} /> : null}
      </Dialogo>
    </>
  );
}

function Formulario({ convocatoria, onOcupado, onCerrar }: { convocatoria: string; onOcupado: (b: boolean) => void; onCerrar: () => void }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { descargar, subir } = useOperacionPanel();
  const puedeObligacion = usePuede('pagos.planificar');
  const [archivo, setArchivo] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [analisis, setAnalisis] = useState<MatriculaMasivaAnalisis | null>(null);
  const [resultado, setResultado] = useState<MatriculaMasivaResultado | null>(null);
  const [obligacion, setObligacion] = useState(false);
  const [cargando, setCargando] = useState(false);

  function cambiarOcupado(valor: boolean) {
    setCargando(valor);
    onOcupado(valor);
  }

  function formulario(): FormData | null {
    if (archivo === null) {
      setError('Adjunta la planilla Excel (.xlsx).');
      return null;
    }
    if (archivo.size > MAX_BYTES) {
      setError('La planilla debe pesar hasta 1 MB.');
      return null;
    }
    const datos = new FormData();
    datos.append('archivo', archivo);
    return datos;
  }

  async function vistaPrevia() {
    setError(null);
    const datos = formulario();
    if (datos === null) return;
    cambiarOcupado(true);
    const r = await subir<{ data: MatriculaMasivaAnalisis }>(`academico/convocatorias/${convocatoria}/matricula-masiva/vista-previa`, datos);
    cambiarOcupado(false);
    if (!r.ok) {
      setAnalisis(null);
      setError(r.error.errors?.archivo?.[0] ?? r.error.message);
      return;
    }
    setAnalisis(r.datos.data);
  }

  async function confirmar() {
    setError(null);
    const datos = formulario();
    if (datos === null) return;
    if (obligacion) datos.append('crear_obligacion', '1');
    cambiarOcupado(true);
    const r = await subir<{ data: MatriculaMasivaResultado }>(`academico/convocatorias/${convocatoria}/matricula-masiva`, datos);
    cambiarOcupado(false);
    if (!r.ok) {
      setError(r.error.errors?.archivo?.[0] ?? r.error.message);
      return;
    }
    setResultado(r.datos.data);
    mostrar('exito', `${r.datos.data.resumen.matriculadas} matrícula(s) registrada(s).`);
    router.refresh();
  }

  const resumen = resultado?.resumen ?? null;
  const filas: { fila: number; dni: string; nombre: string; accion: string; motivo: string | null }[] = resultado?.filas ?? analisis?.filas ?? [];
  const confirmables = analisis ? analisis.resumen.matricular + analisis.resumen.crear_y_matricular : 0;

  return (
    <div className="space-y-4">
      {error ? <Alerta tipo="error">{error}</Alerta> : null}
      {resumen ? (
        <Alerta tipo="exito">
          {resumen.matriculadas} matriculada(s), {resumen.estudiantes_creados} estudiante(s) nuevo(s), {resumen.omitir} omitida(s), {resumen.sin_cupo} sin cupo, {resumen.error} con error.
        </Alerta>
      ) : (
        <>
          <Campo
            etiqueta="Planilla Excel (.xlsx)"
            type="file"
            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            onChange={(e) => {
              setArchivo(e.target.files?.[0] ?? null);
              setAnalisis(null);
            }}
            ayuda="Columnas: dni (obligatoria), nombres, apellidos, telefono, email. Hasta 200 filas y 1 MB. Un DNI nuevo necesita nombres y apellidos."
          />
          <div className="flex flex-wrap items-center gap-2">
            <Boton
              variante="fantasma"
              onClick={async () => {
                const r = await descargar('academico/matriculas/masiva/plantilla', 'GET');
                mostrar(r.ok ? 'exito' : 'error', r.ok ? 'Planilla modelo descargada.' : r.error.message);
              }}
            >
              Descargar planilla modelo
            </Boton>
            <Boton cargando={cargando && analisis === null} onClick={() => void vistaPrevia()}>Vista previa</Boton>
          </div>
        </>
      )}

      {analisis && !resultado ? (
        <p className="text-sm text-panel-muted">
          {analisis.cupos_disponibles} cupo(s) libre(s) · {confirmables} fila(s) listas para matricular · {analisis.resumen.omitir} ya matriculada(s) · {analisis.resumen.sin_cupo} sin cupo · {analisis.resumen.error} con error.
          {analisis.puede_crear_estudiantes ? '' : ' No tienes permiso para crear estudiantes nuevos.'}
        </p>
      ) : null}

      {filas.length > 0 ? (
        <Tabla plana titulo="Resultado fila por fila" columnas={['Fila', 'DNI', 'Estudiante', 'Acción', 'Detalle']}>
          {filas.map((f) => {
            const a = ACCIONES[f.accion] ?? { texto: f.accion, color: 'gris' as ColorInsignia };
            return (
              <tr key={f.fila}>
                <td className="px-4 py-2 font-mono text-xs">{f.fila}</td>
                <td className="px-4 py-2 font-mono text-xs">{f.dni || '—'}</td>
                <td className="px-4 py-2 text-sm">{f.nombre || '—'}</td>
                <td className="px-4 py-2"><Insignia color={a.color}>{a.texto}</Insignia></td>
                <td className="px-4 py-2 text-xs text-panel-muted">{f.motivo ?? ''}</td>
              </tr>
            );
          })}
        </Tabla>
      ) : null}

      {analisis && !resultado ? (
        <div className="space-y-3">
          {puedeObligacion ? (
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={obligacion} onChange={(e) => setObligacion(e.target.checked)} />
              Crear también la cuenta por cobrar de cada matrícula (precio del curso)
            </label>
          ) : null}
          <div className="pie-acciones">
            <Boton variante="secundario" onClick={onCerrar} disabled={cargando}>Cancelar</Boton>
            <Boton cargando={cargando} disabled={confirmables === 0} onClick={() => void confirmar()}>Confirmar {confirmables} matrícula(s)</Boton>
          </div>
        </div>
      ) : null}
      {resultado ? (
        <div className="pie-acciones">
          <Boton onClick={onCerrar}>Cerrar</Boton>
        </div>
      ) : null}
    </div>
  );
}
