import type { NombreIconoPanel } from './panel-iconos';

/** Identidad visual de cada panel administrativo. */
export type TonoPanel = 'academico';

/**
 * Identificador de permiso tal como lo publica Laravel en `GET /api/panel/v1/auth/sesion` (cadena opaca).
 * Solo se pueden declarar permisos que existan en `RolesAndPermissionsSeeder`.
 */
export type IdentificadorPermiso = string;

/** Cómo se decide que una entrada está activa respecto a la ruta actual. */
export type CoincidenciaRuta = 'exacta' | 'prefijo';

/** Entrada de la barra lateral de un panel. */
export interface ElementoNavegacion {
  /** Estable y único dentro del panel (kebab-case). No se deriva de la etiqueta ni de la ruta. */
  id: string;
  etiqueta: string;
  href: string;
  icono?: NombreIconoPanel;
  /**
   * Permisos que el usuario debe tener (todos). Ausente o vacío: visible para quien pueda entrar al
   * panel. `filtrarNavegacion` lo aplica en el servidor; es solo una ayuda visual: Laravel vuelve a
   * comprobar cada operación.
   */
  permisosRequeridos?: readonly IdentificadorPermiso[];
  /**
   * Regla de estado activo. Por defecto `'prefijo'` (la ruta o cualquier subruta). Usar `'exacta'`
   * en la entrada de inicio de un panel, o quedaría activa en todas sus páginas.
   */
  coincidencia?: CoincidenciaRuta;
}

/** Agrupación de entradas. Sin `etiqueta`, el grupo no lleva encabezado. */
export interface GrupoNavegacion {
  id: string;
  etiqueta?: string;
  elementos: readonly ElementoNavegacion[];
}

/** Navegación completa de un panel. */
export type ModeloNavegacion = readonly GrupoNavegacion[];

/** Deja solo las entradas cuyos permisos requeridos tiene el usuario; descarta los grupos vacíos. */
export function filtrarNavegacion(modelo: ModeloNavegacion, permisos: readonly IdentificadorPermiso[]): ModeloNavegacion {
  const concedidos = new Set(permisos);
  return modelo
    .map((grupo) => ({
      ...grupo,
      elementos: grupo.elementos.filter((elemento) =>
        (elemento.permisosRequeridos ?? []).every((permiso) => concedidos.has(permiso)),
      ),
    }))
    .filter((grupo) => grupo.elementos.length > 0);
}
