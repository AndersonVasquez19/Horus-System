/** Contratos de /api/panel/v1/compartido (notificaciones, perfil y sesiones de la cuenta propia). */

export interface Notificacion {
  id: number;
  titulo: string;
  mensaje: string | null;
  tipo: string | null;
  /** ISO 8601. */
  fecha: string | null;
  /** Fecha y hora en Lima, ya formateada por Laravel (`dd/mm/aaaa hh:mm`). */
  fecha_local: string | null;
  /** Ruta interna segura (o null: Laravel descarta enlaces externos). */
  vinculo: string | null;
  leida: boolean;
}

export interface PerfilPropio {
  id: number;
  nombre: string;
  email: string;
  roles: string[];
}

export interface SesionActiva {
  identificador: string;
  ip: string | null;
  navegador: string | null;
  ultima_actividad: string;
  actual: boolean;
}
