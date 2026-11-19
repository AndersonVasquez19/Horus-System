'use client';

import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useMemo, useState } from 'react';
import { z } from 'zod';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useFormularioPanel } from '@/components/shared/use-formulario-panel';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { Alerta } from '@/components/shared/ui/alerta';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { CampoSeleccion } from '@/components/shared/ui/campo';
import { Dialogo } from '@/components/shared/ui/dialogo';
import { Insignia } from '@/components/shared/ui/insignia';
import { ListaDatos } from '@/components/shared/ui/tabla';
import type { AulaResumen, ConvocatoriaDetalle, DocenteAsignado, DocenteResumen, PlataformaResumen, RolDocente, SedeResumen } from '@/features/academico/tipos';
import { errorDeCampo } from '@/lib/panel/cliente';
import { ETIQUETAS, etiqueta } from '@/lib/panel/formato';
import type { ErrorPanel } from '@/lib/panel/tipos';

// ---- Infraestructura de la edición -----------------------------------------------------------------------------------

interface PropsInfra {
  convocatoria: ConvocatoriaDetalle;
  sedes: readonly Pick<SedeResumen, 'identificador' | 'codigo' | 'nombre'>[];
  aulas: readonly AulaResumen[];
  plataformas: readonly PlataformaResumen[];
  /** La edición todavía admite cambios (no está cerrada ni cancelada). */
  editable: boolean;
}

const esquemaPresencial = z.object({
  sede: z.string().min(1, 'Selecciona la sede.'),
  aula: z.string().min(1, 'Selecciona el aula.'),
  plataforma_virtual: z.string(),
});
const esquemaVirtual = z.object({
  sede: z.string(),
  aula: z.string(),
  plataforma_virtual: z.string().min(1, 'Selecciona la plataforma virtual.'),
});

/** Sede y aula (presencial) o plataforma virtual (virtual) de una edición. Laravel valida capacidad, estado y coherencia. */
export function PanelInfraestructuraEdicion({ convocatoria: c, sedes, aulas, plataformas, editable }: PropsInfra) {
  const puede = usePuede('convocatorias.editar');
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const infra = c.infraestructura;
  const asignable = c.modalidad === 'presencial' || c.modalidad === 'virtual';

  return (
    <div className="space-y-4">
      {infra.exigida && !infra.completa ? (
        <Alerta tipo="aviso">
          {c.modalidad === 'presencial' ? 'Asigna sede y aula antes de abrir esta edición.' : 'Asigna una plataforma virtual antes de abrir esta edición.'}
        </Alerta>
      ) : null}
      {!asignable ? <Alerta tipo="info">Modalidad histórica ({etiqueta('modalidad', c.modalidad)}): no admite asignación de infraestructura.</Alerta> : null}

      <ListaDatos
        filas={
          c.modalidad === 'virtual'
            ? [['Plataforma virtual', infra.plataforma_virtual ? `${infra.plataforma_virtual.nombre} (${etiqueta('tipoPlataforma', infra.plataforma_virtual.tipo)})` : 'Sin asignar']]
            : [
                ['Sede', infra.sede ? `${infra.sede.codigo} · ${infra.sede.nombre}` : 'Sin asignar'],
                ['Aula', infra.aula ? `${infra.aula.codigo} · ${infra.aula.nombre} (${infra.aula.capacidad} personas)` : 'Sin asignar'],
              ]
        }
      />

      {puede && editable && asignable ? (
        <>
          <Boton variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>
            {infra.completa ? 'Cambiar infraestructura' : 'Asignar infraestructura'}
          </Boton>
          <Dialogo abierto={abierto} titulo="Infraestructura de la edición" descripcion={`${c.codigo} · ${etiqueta('modalidad', c.modalidad)}`} onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
            <FormularioInfraestructura convocatoria={c} sedes={sedes} aulas={aulas} plataformas={plataformas} onOcupado={setOcupado} onCerrar={() => setAbierto(false)} />
          </Dialogo>
        </>
      ) : null}
    </div>
  );
}

function FormularioInfraestructura({ convocatoria: c, sedes, aulas, plataformas, onOcupado, onCerrar }: Omit<PropsInfra, 'editable'> & { onOcupado: (b: boolean) => void; onCerrar: () => void }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const presencial = c.modalidad === 'presencial';
  const f = useFormularioPanel({
    esquema: presencial ? esquemaPresencial : esquemaVirtual,
    valores: { sede: c.infraestructura.sede?.identificador ?? '', aula: c.infraestructura.aula?.identificador ?? '', plataforma_virtual: c.infraestructura.plataforma_virtual?.identificador ?? '' },
    onOcupado,
    enviar: (d) =>
      ejecutar<{ message: string }>(`academico/convocatorias/${c.identificador}/infraestructura`, 'PUT', {
        sede: presencial ? d.sede : null,
        aula: presencial ? d.aula : null,
        plataforma_virtual: presencial ? null : d.plataforma_virtual,
        _revision: c.revision,
      }),
    onExito: (r) => {
      onCerrar();
      mostrar('exito', r.message);
      router.refresh();
    },
  });

  const sedeElegida = f.form.watch('sede');
  const opcionesSede: Record<string, string> = { '': 'Selecciona una sede', ...Object.fromEntries(sedes.map((s) => [s.identificador, `${s.codigo} · ${s.nombre}`])) };
  const aulasDeLaSede = aulas.filter((a) => a.sede?.identificador === sedeElegida);
  const opcionesAula: Record<string, string> = {
    '': sedeElegida === '' ? 'Primero elige la sede' : 'Selecciona un aula',
    ...Object.fromEntries(aulasDeLaSede.map((a) => [a.identificador, `${a.codigo} · ${a.nombre} (${a.capacidad} personas${a.capacidad < c.cupos.maximos ? ' — menor que los cupos' : ''})`])),
  };
  const opcionesPlataforma: Record<string, string> = { '': 'Selecciona una plataforma', ...Object.fromEntries(plataformas.map((p) => [p.identificador, `${p.nombre} (${etiqueta('tipoPlataforma', p.tipo)})`])) };

  return (
    <form onSubmit={f.enviar} noValidate className="space-y-4">
      {f.errorGeneral ? <Alerta tipo="error">{f.errorGeneral}</Alerta> : null}
      {presencial ? (
        <div className="grid gap-4 sm:grid-cols-2">
          <CampoSeleccion etiqueta="Sede" {...f.registro('sede', { onChange: () => f.form.setValue('aula', '') })} error={f.error('sede')} opciones={opcionesSede} />
          <CampoSeleccion etiqueta="Aula" {...f.registro('aula')} error={f.error('aula')} opciones={opcionesAula} disabled={sedeElegida === ''} ayuda={`La edición admite ${c.cupos.maximos} cupos.`} />
        </div>
      ) : (
        <CampoSeleccion etiqueta="Plataforma virtual" {...f.registro('plataforma_virtual')} error={f.error('plataforma_virtual')} opciones={opcionesPlataforma} />
      )}
      <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
        <Boton variante="secundario" onClick={onCerrar} disabled={f.guardando}>Cancelar</Boton>
        <Boton type="submit" cargando={f.guardando}>Guardar</Boton>
      </div>
    </form>
  );
}

// ---- Docentes de la edición ------------------------------------------------------------------------------------------

interface PropsDocentes {
  convocatoriaId: string;
  revision: string;
  /** `null`: la persona no puede ver docentes. */
  asignados: readonly DocenteAsignado[] | null;
  /** Docentes activos que se pueden asignar. */
  docentes: readonly DocenteResumen[];
  editable: boolean;
}

interface Fila {
  docente: string;
  rol: RolDocente;
}

const MAX_DOCENTES = 10;

/** Docentes de la edición: un principal como máximo y hasta 10. La lista se reemplaza completa con la revisión vigente. */
export function PanelDocentesEdicion({ convocatoriaId, revision, asignados, docentes, editable }: PropsDocentes) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puede = usePuede('convocatorias.editar');
  const [abierto, setAbierto] = useState(false);
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState<ErrorPanel | null>(null);
  const [filas, setFilas] = useState<Fila[]>([]);

  const opciones = useMemo<Record<string, string>>(
    () => ({ '': 'Selecciona un docente', ...Object.fromEntries(docentes.map((d) => [d.identificador, `${d.apellidos}, ${d.nombres} · ${d.dni}`])) }),
    [docentes],
  );

  if (asignados === null) return <Alerta tipo="info">No tienes permiso para ver los docentes de esta edición.</Alerta>;

  function abrir() {
    setFilas(asignados!.map((a) => ({ docente: a.docente, rol: a.rol })));
    setError(null);
    setAbierto(true);
  }

  const actualizar = (indice: number, cambio: Partial<Fila>) => setFilas((actuales) => actuales.map((f, i) => (i === indice ? { ...f, ...cambio } : f)));

  async function guardar() {
    setGuardando(true);
    setError(null);
    const r = await ejecutar<{ message: string }>(`academico/convocatorias/${convocatoriaId}/docentes`, 'PUT', { docentes: filas, _revision: revision });
    setGuardando(false);
    if (!r.ok) {
      setError(r.error);
      return;
    }
    setAbierto(false);
    mostrar('exito', r.datos.message);
    router.refresh();
  }

  const principales = filas.filter((f) => f.rol === 'principal').length;
  const hayVacias = filas.some((f) => f.docente === '');
  const errorLista = errorDeCampo(error, 'docentes');

  return (
    <div className="space-y-4">
      {asignados.length === 0 ? (
        <p className="text-sm text-panel-muted">Todavía no hay docentes asignados a esta edición.</p>
      ) : (
        <ul className="divide-y divide-panel-border rounded-lg border border-panel-border">
          {asignados.map((a) => (
            <li key={a.docente} className="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
              <span className="font-medium text-panel-text">{a.apellidos}, {a.nombres} <span className="font-mono text-xs text-panel-muted">· {a.dni}</span></span>
              <Insignia color={a.rol === 'principal' ? 'verde' : 'gris'}>{etiqueta('rolDocente', a.rol)}</Insignia>
            </li>
          ))}
        </ul>
      )}

      {puede && editable ? (
        <>
          <Boton variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={abrir}>Editar docentes</Boton>
          <Dialogo abierto={abierto} titulo="Docentes de la edición" descripcion="Un docente principal como máximo. Se guarda la lista completa." onCerrar={() => setAbierto(false)} bloqueado={guardando}>
            <div className="space-y-3">
              {error && error.code !== 'validacion' ? <Alerta tipo="error">{error.message}</Alerta> : null}
              {errorLista ? <Alerta tipo="error">{errorLista}</Alerta> : null}
              {filas.length === 0 ? <p className="text-sm text-panel-muted">Sin docentes. Guarda para dejar la edición sin docentes o agrega uno.</p> : null}
              {filas.map((fila, i) => (
                <div key={i} className="grid items-end gap-2 sm:grid-cols-[1fr_9rem_auto]">
                  <CampoSeleccion etiqueta={`Docente ${i + 1}`} value={fila.docente} onChange={(e) => actualizar(i, { docente: e.target.value })} opciones={opciones} error={errorDeCampo(error, `docentes.${i}.docente`)} />
                  <CampoSeleccion etiqueta="Rol" value={fila.rol} onChange={(e) => actualizar(i, { rol: e.target.value as RolDocente })} opciones={ETIQUETAS.rolDocente} error={errorDeCampo(error, `docentes.${i}.rol`)} />
                  <Boton variante="secundario" aria-label={`Quitar docente ${i + 1}`} icono={<Trash2 className="h-4 w-4" aria-hidden="true" />} onClick={() => setFilas((actuales) => actuales.filter((_, j) => j !== i))}>Quitar</Boton>
                </div>
              ))}
              <Boton variante="secundario" icono={<Plus className="h-4 w-4" aria-hidden="true" />} disabled={filas.length >= MAX_DOCENTES} onClick={() => setFilas((actuales) => [...actuales, { docente: '', rol: principales === 0 ? 'principal' : 'apoyo' }])}>
                Agregar docente
              </Boton>
              {principales > 1 ? <Alerta tipo="aviso">Solo puede haber un docente principal.</Alerta> : null}
              <div className="flex flex-col-reverse gap-2 border-t border-panel-border pt-4 sm:flex-row sm:justify-end">
                <Boton variante="secundario" onClick={() => setAbierto(false)} disabled={guardando}>Cancelar</Boton>
                <Boton onClick={guardar} cargando={guardando} disabled={hayVacias || principales > 1}>Guardar docentes</Boton>
              </div>
            </div>
          </Dialogo>
        </>
      ) : null}
    </div>
  );
}
