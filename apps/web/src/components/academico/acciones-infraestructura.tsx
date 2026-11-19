'use client';

import { Pencil, Plus, Power, PowerOff } from 'lucide-react';
import { useRouter } from 'next/navigation';
import { useState, type ReactNode } from 'react';
import { usePuede } from '@/components/shared/sesion-panel-contexto';
import { useOperacionPanel } from '@/components/shared/use-operacion-panel';
import { useAvisos } from '@/components/shared/ui/avisos';
import { Boton } from '@/components/shared/ui/boton';
import { Dialogo, DialogoConfirmacion } from '@/components/shared/ui/dialogo';
import type { AulaResumen, EstadoActivo, PlataformaResumen, SedeResumen } from '@/features/academico/tipos';
import { FormularioAula, FormularioPlataforma, FormularioSede } from './formularios-infraestructura';

type Formulario = (props: { onGuardado: (mensaje: string) => void; onCancelar: () => void; onOcupado: (ocupado: boolean) => void }) => ReactNode;

/** Botón «Nuevo …» de un listado: abre el formulario en un diálogo y refresca el listado al guardar. */
function BotonNuevo({ permiso, etiqueta, titulo, descripcion, formulario }: { permiso: string; etiqueta: string; titulo: string; descripcion: string; formulario: Formulario }) {
  const puede = usePuede(permiso);
  const router = useRouter();
  const { mostrar } = useAvisos();
  const [abierto, setAbierto] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  if (!puede) return null;

  return (
    <>
      <Boton icono={<Plus className="h-4 w-4" aria-hidden="true" />} onClick={() => setAbierto(true)}>{etiqueta}</Boton>
      <Dialogo abierto={abierto} titulo={titulo} descripcion={descripcion} onCerrar={() => setAbierto(false)} bloqueado={ocupado}>
        {formulario({
          onOcupado: setOcupado,
          onCancelar: () => setAbierto(false),
          onGuardado: (mensaje) => {
            setAbierto(false);
            mostrar('exito', mensaje);
            router.refresh();
          },
        })}
      </Dialogo>
    </>
  );
}

/** Editar y activar/desactivar un registro de infraestructura (no se eliminan: se desactivan). */
function AccionesFila({ ruta, estado, nombre, entidad, formulario }: { ruta: string; estado: EstadoActivo; nombre: string; entidad: string; formulario: Formulario }) {
  const router = useRouter();
  const { mostrar } = useAvisos();
  const { ejecutar } = useOperacionPanel();
  const puede = usePuede('infraestructura.gestionar');
  const [editando, setEditando] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [confirmando, setConfirmando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (!puede) return null;

  const destino: EstadoActivo = estado === 'activo' ? 'inactivo' : 'activo';

  async function cambiarEstado() {
    setOcupado(true);
    setError(null);
    const resultado = await ejecutar<{ message: string }>(`${ruta}/estado`, 'POST', { estado: destino });
    setOcupado(false);
    if (!resultado.ok) {
      setError(resultado.error.errors ? Object.values(resultado.error.errors)[0]?.[0] ?? resultado.error.message : resultado.error.message);
      return;
    }
    setConfirmando(false);
    mostrar('exito', resultado.datos.message);
    router.refresh();
  }

  return (
    <div className="flex justify-end gap-1">
      <Boton variante="secundario" icono={<Pencil className="h-4 w-4" aria-hidden="true" />} onClick={() => setEditando(true)} aria-label={`Editar ${nombre}`}>Editar</Boton>
      <Boton
        variante="secundario"
        icono={estado === 'activo' ? <PowerOff className="h-4 w-4" aria-hidden="true" /> : <Power className="h-4 w-4" aria-hidden="true" />}
        onClick={() => setConfirmando(true)}
        aria-label={`${estado === 'activo' ? 'Desactivar' : 'Activar'} ${nombre}`}
      >
        {estado === 'activo' ? 'Desactivar' : 'Activar'}
      </Boton>

      <Dialogo abierto={editando} titulo={`Editar ${entidad}`} descripcion={nombre} onCerrar={() => setEditando(false)} bloqueado={ocupado}>
        {formulario({
          onOcupado: setOcupado,
          onCancelar: () => setEditando(false),
          onGuardado: (mensaje) => {
            setEditando(false);
            mostrar('exito', mensaje);
            router.refresh();
          },
        })}
      </Dialogo>

      <DialogoConfirmacion
        abierto={confirmando}
        titulo={destino === 'inactivo' ? `¿Desactivar ${entidad}?` : `¿Activar ${entidad}?`}
        texto={
          destino === 'inactivo'
            ? <p>Dejará de ofrecerse para nuevas asignaciones. No se elimina: puede activarse de nuevo. Si lo usan ediciones vigentes, primero hay que cambiarlo en ellas.</p>
            : <p>Volverá a ofrecerse para asignarlo a ediciones.</p>
        }
        confirmar={destino === 'inactivo' ? 'Desactivar' : 'Activar'}
        peligro={destino === 'inactivo'}
        cargando={ocupado}
        error={error}
        onConfirmar={cambiarEstado}
        onCerrar={() => { setConfirmando(false); setError(null); }}
      />
    </div>
  );
}

// ---- Sedes -----------------------------------------------------------------------------------------------------------

export function BotonNuevaSede() {
  return <BotonNuevo permiso="infraestructura.gestionar" etiqueta="Nueva sede" titulo="Registrar sede" descripcion="Un local donde se dictan clases presenciales." formulario={(p) => <FormularioSede {...p} />} />;
}

export function AccionesSede({ sede }: { sede: SedeResumen }) {
  return <AccionesFila ruta={`academico/sedes/${sede.identificador}`} estado={sede.estado} nombre={sede.nombre} entidad="sede" formulario={(p) => <FormularioSede registro={sede} {...p} />} />;
}

// ---- Aulas -----------------------------------------------------------------------------------------------------------

type SedeOpcion = Pick<SedeResumen, 'identificador' | 'codigo' | 'nombre'>;

export function BotonNuevaAula({ sedes }: { sedes: readonly SedeOpcion[] }) {
  return <BotonNuevo permiso="infraestructura.gestionar" etiqueta="Nueva aula" titulo="Registrar aula" descripcion="Cada aula pertenece a una sede y tiene una capacidad." formulario={(p) => <FormularioAula sedes={sedes} {...p} />} />;
}

export function AccionesAula({ aula, sedes }: { aula: AulaResumen; sedes: readonly SedeOpcion[] }) {
  return <AccionesFila ruta={`academico/aulas/${aula.identificador}`} estado={aula.estado} nombre={`${aula.codigo} · ${aula.nombre}`} entidad="aula" formulario={(p) => <FormularioAula registro={aula} sedes={sedes} {...p} />} />;
}

// ---- Plataformas virtuales -------------------------------------------------------------------------------------------

export function BotonNuevaPlataforma() {
  return <BotonNuevo permiso="infraestructura.gestionar" etiqueta="Nueva plataforma" titulo="Registrar plataforma virtual" descripcion="La herramienta externa donde se dictan las clases virtuales." formulario={(p) => <FormularioPlataforma {...p} />} />;
}

export function AccionesPlataforma({ plataforma }: { plataforma: PlataformaResumen }) {
  return <AccionesFila ruta={`academico/plataformas-virtuales/${plataforma.identificador}`} estado={plataforma.estado} nombre={plataforma.nombre} entidad="plataforma" formulario={(p) => <FormularioPlataforma registro={plataforma} {...p} />} />;
}
