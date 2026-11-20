import { EsqueletoListado } from '@/components/shared/ui/tabla';

export default function AdminLoading() {
  return (
    <div className="mx-auto w-full max-w-5xl p-6">
      <EsqueletoListado etiqueta="Cargando el panel" />
    </div>
  );
}
