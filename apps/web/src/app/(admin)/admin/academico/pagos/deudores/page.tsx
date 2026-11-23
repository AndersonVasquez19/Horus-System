import { redirect } from 'next/navigation';

export default async function DeudoresRedirectPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const sp = await searchParams;
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(sp)) {
    if (typeof v === 'string' && v !== '') qs.set(k, v);
  }
  const sufijo = qs.toString() ? `?${qs.toString()}` : '';
  redirect(`/admin/academico/pagos${sufijo}`);
}
