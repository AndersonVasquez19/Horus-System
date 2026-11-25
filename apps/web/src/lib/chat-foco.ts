/**
 * Decide de qué curso trata una respuesta, para la barra «Consultando» y el mensaje de WhatsApp. Regla principal: ante
 * la duda, no nombrar ningún curso. Solo devuelve nombres oficiales (de las tarjetas o del catálogo), nunca texto del
 * visitante. Sin imports, para probarlo con `node --test`.
 */

export interface EntradaFoco {
  respuesta: string;
  /** Lo que escribió la persona: solo se usa para buscar nombres de cursos conocidos; no se guarda ni se envía. */
  pregunta: string;
  /** Curso de la barra, si la hay. */
  foco: string | null;
  /** Nombres oficiales vistos en tarjetas, el menú de orientación o el catálogo. */
  conocidos: readonly string[];
  tarjetas: readonly { tipo: string; titulo: string }[] | undefined;
}

export interface Foco {
  /** Curso que puede nombrar el mensaje de WhatsApp de esta respuesta. */
  curso: string | null;
  /** Curso que debe quedar en la barra. La barra nunca se crea sola: solo cambia o se quita. */
  foco: string | null;
}

const NADA: Foco = { curso: null, foco: null };

/** El asistente dice que no tiene ese curso (frase de Horus AI). */
const SIN_ESE_CURSO = 'un curso o capacitación sobre ese tema';

const palabras = (lista: string) => new Set(lista.split(' '));
/** Palabras que siguen a «curso de…» sin nombrar un tema. */
const NO_ES_TEMA = palabras(
  'este ese esto aquel mismo actual horus group ustedes linea persona grupo forma modalidad virtual presencial semipresencial hibrido distancia online verano invierno noche manana tarde sabado sabados domingo domingos mes ano semana',
);
/** Lo que puede acompañar a una petición de matrícula sin referirse a otro curso. */
const RELLENO_MATRICULA = palabras(
  'a al algun como cuales curso cursos de del deseo donde el en este ese esta favor gracias hacer hago hola la las los me mi mismo necesito o para pasos por puedo que quiero quisiera se si son un una y ya ahora hoy tambien capacitacion taller programa informacion sobre requisitos podria debo mas este esto aqui linea virtual presencial pronto manana',
);
const ES_MATRICULA = /^(matricul|inscri)/;

function normalizar(texto: string): string {
  return ` ${texto.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, ' ').trim()} `;
}

/** La pregunta, sin cursos conocidos, parece hablar de uno distinto al de la barra. Ante la duda, sí. */
function pideOtroCurso(dicho: string, foco: string): boolean {
  const delFoco = palabras(normalizar(foco).trim());
  const esOtro = (palabra: string) => !delFoco.has(palabra) && !NO_ES_TEMA.has(palabra);
  const nombrado = / (?:curso|taller|capacitacion|diplomado|seminario) (?:de|del|sobre) (?:(?:la|el|las|los) )?([a-z0-9]+) /.exec(dicho);
  if (nombrado !== null && esOtro(nombrado[1])) return true;
  const dichas = dicho.trim().split(' ');
  // En una petición de matrícula, cualquier palabra que no sea de relleno puede ser el nombre de otro curso.
  return dichas.some((p) => ES_MATRICULA.test(p)) && dichas.some((p) => !ES_MATRICULA.test(p) && !RELLENO_MATRICULA.has(p) && esOtro(p));
}

export function resolverFoco({ respuesta, pregunta, foco, conocidos, tarjetas }: EntradaFoco): Foco {
  const deTarjetas = (tarjetas ?? []).filter((t) => t.tipo === 'curso').map((t) => t.titulo);
  const oficiales = new Map<string, string>();
  for (const titulo of [...conocidos, ...deTarjetas, ...(foco ? [foco] : [])]) {
    if (!oficiales.has(normalizar(titulo))) oficiales.set(normalizar(titulo), titulo);
  }
  const con = (titulo: string): Foco => ({ curso: titulo, foco: foco === null ? null : titulo });

  // 1. Señales claras de la respuesta: «X»: …, «el curso «X»», la ficha «X: …» o una única tarjeta.
  const sujetos = new Set<string>();
  for (const m of respuesta.matchAll(/(?:^|\n)\s*«([^»\n]{1,120})»:|\bcurso «([^»\n]{1,120})»/g)) sujetos.add(normalizar(m[1] ?? m[2]));
  for (const [clave, titulo] of oficiales) {
    if (respuesta.split('\n').some((l) => l.trim().startsWith(`${titulo}: `))) sujetos.add(clave);
  }
  if (deTarjetas.length === 1) sujetos.add(normalizar(deTarjetas[0]));
  if (respuesta.includes(SIN_ESE_CURSO) || sujetos.size > 1 || deTarjetas.length > 1) return NADA;
  if (sujetos.size === 1) {
    const titulo = oficiales.get([...sujetos][0]);
    return titulo === undefined ? NADA : con(titulo);
  }
  // Una lista que nombra varios cursos sin ficha propia.
  if ([...oficiales.values()].filter((titulo) => respuesta.includes(titulo)).length > 1) return NADA;

  // 2. La respuesta no nombra cursos: se mira la pregunta.
  const dicho = normalizar(pregunta);
  const mencionados = [...oficiales].filter(([clave]) => dicho.includes(clave));
  if (mencionados.length === 0) return foco !== null && pideOtroCurso(dicho, foco) ? NADA : { curso: foco, foco };
  if (mencionados.length > 1) return NADA;
  const [clave, titulo] = mencionados[0];
  if (foco !== null && clave === normalizar(foco)) return { curso: foco, foco };
  const comoCurso = [` curso${clave}`, ` curso de${clave}`, ` capacitacion${clave}`, ` capacitacion de${clave}`].some((f) => dicho.includes(f));
  return comoCurso ? con(titulo) : NADA;
}
