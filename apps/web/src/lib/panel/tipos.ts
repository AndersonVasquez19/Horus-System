/**
 * Tipos compartidos entre el servidor y el cliente del panel. Sin secretos: es lo que Laravel devuelve en
 * `GET /api/panel/v1/auth/sesion` (el token CSRF pertenece a la sesión del propio usuario).
 */

export interface UsuarioSesion {
  id: number;
  nombre: string;
  email: string;
}

export interface SesionPanel {
  usuario: UsuarioSesion;
  roles: string[];
  permisos: string[];
  paneles: { academico: boolean };
  csrf_token: string;
  expira_en: number | null;
}

export type PanelId = keyof SesionPanel['paneles'];

export interface ErrorPanel {
  message: string;
  code: string;
  errors?: Record<string, string[]>;
}
