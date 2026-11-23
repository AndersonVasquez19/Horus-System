/**
 * Esquemas Zod de los formularios de entidad del panel (estudiante, curso, usuario, rol).
 *
 * Solo ayudan a la persona antes de enviar: Laravel sigue siendo la autoridad y vuelve a validar todo. Por eso aquí solo
 * se reflejan reglas ya conocidas (obligatorios, largos máximos, DNI de 8 dígitos y los límites numéricos documentados
 * en CLAUDE.md), sin inventar reglas que Laravel no tenga: un esquema más estricto que el servidor bloquearía datos válidos.
 *
 * Los nombres de los campos son los que usa Laravel (`name`, `password`, `permissions`…), porque `useFormularioPanel`
 * enlaza el error del servidor con el campo por ese nombre.
 *
 * Módulo puro (sin React ni imports locales) para poder probarlo con `node --test`.
 */
import { z } from 'zod';

/** Rango de un entero `integer` de PostgreSQL (duración y cupos) y de `decimal(10,2)` (precio). */
export const LIMITE_ENTERO = 2147483647;
export const LIMITE_PRECIO = 99999999.99;

const texto = (max: number) => z.string().trim().max(max, `Hasta ${max} caracteres.`);
const obligatorio = (mensaje: string, max: number) => z.string().trim().min(1, mensaje).max(max, `Hasta ${max} caracteres.`);

/** Entero positivo escrito como texto, dentro del rango de PostgreSQL. */
const enteroPositivo = (mensaje: string) =>
  z
    .string()
    .trim()
    .min(1, mensaje)
    .refine((v) => /^\d+$/.test(v) && Number(v) >= 1 && Number(v) <= LIMITE_ENTERO, `Escribe un número entero entre 1 y ${LIMITE_ENTERO}.`);

/** Correo con forma mínima (algo@algo): el formato fino lo decide Laravel. */
const FORMA_CORREO = /^[^\s@]+@[^\s@]+$/;

export const FECHA_NACIMIENTO_MINIMA = '1920-01-01';
export const FECHA_NACIMIENTO_MAXIMA = '2010-12-31';

export const esquemaEstudiante = z.object({
  dni: z
    .string()
    .trim()
    .regex(/^\d{8}$/, 'El DNI debe tener 8 dígitos.')
    .refine((v) => v !== '00000000', 'El DNI ingresado no es válido.'),
  nombres: obligatorio('Indica los nombres.', 100)
    .min(2, 'Al menos 2 caracteres.')
    .regex(/^[\p{L}\s'\-.]+$/u, 'Solo letras y espacios.'),
  apellidos: obligatorio('Indica los apellidos.', 100)
    .min(2, 'Al menos 2 caracteres.')
    .regex(/^[\p{L}\s'\-.]+$/u, 'Solo letras y espacios.'),
  email: texto(150).refine((v) => v === '' || FORMA_CORREO.test(v), 'Escribe un correo válido.'),
  telefono: z
    .string()
    .trim()
    .transform((v) => v.replace(/[\s-]+/g, ''))
    .refine((v) => v === '' || /^9\d{8}$/.test(v), 'El teléfono debe tener 9 dígitos e iniciar con 9.'),
  fecha_nacimiento: z
    .string()
    .trim()
    .refine(
      (v) => v === '' || (/^\d{4}-\d{2}-\d{2}$/.test(v) && v >= FECHA_NACIMIENTO_MINIMA && v <= FECHA_NACIMIENTO_MAXIMA),
      'La fecha de nacimiento debe estar entre 1920 y el año 2010.'
    ),
  direccion: texto(255).refine((v) => !/[<>]/.test(v), 'Contiene caracteres no permitidos.'),
  estado: z.string().min(1),
});
export type DatosEstudiante = z.infer<typeof esquemaEstudiante>;

export const esquemaCurso = z.object({
  codigo: obligatorio('Indica el código.', 20),
  nombre: obligatorio('Indica el nombre.', 150),
  descripcion: obligatorio('Indica la descripción.', 5000),
  categoria: z.string().min(1),
  modalidad: z.string().min(1),
  estado: z.string().min(1),
  horario: obligatorio('Indica el horario.', 100),
  duracion_horas: enteroPositivo('Indica la duración en horas.'),
  precio: z
    .string()
    .trim()
    .min(1, 'Indica el precio.')
    .refine((v) => /^\d+(\.\d{1,2})?$/.test(v) && Number(v) <= LIMITE_PRECIO, `Precio válido: hasta 2 decimales y como máximo ${LIMITE_PRECIO}.`),
  cupos_maximos: enteroPositivo('Indica los cupos máximos.'),
  enlace_grupo_whatsapp: texto(255),
});
export type DatosCurso = z.infer<typeof esquemaCurso>;

/**
 * Usuario. Al crear, la contraseña es obligatoria; al editar es opcional (vacía = conservar la actual). Si se escribe,
 * debe coincidir con su confirmación. Su complejidad la valida Laravel (`PasswordPolicy`).
 */
export function crearEsquemaUsuario(edicion: boolean) {
  return z
    .object({
      name: obligatorio('Indica el nombre.', 255),
      email: obligatorio('Indica el correo.', 255).refine((v) => FORMA_CORREO.test(v), 'Escribe un correo válido.'),
      password: z.string().max(255, 'Hasta 255 caracteres.'),
      password_confirmation: z.string().max(255, 'Hasta 255 caracteres.'),
      temporal: z.boolean(),
      roles: z.array(z.string()),
    })
    .superRefine((datos, contexto) => {
      if (!edicion && datos.password === '') {
        contexto.addIssue({ code: 'custom', path: ['password'], message: 'Indica una contraseña.' });
      }
      if ((!edicion || datos.password !== '') && datos.password !== datos.password_confirmation) {
        contexto.addIssue({ code: 'custom', path: ['password_confirmation'], message: 'Las contraseñas no coinciden.' });
      }
    });
}
export type DatosUsuario = z.infer<ReturnType<typeof crearEsquemaUsuario>>;

export const esquemaRol = z.object({
  name: obligatorio('Indica el nombre del rol.', 255),
  permissions: z.array(z.string()),
});
export type DatosRol = z.infer<typeof esquemaRol>;

/** Cuerpo del estudiante para Laravel: los opcionales vacíos viajan como `null` (sus Form Requests los declaran `nullable`). */
export function cuerpoEstudiante(datos: DatosEstudiante): Record<string, string | null> {
  return Object.fromEntries(Object.entries(datos).map(([campo, valor]) => [campo, valor === '' ? null : valor]));
}

/**
 * Cuerpo del usuario para Laravel. Las contraseñas solo viajan al crear o si se escribió una nueva; `roles` solo si
 * quien actúa puede asignarlos (su ausencia no significa «quitar todos»).
 */
export function cuerpoUsuario(datos: DatosUsuario, opciones: { edicion: boolean; puedeAsignarRoles: boolean }): Record<string, unknown> {
  const cuerpo: Record<string, unknown> = { name: datos.name, email: datos.email };
  if (datos.password !== '' || !opciones.edicion) {
    cuerpo.password = datos.password;
    cuerpo.password_confirmation = datos.password_confirmation;
    cuerpo.temporal = datos.temporal;
  }
  if (opciones.puedeAsignarRoles) cuerpo.roles = datos.roles;
  return cuerpo;
}
