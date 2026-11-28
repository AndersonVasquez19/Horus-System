import { redirect } from 'next/navigation';

/**
 * La pantalla de Riesgo de deserción se retiró (27/11/2026). La dirección se conserva porque quedan avisos y favoritos que la
 * apuntan.
 */
export default function RiesgoRetiradoPage() {
  redirect('/admin/academico');
}
