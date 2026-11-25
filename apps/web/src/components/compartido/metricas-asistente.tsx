import { MessageSquareText, MessagesSquare, ShieldCheck, ThumbsUp } from 'lucide-react';
import Link from 'next/link';
import { etiquetaTema } from '@/components/compartido/temas-asistente';
import { GraficoBarras } from '@/components/shared/ui/grafico-barras';
import { Seccion } from '@/components/shared/ui/tabla';
import { TarjetaKpi } from '@/components/shared/ui/tarjeta-kpi';

export interface Metricas {
  dias: number;
  consultas: number;
  conversaciones: number;
  respondidas: number;
  contingencia: number;
  /** Registros antiguos sin motor anotado: quedan fuera del porcentaje de respondidas. */
  sin_origen: number;
  /** Conversaciones que llegaron a cada etapa; `whatsapp` es `null` hasta que la base registre el clic. */
  etapas: { interesado: number; matricula: number; contacto: number; reclamos: number; whatsapp: number | null };
  /** Consultas que guardaron su tema: la base sobre la que se mide «sin dato». */
  con_tema: number;
  sin_dato: number;
  temas: { nombre: string; total: number }[];
  por_dia: { etiqueta: string; total: number }[];
  /** `null` mientras la base no tenga la columna de votos. */
  votos: { utiles: number; no_utiles: number } | null;
  separa_canal: boolean;
}

const porcentaje = (parte: number, total: number) => (total === 0 ? '—' : `${Math.round((parte / total) * 100)}%`);
const plural = (n: number, uno: string, varios: string) => `${n} ${n === 1 ? uno : varios}`;

function SelectorPeriodo({ ruta, dias }: { ruta: string; dias: number }) {
  return (
    <nav aria-label="Periodo del resumen" className="inline-flex rounded-lg border border-panel-border bg-panel-surface p-1">
      {[7, 30].map((opcion) => (
        <Link
          key={opcion}
          href={`${ruta}?dias=${opcion}`}
          aria-current={opcion === dias ? 'true' : undefined}
          className={`inline-flex min-h-9 items-center rounded-md px-3 text-sm font-semibold ${
            opcion === dias ? 'bg-academico-700 text-white' : 'text-panel-muted hover:bg-panel-bg hover:text-panel-text'
          }`}
        >
          {opcion} días
        </Link>
      ))}
    </nav>
  );
}

/** Cuántas conversaciones llegaron a cada etapa. Cada fila abre esas conversaciones. */
function Alcance({ metricas, ruta }: { metricas: Metricas; ruta: string }) {
  const { etapas, conversaciones: total } = metricas;
  const lista = (etapa: string) => `${ruta}?vista=conversaciones&etapa=${etapa}&dias=${metricas.dias}`;
  const filas: { nombre: string; valor: number | null; href: string }[] = [
    { nombre: 'Todas las conversaciones', valor: total, href: `${ruta}?vista=conversaciones&dias=${metricas.dias}` },
    { nombre: 'Mostraron interés', valor: etapas.interesado, href: lista('interesado') },
    { nombre: 'Quieren matricularse', valor: etapas.matricula, href: lista('matricula') },
    { nombre: 'Abrieron WhatsApp', valor: etapas.whatsapp, href: lista('whatsapp') },
  ];

  return (
    <Seccion id="titulo-alcance" titulo="¿Hasta dónde llegan?" descripcion={`Conversaciones de los últimos ${metricas.dias} días`}>
      <ul className="space-y-1">
        {filas.map(({ nombre, valor, href }) => {
          const contenido = (
            <>
              <span className="text-panel-text">{nombre}</span>
              <span className="order-last h-2.5 overflow-hidden rounded-full bg-panel-bg max-sm:col-span-2 sm:order-none" aria-hidden="true">
                <span className="block h-full rounded-full bg-academico-600" style={{ width: `${total === 0 || valor === null ? 0 : (valor / total) * 100}%` }} />
              </span>
              <span className="text-right font-semibold tabular-nums text-panel-text">
                {valor === null ? '—' : valor}
                {valor !== null && total > 0 && nombre !== 'Todas las conversaciones' ? (
                  <span className="ml-2 font-normal text-panel-muted">{porcentaje(valor, total)}</span>
                ) : null}
              </span>
            </>
          );
          const clase = 'grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-1 rounded-lg px-2 py-2 text-sm sm:grid-cols-[13rem_1fr_6rem]';
          return (
            <li key={nombre}>
              {valor === null || valor === 0 ? (
                <div className={clase}>{contenido}</div>
              ) : (
                <Link href={href} className={`${clase} hover:bg-panel-bg focus-visible:outline-2 focus-visible:outline-academico-700`}>
                  {contenido}
                </Link>
              )}
            </li>
          );
        })}
      </ul>
      <p className="mt-3 text-sm text-panel-muted">
        También:{' '}
        <Link href={lista('contacto')} className="font-medium text-academico-700 underline underline-offset-2">
          {etapas.contacto} {etapas.contacto === 1 ? 'pidió' : 'pidieron'} contacto
        </Link>{' '}
        ·{' '}
        <Link href={lista('reclamos')} className="font-medium text-academico-700 underline underline-offset-2">
          {etapas.reclamos} {etapas.reclamos === 1 ? 'consultó' : 'consultaron'} reclamos
        </Link>
      </p>
      <p className="mt-1 text-xs text-panel-muted">
        {etapas.whatsapp === null
          ? 'Los clics en WhatsApp se medirán al actualizar la base. '
          : '«Abrieron WhatsApp» es el clic en el botón del chat, no un mensaje enviado. '}
        En las conversaciones antiguas, la etapa se estima por las respuestas.
      </p>
    </Seccion>
  );
}

/**
 * Resumen de uso del asistente. Las barras por tema son decorativas (`aria-hidden`): el nombre y el total de cada una
 * están escritos como texto.
 */
export function MetricasAsistente({ metricas, ruta }: { metricas: Metricas; ruta: string }) {
  const { votos } = metricas;
  const totalVotos = votos === null ? 0 : votos.utiles + votos.no_utiles;
  const maximo = Math.max(1, ...metricas.temas.map((t) => t.total));
  const promedio = metricas.conversaciones === 0 ? null : (metricas.consultas / metricas.conversaciones).toFixed(1).replace('.0', '');

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-panel-muted">
          {metricas.separa_canal
            ? 'Consultas del sitio público. Las pruebas del panel no cuentan.'
            : 'Incluye las pruebas del panel hasta que se actualice la base.'}
        </p>
        <SelectorPeriodo ruta={ruta} dias={metricas.dias} />
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <TarjetaKpi
          tono="academico"
          etiqueta="Conversaciones"
          valor={String(metricas.conversaciones)}
          detalle={promedio === null ? 'Todavía no hay conversaciones' : `${promedio} consultas por conversación`}
          icono={<MessagesSquare className="h-5 w-5" />}
          href={`${ruta}?vista=conversaciones&dias=${metricas.dias}`}
        />
        <TarjetaKpi
          tono="academico"
          etiqueta="Consultas"
          valor={String(metricas.consultas)}
          detalle={`En los últimos ${metricas.dias} días`}
          icono={<MessageSquareText className="h-5 w-5" />}
        />
        <TarjetaKpi
          tono="academico"
          etiqueta="Respondidas"
          valor={porcentaje(metricas.respondidas, metricas.respondidas + metricas.contingencia)}
          detalle={`${metricas.contingencia} con contingencia o bloqueo de privacidad${
            metricas.sin_origen > 0 ? ` · ${metricas.sin_origen} antiguas sin ese dato` : ''
          }`}
          icono={<ShieldCheck className="h-5 w-5" />}
        />
        <TarjetaKpi
          tono="academico"
          etiqueta="Votos útiles"
          valor={votos === null ? '—' : porcentaje(votos.utiles, totalVotos)}
          detalle={
            votos === null
              ? 'Disponible al actualizar la base'
              : totalVotos === 0
                ? 'Todavía no hay votos'
                : `${plural(votos.utiles, 'útil', 'útiles')} y ${plural(votos.no_utiles, 'no útil', 'no útiles')}`
          }
          icono={<ThumbsUp className="h-5 w-5" />}
        />
      </div>

      <Alcance metricas={metricas} ruta={ruta} />

      <div className="grid gap-5 lg:grid-cols-2">
        <Seccion id="titulo-por-dia" titulo="Consultas por día" descripcion="Últimos 7 días, hora de Lima">
          <GraficoBarras tono="academico" titulo="Consultas por día" columna="Día" datos={metricas.por_dia} />
        </Seccion>

        <Seccion id="titulo-temas" titulo="¿Sobre qué preguntan?" descripcion={`Temas más consultados en ${metricas.dias} días`}>
          {metricas.temas.length === 0 ? (
            <p className="text-sm text-panel-muted">Todavía no hay consultas con tema registrado.</p>
          ) : (
            <ul className="space-y-3">
              {metricas.temas.map((tema) => (
                <li key={tema.nombre} className="text-sm">
                  <div className="flex items-baseline justify-between gap-3">
                    <span className="text-panel-text">{etiquetaTema(tema.nombre)}</span>
                    <span className="font-semibold tabular-nums text-panel-text">{tema.total}</span>
                  </div>
                  <span className="mt-1 block h-2 overflow-hidden rounded-full bg-panel-bg" aria-hidden="true">
                    <span className="block h-full rounded-full bg-academico-600" style={{ width: `${(tema.total / maximo) * 100}%` }} />
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Seccion>
      </div>

      <div className="rounded-xl border border-panel-border bg-panel-surface p-5 shadow-sm">
        <p className="text-sm text-panel-text">
          <strong>Preguntas sin dato publicado: </strong>
          {metricas.con_tema === 0
            ? '— (todavía no hay consultas con tema registrado).'
            : `${metricas.sin_dato} de ${plural(metricas.con_tema, 'consulta con tema', 'consultas con tema')}.${
                metricas.sin_dato > 0 ? ' El asistente lo dice en vez de inventar.' : ''
              }`}{' '}
          {metricas.sin_dato > 0 ? (
            <Link href={`${ruta}?vista=conversaciones&intent=sin_dato&dias=${metricas.dias}`} className="font-semibold text-academico-700 underline underline-offset-2">
              Ver cuáles
            </Link>
          ) : null}
        </p>
        <p className="mt-1 text-xs text-panel-muted">Las consultas anteriores al registro de métricas no tienen tema guardado.</p>
      </div>
    </div>
  );
}
