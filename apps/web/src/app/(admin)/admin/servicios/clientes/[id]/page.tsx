import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';
import { Suspense } from 'react';
import { EncabezadoPagina } from '@/components/shared/ui/encabezado-pagina';
import { EstadoError, EstadoProhibido, EstadoSinResultados } from '@/components/shared/ui/estados';
import { Insignia, colorEstado } from '@/components/shared/ui/insignia';
import { EsqueletoFicha, ListaDatos, Seccion } from '@/components/shared/ui/tabla';
import { AccionesCliente } from '@/components/servicios/acciones-cliente';
import type { ClienteDetalle } from '@/features/servicios/tipos';
import { consultarPanel } from '@/lib/panel/api-servidor';
import { etiqueta, fecha } from '@/lib/panel/formato';

export const metadata = { title: 'Cliente · Servicios tecnológicos' };

// Ver la nota de academico/cursos/[id]: la Navbar pública del layout raíz bloquea el prerender de rutas dinámicas.
export const instant = false;

const LISTADO = '/admin/servicios/clientes';

export default function ClienteDetallePage({ params }: { params: Promise<{ id: string }> }) {
  return (
    <Suspense fallback={<EsqueletoFicha etiqueta="Cargando el cliente" />}>
      <Ficha params={params} />
    </Suspense>
  );
}

async function Ficha({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const volver = (
    <Link href={LISTADO} className="inline-flex items-center gap-1 text-sm font-semibold text-servicios-700 hover:underline">
      <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Volver a clientes
    </Link>
  );

  if (!/^[0-9a-f-]{36}$/i.test(id)) return <EstadoSinResultados titulo="El cliente no existe" accion={volver} />;

  const resultado = await consultarPanel<{ data: ClienteDetalle }>(`servicios/clientes/${id}`, `${LISTADO}/${id}`);
  if (resultado.estado === 'prohibido') return <EstadoProhibido volver="/admin/servicios" />;
  if (resultado.estado === 'no_encontrado') return <EstadoSinResultados titulo="El cliente no existe" accion={volver} />;
  if (resultado.estado !== 'ok') return <EstadoError texto="Inténtalo de nuevo en unos minutos." accion={volver} />;

  const cliente = resultado.datos.data;

  return (
    <>
      <EncabezadoPagina
        titulo={cliente.nombre}
        descripcion={`${etiqueta('tipoCliente', cliente.tipo)}. Un cliente inactivo se conserva con todo su historial.`}
        migas={[
          { etiqueta: 'Inicio', href: '/admin/servicios' },
          { etiqueta: 'Clientes', href: LISTADO },
          { etiqueta: cliente.nombre },
        ]}
        acciones={<AccionesCliente cliente={cliente} />}
      />

      <div className="grid gap-6 xl:grid-cols-3">
        <Seccion id="titulo-datos" titulo="Datos" acciones={<Insignia color={colorEstado(cliente.estado)}>{etiqueta('estadoCliente', cliente.estado)}</Insignia>}>
          <ListaDatos
            filas={[
              ['Tipo', etiqueta('tipoCliente', cliente.tipo)],
              ['Documento', cliente.documento_numero ? `${etiqueta('documentoCliente', cliente.documento_tipo)} ${cliente.documento_numero}` : '—'],
              ['Correo', cliente.email ?? '—'],
              ['Teléfono', cliente.telefono ?? '—'],
              ['Dirección', cliente.direccion ?? '—'],
              ['Responsable', cliente.responsable?.nombre ?? 'Sin asignar'],
              ['Registrado', fecha(cliente.creado_en)],
            ]}
          />
        </Seccion>

        <div className="space-y-6 xl:col-span-2">
          <Seccion id="titulo-contactos" titulo="Personas de contacto" descripcion="El contacto principal aparece primero.">
            {cliente.contactos.length === 0 ? (
              <p className="py-8 text-center text-sm text-panel-muted">Sin contactos adicionales registrados.</p>
            ) : (
              <ul className="divide-y divide-panel-border">
                {cliente.contactos.map((contacto, indice) => (
                  <li key={indice} className="flex flex-wrap items-start justify-between gap-2 py-3">
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-panel-text">
                        {contacto.nombre}
                        {contacto.principal ? <span className="ml-2 align-middle"><Insignia color="azul">Principal</Insignia></span> : null}
                      </p>
                      {contacto.cargo ? <p className="text-xs text-panel-muted">{contacto.cargo}</p> : null}
                    </div>
                    <p className="text-sm text-panel-muted">{[contacto.email, contacto.telefono].filter(Boolean).join(' · ') || '—'}</p>
                  </li>
                ))}
              </ul>
            )}
          </Seccion>
        </div>
      </div>
    </>
  );
}
