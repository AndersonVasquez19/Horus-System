import { redirect } from 'next/navigation';

/**
 * La pantalla de Cobranza se retiró (27/11/2026): la deuda vencida se gestiona en Pagos. La dirección se conserva porque quedan
 * avisos y favoritos que la apuntan.
 */
export default function CobranzaRetiradaPage() {
  redirect('/admin/academico/pagos?estado=vencidas');
}
