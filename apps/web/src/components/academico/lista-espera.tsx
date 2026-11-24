'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useCallback, useState } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Campo } from '@/components/shared/ui/campo';
import { SelectorRemoto, type OpcionRemota } from '@/components/shared/ui/selector-remoto';
import { Tabla } from '@/components/shared/ui/tabla';
import { BotonesContacto } from '@/components/shared/ui/acciones-contacto';
import type { ListaEsperaVista } from '@/features/academico/tipos';

/**
 * Lista de espera de la edición: quién espera y en qué orden. Solo se puede anotar a alguien si la edición está llena; cuando se libera un
 * cupo se matricula por el flujo normal (la lista no reserva cupos). Agregar y quitar exigen `matriculas.crear`.
 */
export function PanelListaEspera({ convocatoria, datos }: { convocatoria: string; datos: ListaEsperaVista }) {
  const puede = usePuede('matriculas.crear');
  const router = useRouter();
  const { ejecutar } = useOperacionPanel();
  const { mostrar } = useAvisos();
  const [estudiante, setEstudiante] = useState<OpcionRemota | null>(null);
  const [nota, setNota] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [ocupado, setOcupado] = useState(false);

  const buscar = useCallback(
    async (texto: string): Promise<OpcionRemota[] | null> => {
      const parametros = new URLSearchParams({ contexto: 'crear' });
      if (texto) parametros.set('q', texto);
      const r = await ejecutar<{ data: OpcionRemota[] }>(`academico/matriculas/opciones/estudiantes?${parametros}`);
      return r.ok ? r.datos.data : null;
    },
    [ejecutar],
  );

  async function agregar() {
    if (estudiante === null) {
      setError('Selecciona el estudiante.');
      return;
    }
    setOcupado(true);
    setError(null);
    const r = await ejecutar(`academico/convocatorias/${convocatoria}/lista-espera`, 'POST', { estudiante_id: estudiante.id, ...(nota.trim() ? { nota: nota.trim() } : {}) });
    setOcupado(false);
    if (!r.ok) {
      setError(r.error.message);
      return;
    }
    setEstudiante(null);
    setNota('');
    mostrar('exito', 'Estudiante agregado a la lista de espera.');
    router.refresh();
  }

  async function quitar(identificador: string) {
    setOcupado(true);
    const r = await ejecutar(`academico/convocatorias/${convocatoria}/lista-espera/${identificador}`, 'DELETE');
    setOcupado(false);
    if (!r.ok) {
      mostrar('error', r.error.message);
      return;
    }
    mostrar('exito', 'La persona salió de la lista de espera.');
    router.refresh();
  }

  return (
    <div className="space-y-4">
      {datos.hay_cupo && datos.total > 0 ? (
        <Alerta tipo="aviso">
          Hay {datos.cupos_disponibles} cupo(s) libre(s) y {datos.total} persona(s) esperando. Matricula primero a quien está en la posición 1.
        </Alerta>
      ) : null}
      {datos.total === 0 ? (
        <p className="text-sm text-panel-muted">Nadie espera cupo en esta edición.</p>
      ) : (
        <Tabla plana titulo="Lista de espera" columnas={['#', 'Estudiante', 'Nota', { texto: 'Acciones', derecha: true }]}>
          {datos.items.map((e) => (
            <tr key={e.identificador}>
              <td className="px-4 py-3 font-mono text-sm">{e.posicion}</td>
              <td className="px-4 py-3">
                {e.estudiante ? (
                  <>
                    <Link href={`/admin/academico/estudiantes/${e.estudiante.id}`} className="font-semibold text-academico-700 hover:underline">{e.estudiante.nombre}</Link>
                    <div className="text-xs text-panel-muted">DNI <span className="font-mono">{e.estudiante.dni}</span></div>
                  </>
                ) : '—'}
              </td>
              <td className="px-4 py-3 text-sm text-panel-muted">{e.nota ?? '—'}</td>
              <td className="px-4 py-3">
                <div className="flex items-center justify-end gap-2">
                  {e.estudiante ? <BotonesContacto compacto telefono={e.estudiante.telefono} email={null} /> : null}
                  {puede ? <Boton variante="secundario" disabled={ocupado} onClick={() => void quitar(e.identificador)}>Quitar</Boton> : null}
                </div>
              </td>
            </tr>
          ))}
        </Tabla>
      )}

      {puede ? (
        datos.hay_cupo || !datos.admite_matriculas ? (
          <p className="text-sm text-panel-muted">
            {datos.admite_matriculas ? 'La edición tiene cupos: matricula directamente.' : 'La edición no admite matrículas, así que no recibe lista de espera.'}
          </p>
        ) : (
          <div className="space-y-3 rounded-xl border border-panel-border p-4">
            <h3 className="text-sm font-semibold">Agregar a la lista de espera</h3>
            {error ? <Alerta tipo="error">{error}</Alerta> : null}
            <SelectorRemoto etiqueta="Estudiante" valor={estudiante} onCambiar={setEstudiante} buscar={buscar} marcador="Busca por DNI, nombres o apellidos" />
            <Campo etiqueta="Nota (opcional)" maxLength={200} value={nota} onChange={(e) => setNota(e.target.value)} ayuda="Por ejemplo, horario que prefiere." />
            <Boton cargando={ocupado} onClick={() => void agregar()}>Agregar</Boton>
          </div>
        )
      ) : null}
    </div>
  );
}
