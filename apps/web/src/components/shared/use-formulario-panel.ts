'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useForm, type DefaultValues, type FieldValues, type Path } from 'react-hook-form';
import type { ZodType, ZodTypeDef } from 'zod';
import { errorDeCampo, type ResultadoBff } from '@/lib/panel/cliente';
import type { ErrorPanel } from '@/lib/panel/tipos';

interface Opciones<T extends FieldValues, R> {
  /** Esquema Zod del formulario: solo ayuda a la persona; Laravel sigue siendo la autoridad y vuelve a validar. */
  esquema: ZodType<T, ZodTypeDef, T>;
  valores: DefaultValues<T>;
  /** Envía los datos ya validados al BFF (normalmente `ejecutar(...)` de `useOperacionPanel`). */
  enviar: (datos: T) => Promise<ResultadoBff<R>>;
  onExito: (respuesta: R) => void;
  /** Avisa si hay un envío en curso (para bloquear el cierre del diálogo). */
  onOcupado?: (ocupado: boolean) => void;
}

/**
 * Kit de formularios del panel (Horus Academic): React Hook Form + Zod + errores de Laravel.
 *
 *  - La validación del navegador (Zod) evita viajes inútiles; los errores de campo que devuelve Laravel (`code: 'validacion'`)
 *    se muestran en el mismo campo, y los demás errores (403, 409, 412, red…) en un aviso general.
 *  - Los campos vacíos viajan como `null` solo si el esquema los declara así: el esquema decide la forma del cuerpo.
 *  - Uso: `<Campo etiqueta="Nombre" {...f.registro('nombre')} error={f.error('nombre')} />`.
 */
export function useFormularioPanel<T extends FieldValues, R>({ esquema, valores, enviar, onExito, onOcupado }: Opciones<T, R>) {
  const form = useForm<T>({ resolver: zodResolver(esquema), defaultValues: valores, mode: 'onSubmit' });
  const [errorServidor, setErrorServidor] = useState<ErrorPanel | null>(null);
  const [guardando, setGuardando] = useState(false);

  const enviarFormulario = form.handleSubmit(async (datos) => {
    setGuardando(true);
    onOcupado?.(true);
    setErrorServidor(null);
    let resultado: ResultadoBff<R> | null = null;
    try {
      resultado = await enviar(datos);
    } catch {
      // Un fallo inesperado no debe dejar el diálogo bloqueado (también bloquea Escape).
      setErrorServidor({ message: 'No se pudo completar la operación. Inténtalo de nuevo.', code: 'error_inesperado' });
    } finally {
      setGuardando(false);
      onOcupado?.(false);
    }
    if (!resultado) return;
    if (resultado.ok) onExito(resultado.datos);
    else setErrorServidor(resultado.error);
  });

  /** Mensaje del campo: primero el del navegador, luego el que devolvió Laravel. */
  const error = (campo: Path<T>): string | undefined => {
    const local = form.formState.errors[campo]?.message;
    return typeof local === 'string' ? local : errorDeCampo(errorServidor, campo);
  };

  /**
   * Error de un campo de lista (`roles`, `permissions`): el del campo en sí o, si no hay, el primero de sus elementos
   * (`roles.0`, `roles.1`…). Laravel valida cada elemento por separado.
   */
  const errorLista = (campo: Path<T>): string | undefined => {
    const propio = error(campo);
    if (propio) return propio;
    const claves = Object.keys(errorServidor?.errors ?? {}).filter((clave) => clave.startsWith(`${campo}.`));
    for (const clave of claves) {
      const mensaje = errorDeCampo(errorServidor, clave);
      if (mensaje) return mensaje;
    }
    return undefined;
  };

  return {
    form,
    registro: form.register,
    enviar: enviarFormulario,
    guardando,
    error,
    errorLista,
    /** Error que no pertenece a un campo (permisos, conflicto, red…). */
    errorGeneral: errorServidor && errorServidor.code !== 'validacion' ? errorServidor.message : null,
    hayErroresDeCampo: errorServidor?.code === 'validacion' || Object.keys(form.formState.errors).length > 0,
  };
}
