/**
 * Interruptor de visibilidad del área «Tecnologías» (servicios tecnológicos y
 * cotizaciones) en la web pública. Está oculta por decisión del propietario
 * (20/11/2026); no se borró nada. Para reactivarla basta con cambiar a `true`.
 */
export const TECNOLOGIAS_VISIBLES = false;

/**
 * Interruptor de la sección «Reclamaciones» del panel. Está oculta por decisión del propietario (28/11/2026); no se
 * borró nada. Los mensajes de «Contáctanos», que viven en la misma ruta, siguen visibles. No afecta a la web pública
 * ni a la API. Para reactivarla basta con cambiar a `true`; hazlo antes de abrir el Libro de Reclamaciones.
 */
export const RECLAMACIONES_EN_PANEL: boolean = false;

/** Casillas de «Roles y permisos» que solo sirven al libro. Ocultarlas no quita el permiso a quien ya lo tiene. */
export const permisoVisible = (clave: string): boolean => RECLAMACIONES_EN_PANEL || clave !== 'reclamaciones.responder';
