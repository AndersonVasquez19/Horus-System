import { redirect } from 'next/navigation';

/** La infraestructura se organiza en tres catálogos; se entra por el de sedes. */
export default function InfraestructuraPage() {
  redirect('/admin/academico/infraestructura/sedes');
}
