/** Etiquetas de las aprobaciones internas, compartidas por la página (servidor) y sus acciones (cliente). */
export const ETIQUETAS_TIPO_APROBACION: Record<string, string> = { compra: 'Compra / Presupuesto', operativo: 'Operativo / Académico' };

/** Para mostrar una solicitud ya creada: añade los tipos que solo crea el sistema (no se ofrecen al crear una nueva). */
export const ETIQUETAS_TIPO_APROBACION_LISTADO: Record<string, string> = { ...ETIQUETAS_TIPO_APROBACION, reapertura_acta: 'Reapertura de acta', reverso_pago: 'Reverso de pago' };

export const ETIQUETAS_AUTORIDAD: Record<string, string> = { admin: 'Gerencia', coordinador: 'Coordinación' };
