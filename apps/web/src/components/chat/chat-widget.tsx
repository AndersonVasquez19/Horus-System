'use client';

import React, { useState, useEffect, useRef } from 'react';
import Link from 'next/link';
import Image from 'next/image';
import { usePathname } from 'next/navigation';
import {
  X,
  Send,
  GraduationCap,
  Laptop,
  MessageSquareMore,
  BookOpen,
  ArrowRight,
  Shield,
  RotateCcw,
  Sparkles,
  Compass,
  Maximize2,
  Minimize2,
  Copy,
  Check,
  ThumbsUp,
  ThumbsDown,
} from 'lucide-react';
import { RobotMascot } from './robot-mascot';
import { enlacesDeRespuesta, rutaNext, type EnlaceChat } from '@/lib/chat-rutas';
import { armarMenuOrientador, cursosValidos } from '@/lib/chat-orientador';
import { restaurarRespuestasRapidas, separarEtiquetas } from '@/lib/chat-formato';
import { urlWhatsappChat } from '@/lib/chat-whatsapp';
import { botonesDeSugerencias, sanearSugerencias } from '@/lib/chat-sugerencias';
import { TECNOLOGIAS_VISIBLES } from '@/lib/funciones';

type ChatLink = EnlaceChat;

interface ChatCard {
  id: string;
  tipo: 'servicio' | 'curso';
  titulo: string;
  descripcion: string;
  url: string;
  imagen: string | null;
  accion: string;
}

/** Respuestas del BFF (`/api/chat`): el navegador solo ve estos campos; nunca tokens ni errores internos. */
interface RespuestaApi {
  respuesta: string;
  tarjetas?: ChatCard[];
  enlaces?: string[];
  sugerencias?: string[];
  respuesta_id?: string;
}

const MAX_MENSAJE = 2000;
// Laravel corta la consulta a Horus AI en pocos segundos: 12 s cubren una respuesta lenta sin dejar a la persona
// mirando «Buscando…» medio minuto. Agotado el tiempo no se reintenta (la espera total sería el doble).
const TIMEOUT_MS = 12_000;
const ESPERA_REINTENTO_MS = 1200;
/** Botón de los mensajes propios del chat (menú, error o 👎): lleva el mensaje general. */
const CONTACTO_WHATSAPP: ChatLink = { url: urlWhatsappChat('', null), label: 'Contactar por WhatsApp', whatsapp: true };

const MENSAJE_NO_DISPONIBLE =
  'El asistente no está disponible en este momento. Puedes intentarlo de nuevo en unos minutos o escribirnos por WhatsApp.';
const MENSAJE_DEMASIADAS =
  'Estás enviando consultas muy seguido. Espera un momento antes de volver a escribir.';

let contadorId = 0;

/** Identificador local de mensaje (fuera del render para no mezclar efectos con la presentación). */
function idMensaje(prefijo: string): string {
  contadorId += 1;
  return `${prefijo}-${Date.now()}-${contadorId}`;
}

function nuevoSessionId(): string {
  try {
    return crypto.randomUUID();
  } catch {
    return `s${Date.now().toString(36)}${Math.random().toString(36).slice(2, 12)}`;
  }
}

/** Identificador de la conversación: vive solo en esta pestaña (sessionStorage) y se envía con cada consulta. */
function obtenerSessionId(): string {
  try {
    const guardado = sessionStorage.getItem('horus_chat_session');
    if (guardado && /^[A-Za-z0-9_-]{8,64}$/.test(guardado)) return guardado;
    const nuevo = nuevoSessionId();
    sessionStorage.setItem('horus_chat_session', nuevo);
    return nuevo;
  } catch {
    return nuevoSessionId();
  }
}

type ResultadoEnvio =
  | { ok: true; data: RespuestaApi }
  | { ok: false; motivo: 'demasiadas' | 'invalido' | 'no_disponible'; reintentable: boolean };

async function consultar(mensaje: string, sessionId: string): Promise<ResultadoEnvio> {
  const control = new AbortController();
  const temporizador = setTimeout(() => control.abort(), TIMEOUT_MS);
  try {
    const respuesta = await fetch('/api/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ mensaje, session_id: sessionId }),
      signal: control.signal,
    });
    if (respuesta.status === 429) return { ok: false, motivo: 'demasiadas', reintentable: false };
    if (respuesta.status === 422 || respuesta.status === 413) return { ok: false, motivo: 'invalido', reintentable: false };
    if (!respuesta.ok) return { ok: false, motivo: 'no_disponible', reintentable: respuesta.status >= 500 };
    const data: unknown = await respuesta.json();
    if (typeof data !== 'object' || data === null || typeof (data as RespuestaApi).respuesta !== 'string') {
      return { ok: false, motivo: 'no_disponible', reintentable: false };
    }
    return { ok: true, data: data as RespuestaApi };
  } catch {
    // Solo un fallo de red se reintenta; si se agotó el tiempo, el servidor ya tuvo su oportunidad.
    return { ok: false, motivo: 'no_disponible', reintentable: !control.signal.aborted };
  } finally {
    clearTimeout(temporizador);
  }
}

/** Datos para votar una respuesta: la referencia firmada por el servidor y la sesión que la recibió. */
interface ReferenciaVoto {
  referencia: string;
  sesion: string;
}

function restaurarReferencia(valor: unknown): ReferenciaVoto | null {
  const v = (valor ?? {}) as Record<string, unknown>;
  if (typeof v.referencia !== 'string' || !/^[1-9][0-9]{0,17}\.[a-f0-9]{64}$/.test(v.referencia)) return null;
  if (typeof v.sesion !== 'string' || !/^[A-Za-z0-9_-]{8,64}$/.test(v.sesion)) return null;
  return { referencia: v.referencia, sesion: v.sesion };
}

/** Historial guardado en la pestaña: puede haber sido alterado, así que se reconstruye solo con campos seguros. */
function restaurarMensajes(valor: unknown): Message[] {
  if (!Array.isArray(valor)) return [];
  const salida: Message[] = [];
  for (const m of valor.slice(-30)) {
    if (typeof m !== 'object' || m === null) continue;
    const c = m as Record<string, unknown>;
    if (typeof c.id !== 'string' || typeof c.text !== 'string' || (c.role !== 'user' && c.role !== 'bot')) continue;
    const mensaje: Message = { id: c.id.slice(0, 64), role: c.role, text: c.text.slice(0, 6000) };
    if (c.role === 'bot') {
      const guardadas = Array.isArray(c.links) ? c.links.map((l) => (l as Record<string, unknown> | null)?.url) : [];
      const curso = typeof c.curso === 'string' && c.curso.trim() !== '' ? c.curso.slice(0, 120) : null;
      if (curso !== null) mensaje.curso = curso;
      const enlaces = enlacesDeRespuesta(mensaje.text, guardadas, curso);
      if (enlaces.length > 0) mensaje.links = enlaces;
      if (Array.isArray(c.cards)) {
        const tarjetas: ChatCard[] = [];
        for (const t of c.cards.slice(0, 5)) {
          const x = (t ?? {}) as Record<string, unknown>;
          const url = rutaNext(x.url);
          if (url === null || typeof x.id !== 'string' || typeof x.titulo !== 'string' || typeof x.descripcion !== 'string' || typeof x.accion !== 'string') continue;
          if (x.tipo !== 'curso' && x.tipo !== 'servicio') continue;
          // La imagen no se restaura: solo se muestra la que acaba de validar el servidor.
          tarjetas.push({ id: x.id, tipo: x.tipo, titulo: x.titulo.slice(0, 120), descripcion: x.descripcion.slice(0, 500), url, imagen: null, accion: x.accion.slice(0, 60) });
        }
        if (tarjetas.length > 0) mensaje.cards = tarjetas;
      }
      const rapidas = restaurarRespuestasRapidas(c.quickReplies);
      if (rapidas.length > 0) mensaje.quickReplies = rapidas;
      const referencia = restaurarReferencia(c.voto);
      if (referencia !== null) mensaje.voto = referencia;
    }
    salida.push(mensaje);
  }
  return salida;
}

interface QuickReply {
  label: string;
  query: string;
}

interface Message {
  id: string;
  role: 'user' | 'bot';
  text: string;
  cards?: ChatCard[];
  links?: ChatLink[];
  quickReplies?: QuickReply[];
  /** Consulta que falló y que la persona puede reintentar. */
  retry?: string;
  voto?: ReferenciaVoto;
  /** Curso consultado en esta respuesta: se conserva para el mensaje de WhatsApp al restaurar la pestaña. */
  curso?: string;
}

const INITIAL_MESSAGE: Message = {
  id: 'init-1',
  role: 'bot',
  text: '¡Hola! Soy Horus, tu asistente virtual. ¿En qué te puedo ayudar hoy?',
};

function WhatsAppIcon({ className = 'w-4 h-4' }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="currentColor"
      aria-hidden="true"
    >
      <path d="M12.031 2C6.495 2 2 6.495 2 12.031a9.99 9.99 0 0 0 1.54 5.337L2 22l4.757-1.522a9.97 9.97 0 0 0 5.274 1.553h.005c5.536 0 10.031-4.495 10.031-10.031A10.005 10.005 0 0 0 12.031 2zm0 18.373a8.31 8.31 0 0 1-4.24-1.157l-.304-.18-3.15.99.98-3.054-.198-.315a8.307 8.307 0 0 1-1.272-4.626c0-4.604 3.748-8.352 8.352-8.352a8.307 8.307 0 0 1 5.906 2.447 8.307 8.307 0 0 1 2.446 5.905c0 4.604-3.748 8.337-8.17 8.337zm4.57-6.242c-.25-.125-1.48-.73-1.71-.812-.23-.083-.396-.125-.563.125-.166.25-.646.812-.792.98-.145.166-.291.187-.541.062s-1.057-.39-2.01-1.24c-.744-.664-1.247-1.485-1.393-1.735-.146-.25-.015-.385.11-.51.11-.11.25-.291.374-.437.125-.146.166-.25.25-.417.083-.166.042-.312-.02-.437-.063-.125-.563-1.354-.772-1.854-.203-.487-.41-.42-.563-.428l-.48-.01c-.166 0-.437.063-.666.312-.23.25-.875.854-.875 2.083s.896 2.417 1.02 2.583c.125.167 1.764 2.694 4.274 3.777.597.258 1.064.412 1.428.528.6.19 1.147.163 1.58.098.481-.072 1.48-.604 1.688-1.187.208-.583.208-1.083.146-1.187-.063-.105-.229-.167-.479-.292z" />
    </svg>
  );
}

function formatearLineaConBadges(linea: string): React.ReactNode[] {
  const patron = /(«[^»]+»|\b(?:Modalidad|Horario|Duración|Inicio|Certificación|Requisitos|Consultas):)/g;
  const partes = separarEtiquetas(linea).split(patron);
  return partes.map((parte, i) => {
    if (parte.startsWith('«') && parte.endsWith('»')) {
      return (
        <span key={i} className="hc-badge-course">
          {parte}
        </span>
      );
    }
    if (/^(?:Modalidad|Horario|Duración|Inicio|Certificación|Requisitos|Consultas):$/.test(parte)) {
      return (
        <span key={i} className="hc-badge-attr">
          {parte}
        </span>
      );
    }
    return <React.Fragment key={i}>{parte}</React.Fragment>;
  });
}

function limpiarUrlWhatsappVisible(text: string): string {
  return text
    .replace(/:\s*https:\/\/wa\.me\/[^\s]+(?=\.)/gi, '')
    .replace(/:\s*https:\/\/wa\.me\/[^\s]+/gi, '.')
    .replace(/\s*https:\/\/wa\.me\/[^\s]+/gi, '')
    .replace(/\.\./g, '.')
    .trim();
}

function renderMessageContent(text: string, role: 'user' | 'bot', tieneBotonWhatsapp = false): React.ReactNode {
  if (role === 'user') {
    return <p>{text}</p>;
  }
  const textoVisible = tieneBotonWhatsapp ? limpiarUrlWhatsappVisible(text) : text;
  const lineas = textoVisible
    .split('\n')
    .map((l) => l.trim())
    .filter((l) => l.length > 0);

  if (lineas.length <= 1) {
    return <p>{formatearLineaConBadges(textoVisible)}</p>;
  }

  return (
    <div className="hc-rich-body">
      {lineas.map((linea, idx) => {
        const esItem =
          /^(?:\d+[.)]\s+|[-•]\s+|«[^»]+»:)/.test(linea) ||
          (lineas.length >= 2 && /\b(?:Modalidad|Horario):/.test(linea));
        const numero = /^(\d+)[.)]\s+/.exec(linea)?.[1];
        const limpia = linea.replace(/^(?:\d+[.)]\s+|[-•]\s+)/, '');
        if (esItem) {
          return (
            <div key={idx} className="hc-rich-item">
              {numero ? <span className="hc-rich-number">{numero}.</span> : <span className="hc-rich-bullet" aria-hidden="true" />}
              <div>{formatearLineaConBadges(limpia)}</div>
            </div>
          );
        }
        return (
          <p key={idx} className="hc-rich-line">
            {formatearLineaConBadges(linea)}
          </p>
        );
      })}
    </div>
  );
}

function generarChipsSeguimiento(
  respuesta: string,
  tarjetas: ChatCard[] | undefined,
  cursoActual: string | null
): QuickReply[] | undefined {
  const norm = respuesta
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '');

  if (cursoActual) {
    const chips: QuickReply[] = [];
    if (!norm.includes('horario')) {
      chips.push({ label: 'Ver horario', query: `¿Cuál es el horario del curso ${cursoActual}?` });
    }
    if (!norm.includes('modalidad') && !norm.includes('es virtual') && !norm.includes('es hibrido') && !norm.includes('es presencial')) {
      chips.push({ label: 'Ver modalidad', query: `¿Cuál es la modalidad del curso ${cursoActual}?` });
    }
    if (!norm.includes('whatsapp') && !norm.includes('matricular')) {
      chips.push({ label: 'Cómo matricularme', query: `Quiero matricularme en el curso ${cursoActual}` });
    }
    chips.push({ label: 'Comparar todos los cursos', query: 'Compárame los cursos disponibles' });
    return chips.slice(0, 3);
  }

  if (
    (tarjetas && tarjetas.some((t) => t.tipo === 'curso')) ||
    norm.includes('cursos y capacitaciones vigentes')
  ) {
    const chips: QuickReply[] = [];
    if (!norm.includes('horario')) {
      chips.push({ label: 'Horarios de los cursos', query: 'Los horarios de los cursos' });
    }
    if (!norm.includes('modalidad')) {
      chips.push({ label: 'Modalidades disponibles', query: '¿Qué modalidad tienen los cursos?' });
    }
    if (norm.includes('horario')) {
      chips.push({ label: 'Comparar detalles completos', query: 'Dame más detalles de los cursos' });
    }
    chips.push({ label: 'Cómo matricularme', query: '¿Cómo puedo matricularme?' });
    return chips.slice(0, 3);
  }

  return undefined;
}

export function ChatWidget() {
  const pathname = usePathname();
  const [isOpen, setIsOpen] = useState(false);
  const [isExpanded, setIsExpanded] = useState(false);
  const [messages, setMessages] = useState<Message[]>([INITIAL_MESSAGE]);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [cursoEnFoco, setCursoEnFoco] = useState<string | null>(null);
  const [copiedId, setCopiedId] = useState<string | null>(null);
  const [feedbackByMsg, setFeedbackByMsg] = useState<Record<string, 'up' | 'down'>>({});
  const [isOffline, setIsOffline] = useState(false);
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const launcherRef = useRef<HTMLButtonElement>(null);
  const cursoEnFocoRef = useRef<string | null>(null);
  const lastSendAtRef = useRef<number>(0);

  const actualizarCursoEnFoco = (titulo: string | null) => {
    cursoEnFocoRef.current = titulo;
    setCursoEnFoco(titulo);
  };

  useEffect(() => {
    let cancelado = false;
    const syncOnline = () => setIsOffline(!navigator.onLine);
    queueMicrotask(() => {
      if (cancelado) return;
      setIsOffline(!navigator.onLine);
    });
    window.addEventListener('online', syncOnline);
    window.addEventListener('offline', syncOnline);
    return () => {
      cancelado = true;
      window.removeEventListener('online', syncOnline);
      window.removeEventListener('offline', syncOnline);
    };
  }, []);

  // Restore history from sessionStorage. Se hace después del montaje (no en el cuerpo síncrono del efecto) para que el HTML del
  // servidor y el primer render del cliente coincidan; el resultado para la persona es el mismo.
  useEffect(() => {
    let cancelado = false;
    queueMicrotask(() => {
      if (cancelado) return;
      try {
        const stored = sessionStorage.getItem('horus_chat_history');
        if (stored) {
          const restaurados = restaurarMensajes(JSON.parse(stored));
          if (restaurados.length > 0) {
            setMessages(restaurados);
          }
        }
      } catch {
        // ignore storage errors
      }
    });
    return () => {
      cancelado = true;
    };
  }, []);

  // Save history to sessionStorage
  useEffect(() => {
    try {
      if (messages.length > 1) {
        sessionStorage.setItem('horus_chat_history', JSON.stringify(messages.filter((m) => !m.retry).slice(-30)));
      }
    } catch {
      // ignore storage errors
    }
  }, [messages]);

  // Desplazamiento: una respuesta nueva del asistente se muestra desde su inicio (en las largas, ir al final obligaba a
  // subir para leerla); el mensaje propio y el indicador «Buscando…» se muestran al final.
  useEffect(() => {
    if (!isOpen) return;
    const ultimo = messages[messages.length - 1];
    if (!sending && ultimo?.role === 'bot' && messages.length > 1) {
      document.getElementById(`hc-msg-${ultimo.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages, sending, isOpen]);

  // Al terminar una respuesta el foco vuelve al campo: se puede seguir escribiendo sin hacer clic.
  const estabaEnviandoRef = useRef(false);
  useEffect(() => {
    if (estabaEnviandoRef.current && !sending && isOpen) {
      textareaRef.current?.focus();
    }
    estabaEnviandoRef.current = sending;
  }, [sending, isOpen]);

  // Handle focus when opening
  useEffect(() => {
    if (isOpen) {
      const timer = setTimeout(() => {
        textareaRef.current?.focus();
      }, 100);
      return () => clearTimeout(timer);
    }
  }, [isOpen]);

  // Listen to Escape key & custom 'horus-cotizacion' event
  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape' && isOpen) {
        setIsOpen(false);
        launcherRef.current?.focus();
      }
    }

    function handleCotizacion(e: Event) {
      const detail = (e as CustomEvent).detail;
      const servicio = typeof detail?.servicio === 'string' ? detail.servicio : 'nuestros servicios';
      setIsOpen(true);
      const userText = `Deseo solicitar una cotización de ${servicio}`;
      const botResponse: Message = {
        id: `cotiz-${Date.now()}`,
        role: 'bot',
        text: `Para solicitar una cotización formal y personalizada de ${servicio}, continúa directamente por WhatsApp con uno de nuestros asesores para coordinar los detalles.`,
        links: [
          {
            url: `https://wa.me/51972021374?text=${encodeURIComponent(
              `Hola, deseo solicitar una cotización de ${servicio}`
            )}`,
            label: 'Solicitar cotización por WhatsApp',
            whatsapp: true,
          },
        ],
      };

      setMessages((prev) => [
        ...prev,
        { id: `usr-${Date.now()}`, role: 'user', text: userText },
        botResponse,
      ]);
    }

    function handleConsultarCurso(e: Event) {
      const detail = (e as CustomEvent).detail;
      const titulo = typeof detail?.titulo === 'string' ? detail.titulo.trim() : '';
      if (!titulo) return;
      actualizarCursoEnFoco(titulo);
      setIsOpen(true);

      const botIntro: Message = {
        id: idMensaje('curso'),
        role: 'bot',
        text: `¡Hola! Veo que te interesa el curso «${titulo}» o quieres más información. ¿Qué es lo que quieres saber según el horario, modalidad, fecha de inicio, duración, certificación o cómo matricularte?`,
        quickReplies: [
          { label: 'Horario y fecha de inicio', query: `¿Cuál es el horario del curso ${titulo}?` },
          { label: 'Modalidad del curso', query: `¿Cuál es la modalidad del curso ${titulo}?` },
          { label: 'Más detalles del curso', query: `Dame detalles del curso ${titulo}` },
          { label: 'Cómo matricularme', query: `Quiero matricularme en el curso ${titulo}` },
        ],
      };

      setMessages((prev) => [...prev, botIntro]);
    }

    window.addEventListener('keydown', handleKeyDown);
    window.addEventListener('horus-cotizacion', handleCotizacion);
    window.addEventListener('horus-consultar-curso', handleConsultarCurso);
    return () => {
      window.removeEventListener('keydown', handleKeyDown);
      window.removeEventListener('horus-cotizacion', handleCotizacion);
      window.removeEventListener('horus-consultar-curso', handleConsultarCurso);
    };
  }, [isOpen]);

  const sessionIdRef = useRef<string | null>(null);
  /** Sube con cada reinicio: una respuesta que llega tarde a una conversación ya reiniciada se descarta. */
  const generacionRef = useRef(0);

  async function consultarConReintento(text: string): Promise<ResultadoEnvio> {
    sessionIdRef.current ??= obtenerSessionId();
    let resultado = await consultar(text, sessionIdRef.current);
    // Un único reintento automático ante un fallo de conexión o del servidor; el servidor deduplica el mismo mensaje.
    if (!resultado.ok && resultado.reintentable) {
      await new Promise((r) => setTimeout(r, ESPERA_REINTENTO_MS));
      resultado = await consultar(text, sessionIdRef.current);
    }
    return resultado;
  }

  const armarRespuesta = (resultado: ResultadoEnvio, text: string): Message => {
    if (!resultado.ok) {
      if (resultado.motivo === 'demasiadas') return { id: idMensaje('err'), role: 'bot', text: MENSAJE_DEMASIADAS };
      if (resultado.motivo === 'invalido') {
        return { id: idMensaje('err'), role: 'bot', text: 'No pude procesar ese mensaje. Revisa que no esté vacío ni supere los 2000 caracteres.' };
      }
      return { id: idMensaje('err'), role: 'bot', text: MENSAJE_NO_DISPONIBLE, links: [CONTACTO_WHATSAPP], retry: text };
    }
    const cursoContexto =
      cursoEnFocoRef.current ??
      (resultado.data.tarjetas && resultado.data.tarjetas.length === 1 && resultado.data.tarjetas[0].tipo === 'curso'
        ? resultado.data.tarjetas[0].titulo
        : null);
    const enlaces = enlacesDeRespuesta(resultado.data.respuesta, resultado.data.enlaces, cursoContexto);
    const botonesSugeridos = botonesDeSugerencias(sanearSugerencias(resultado.data.sugerencias));
    const referencia = restaurarReferencia({ referencia: resultado.data.respuesta_id, sesion: sessionIdRef.current });
    return {
      id: idMensaje('bot'),
      role: 'bot',
      voto: referencia ?? undefined,
      curso: cursoContexto ?? undefined,
      text: resultado.data.respuesta,
      cards: resultado.data.tarjetas,
      links: enlaces.length > 0 ? enlaces : undefined,
      // El servidor decide los botones (claves cerradas); el cálculo por texto queda solo como respaldo.
      quickReplies: botonesSugeridos.length > 0
        ? botonesSugeridos
        : generarChipsSeguimiento(resultado.data.respuesta, resultado.data.tarjetas, cursoEnFocoRef.current),
    };
  };

  const enviarConsulta = async (text: string) => {
    if (typeof navigator !== 'undefined' && !navigator.onLine) {
      setMessages((prev) => [
        ...prev,
        {
          id: idMensaje('offline'),
          role: 'bot',
          text: 'Parece que no tienes conexión a internet en este momento. Verifica tu red y vuelve a intentarlo.',
          retry: text,
        },
      ]);
      return;
    }
    setSending(true);
    const generacion = generacionRef.current;
    try {
      // El foco de la conversación lo lleva el servidor (estado firmado): el cliente ya no reescribe la consulta.
      const resultado = await consultarConReintento(text);
      // Si la persona reinició la conversación mientras esperaba, la respuesta vieja ya no corresponde.
      if (generacion !== generacionRef.current) return;
      const botMsg = armarRespuesta(resultado, text);
      setMessages((prev) => [...prev, botMsg]);
    } catch {
      if (generacion === generacionRef.current) {
        setMessages((prev) => [
          ...prev,
          { id: idMensaje('err'), role: 'bot', text: MENSAJE_NO_DISPONIBLE, links: [CONTACTO_WHATSAPP], retry: text },
        ]);
      }
    } finally {
      // Siempre se libera el campo, aunque algo falle al armar la respuesta.
      setSending(false);
    }
  };

  /** Sesión nueva: el servidor olvida el curso en foco y el resto del estado de la conversación anterior. */
  const rotarSesion = () => {
    try {
      const nuevo = nuevoSessionId();
      sessionStorage.setItem('horus_chat_session', nuevo);
      sessionIdRef.current = nuevo;
    } catch {
      sessionIdRef.current = nuevoSessionId();
    }
  };

  const handleResetConversation = () => {
    if (sending) return;
    generacionRef.current += 1; // descarta un menú del orientador que aún esté llegando
    actualizarCursoEnFoco(null);
    setMessages([INITIAL_MESSAGE]);
    setDraft('');
    setFeedbackByMsg({});
    if (textareaRef.current) {
      textareaRef.current.style.height = 'auto';
    }
    try {
      sessionStorage.removeItem('horus_chat_history');
    } catch {
      // ignore storage errors
    }
    rotarSesion();
  };

  /** «Ver todos»: además de la barra, el servidor deja de responder sobre el curso anterior. */
  const handleVerTodos = () => {
    actualizarCursoEnFoco(null);
    rotarSesion();
  };

  const handleCopyMessage = (messageId: string, text: string) => {
    const limpio = limpiarUrlWhatsappVisible(text);
    if (!navigator.clipboard) return;
    navigator.clipboard
      .writeText(limpio)
      .then(() => {
        setCopiedId(messageId);
        setTimeout(() => {
          setCopiedId((curr) => (curr === messageId ? null : curr));
        }, 1800);
      })
      .catch(() => {
        // Sin permiso para el portapapeles (navegador o iframe): no hay nada que copiar ni que mostrar.
      });
  };

  /**
   * Anota que se pulsó un botón de WhatsApp, sin frenar la apertura. Los botones que no vienen de una respuesta del
   * servidor (el del 👎, el del orientador) se atribuyen a la última respuesta; sin ninguna, no se registra.
   */
  const registrarClicWhatsapp = (message: Message) => {
    const origen = message.voto ?? [...messages].reverse().find((m) => m.voto)?.voto;
    if (!origen) return;
    void fetch('/api/chat/contacto', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ session_id: origen.sesion, respuesta_id: origen.referencia }),
      keepalive: true,
    }).catch(() => {});
  };

  const handleFeedback = (messageId: string, value: 'up' | 'down') => {
    // Un voto por respuesta: repetir el clic no vuelve a enviar el mensaje de ayuda.
    if (feedbackByMsg[messageId]) return;
    setFeedbackByMsg((prev) => ({ ...prev, [messageId]: value }));
    const voto = messages.find((m) => m.id === messageId)?.voto;
    if (voto) {
      // Sin esperar ni avisar: si no se puede guardar, la conversación sigue igual.
      void fetch('/api/chat/voto', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: voto.sesion, respuesta_id: voto.referencia, voto: value }),
        keepalive: true,
      }).catch(() => {});
    }
    if (value === 'down') {
      setMessages((prev) => [
        ...prev,
        {
          id: idMensaje('fb'),
          role: 'bot',
          text: 'Gracias por avisarme. Si prefieres atención directa o resolver una duda específica, puedes escribirnos por WhatsApp o explorar los cursos vigentes:',
          links: [CONTACTO_WHATSAPP],
          quickReplies: [
            { label: 'Ver todos los cursos', query: '¿Qué cursos ofrecen?' },
            { label: 'Horarios de los cursos', query: 'Los horarios de los cursos' },
          ],
        },
      ]);
    }
  };

  const handleConsultarDesdeTarjeta = (titulo: string) => {
    actualizarCursoEnFoco(titulo);
    setMessages((prev) => [
      ...prev,
      {
        id: idMensaje('card-focus'),
        role: 'bot',
        text: `Perfecto, nos enfocamos en el curso «${titulo}». ¿Qué deseas consultar ahora?`,
        quickReplies: [
          { label: 'Horario y fecha de inicio', query: `¿Cuál es el horario del curso ${titulo}?` },
          { label: 'Modalidad del curso', query: `¿Cuál es la modalidad del curso ${titulo}?` },
          { label: 'Más detalles', query: `Dame detalles del curso ${titulo}` },
          { label: 'Cómo matricularme', query: `Quiero matricularme en el curso ${titulo}` },
        ],
      },
    ]);
  };

  const handleIniciarOrientador = async () => {
    if (sending) return;
    // Mientras llega el menú la conversación queda ocupada (indicador «Buscando…» y campo en solo lectura): así una
    // pregunta escrita en ese momento no queda por encima del menú, que llegaría después.
    setSending(true);
    const generacion = generacionRef.current;
    actualizarCursoEnFoco(null);
    const userMsg: Message = {
      id: idMensaje('user'),
      role: 'user',
      text: 'Ayúdame a elegir un curso',
    };
    setMessages((prev) => [...prev, userMsg]);

    // Los botones salen de los cursos publicados en este momento; si no se pueden consultar, el menú lo dice.
    let cursos: ReturnType<typeof cursosValidos> | null = null;
    try {
      const respuesta = await fetch('/api/chat/orientador', { cache: 'no-store', signal: AbortSignal.timeout(8000) });
      if (respuesta.ok) {
        const cuerpo = (await respuesta.json()) as { cursos?: unknown };
        cursos = cuerpo.cursos === null || cuerpo.cursos === undefined ? null : cursosValidos(cuerpo.cursos);
      }
    } catch {
      cursos = null;
    }

    setSending(false);
    if (generacion !== generacionRef.current) return;
    const menu = armarMenuOrientador(cursos);
    const orientadorMsg: Message = {
      id: idMensaje('orientador'),
      role: 'bot',
      text: menu.texto,
      quickReplies: menu.opciones,
      links: menu.conWhatsapp ? [CONTACTO_WHATSAPP] : undefined,
    };
    setMessages((prev) => [...prev, orientadorMsg]);
  };

  const handleSend = (textToSend?: string) => {
    const text = (textToSend || draft).trim().slice(0, MAX_MENSAJE);
    if (!text || sending || lastSendAtRef.current !== 0) return;
    lastSendAtRef.current = 1;
    setTimeout(() => {
      lastSendAtRef.current = 0;
    }, 350);

    setMessages((prev) => [...prev, { id: idMensaje('user'), role: 'user', text }]);
    setDraft('');
    if (textareaRef.current) {
      textareaRef.current.style.height = 'auto';
    }
    void enviarConsulta(text);
  };

  const handleRetry = (messageId: string, text: string) => {
    if (sending) return;
    setMessages((prev) => prev.filter((m) => m.id !== messageId));
    void enviarConsulta(text);
  };

  const handleSuggestionClick = (query: string) => {
    if (query === '__orientador__') {
      void handleIniciarOrientador();
      return;
    }
    handleSend(query);
  };

  const sugerenciasIniciales: QuickReply[] = React.useMemo(() => {
    if (pathname?.startsWith('/educacion/cursos')) {
      return [
        { label: 'Ayúdame a elegir un curso', query: '__orientador__' },
        { label: 'Comparar detalles de los cursos', query: 'Compárame los cursos disponibles' },
        { label: 'Ver horarios de los cursos', query: 'Los horarios de los cursos' },
        { label: 'Cómo matricularme', query: '¿Cómo puedo matricularme?' },
      ];
    }
    if (pathname?.startsWith('/educacion/asesoramiento')) {
      return [
        { label: '¿Cómo funciona la asesoría y tesis?', query: '¿En qué consiste el servicio de asesoramiento?' },
        { label: 'Áreas de asesoramiento', query: '¿En qué áreas brindan asesoramiento?' },
        { label: 'Ayúdame a elegir un curso', query: '__orientador__' },
        { label: 'Contactar con un asesor', query: '¿Cómo me comunico con un asesor?' },
      ];
    }
    if (pathname?.startsWith('/educacion/capacitaciones')) {
      return [
        { label: 'Capacitaciones para empresas', query: '¿Qué capacitaciones tienen para empresas?' },
        { label: 'Convenios institucionales', query: '¿Con qué instituciones tienen convenios?' },
        { label: 'Ayúdame a elegir un curso', query: '__orientador__' },
        { label: 'Explorar cursos vigentes', query: '¿Qué cursos ofrecen?' },
      ];
    }
    if (pathname?.startsWith('/contactos')) {
      return [
        { label: 'Ubicación y dirección en Cajamarca', query: '¿Dónde están ubicados?' },
        { label: 'Medios de pago (BCP, BBVA, BN)', query: '¿Cuáles son los medios de pago?' },
        { label: 'Ayúdame a elegir un curso', query: '__orientador__' },
        { label: 'Horarios de los cursos', query: 'Los horarios de los cursos' },
      ];
    }
    return [
      { label: 'Ayúdame a elegir un curso', query: '__orientador__' },
      { label: 'Explorar cursos y capacitaciones', query: '¿Qué capacitaciones ofrecen?' },
      { label: 'Ver horarios de los cursos', query: 'Los horarios de los cursos' },
      { label: 'Consultar matrícula', query: '¿Cómo puedo matricularme?' },
    ];
  }, [pathname]);

  // Solo los botones del último mensaje del asistente siguen activos: los anteriores cambiarían el contexto sin avisar.
  const idUltimoBot = [...messages].reverse().find((m) => m.role === 'bot')?.id;
  const anuncio = (() => {
    const ultimo = [...messages].reverse().find((m) => m.role === 'bot');
    return ultimo && ultimo.id !== 'init-1' ? ultimo.text.slice(0, 400) : '';
  })();

  // Mantiene el foco dentro del diálogo mientras está abierto (Tab y Mays+Tab dan la vuelta).
  const mantenerFoco = (e: React.KeyboardEvent<HTMLElement>) => {
    if (e.key !== 'Tab') return;
    const foco = Array.from(
      e.currentTarget.querySelectorAll<HTMLElement>('button:not([disabled]), a[href], textarea:not([disabled]), input:not([disabled]), [tabindex="0"]')
    ).filter((el) => el.offsetParent !== null);
    if (foco.length === 0) return;
    const primero = foco[0];
    const ultimo = foco[foco.length - 1];
    const activo = document.activeElement;
    if (e.shiftKey && activo === primero) {
      e.preventDefault();
      ultimo.focus();
    } else if (!e.shiftKey && activo === ultimo) {
      e.preventDefault();
      primero.focus();
    }
  };

  return (
    <div className="horus-chat-widget">
      {/* Launcher Button with Robot Mascot */}
      <button
        ref={launcherRef}
        type="button"
        onClick={() => setIsOpen(true)}
        aria-expanded={isOpen}
        aria-label="Abrir chat con el asistente Horus"
        aria-haspopup="dialog"
        aria-controls="horus-chat-panel"
        className="hc-launcher"
      >
        <span className="hc-launcher-hint" aria-hidden="true">
          ¿En qué te ayudo?
        </span>
        <RobotMascot portrait className="hc-launcher-robot" />
        <span className="hc-launcher-badge" aria-hidden="true">
          <MessageSquareMore className="w-4 h-4" />
        </span>
      </button>

      {/* Chat Dialog Panel */}
      {isOpen && (
        <section
          id="horus-chat-panel"
          className={`hc-dialog animate-in fade-in zoom-in-95 ${isExpanded ? 'hc-dialog-expanded' : ''}`}
          role="dialog"
          aria-modal="true"
          aria-labelledby="hc-title"
          onKeyDown={mantenerFoco}
        >
          <div className="hc-shell">
            {/* Header */}
            <header className="hc-header">
              <span className="hc-mark">
                <RobotMascot portrait className="hc-robot-portrait" />
              </span>
              <div className="hc-header-copy">
                <span className="hc-header-brand">HORUS GROUP</span>
                <h2 id="hc-title">Asistente Horus</h2>
                <p>{TECNOLOGIAS_VISIBLES ? 'Cursos, tecnología y orientación' : 'Cursos y orientación'}</p>
              </div>
              <div className="hc-header-actions">
                <button
                  type="button"
                  onClick={() => setIsExpanded((v) => !v)}
                  className="hc-reset-btn hidden sm:grid"
                  title={isExpanded ? 'Vista compacta' : 'Ampliar chat'}
                  aria-label={isExpanded ? 'Reducir ancho del chat' : 'Ampliar ancho del chat'}
                >
                  {isExpanded ? (
                    <Minimize2 className="w-4 h-4" aria-hidden="true" />
                  ) : (
                    <Maximize2 className="w-4 h-4" aria-hidden="true" />
                  )}
                </button>
                {messages.length > 1 && (
                  <button
                    type="button"
                    onClick={handleResetConversation}
                    disabled={sending}
                    className="hc-reset-btn"
                    title="Reiniciar conversación"
                    aria-label="Reiniciar conversación"
                  >
                    <RotateCcw className="w-4 h-4" aria-hidden="true" />
                  </button>
                )}
                <button
                  type="button"
                  onClick={() => {
                    setIsOpen(false);
                    launcherRef.current?.focus(); // como con Escape: el foco vuelve al botón que abrió el chat
                  }}
                  className="hc-close"
                  aria-label="Cerrar chat"
                >
                  <X className="w-5 h-5" aria-hidden="true" />
                </button>
              </div>
            </header>

            {/* Subheader Toolbar */}
            <div className="hc-toolbar">
              <span>
                <i
                  aria-hidden="true"
                  style={isOffline ? { background: '#ef4444', boxShadow: '0 0 0 3px rgba(239, 68, 68, 0.2)' } : undefined}
                />
                {isOffline ? 'Sin conexión a internet' : 'Información del catálogo'}
              </span>
              <span className="hc-toolbar-caption">Asistente virtual</span>
            </div>

            {/* Active Course Focus Bar */}
            {cursoEnFoco && (
              <div className="hc-focus-bar" role="status" aria-live="polite">
                <span className="hc-focus-bar-label">
                  <Sparkles className="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
                  <span className="truncate">Consultando: «{cursoEnFoco}»</span>
                </span>
                <button
                  type="button"
                  className="hc-focus-bar-clear"
                  onClick={handleVerTodos}
                  aria-label="Salir del curso actual y consultar todos los cursos"
                >
                  <span>Ver todos</span>
                  <X className="w-3 h-3" aria-hidden="true" />
                </button>
              </div>
            )}

            {/* Conversation Area */}
            <div className="hc-conversation">
              <div
                className="hc-log"
                role="region"
                tabIndex={0}
                aria-label="Conversación con Horus"
              >
                {messages.map((message) => (
                  <article
                    key={message.id}
                    id={`hc-msg-${message.id}`}
                    className={`hc-message ${
                      message.role === 'user' ? 'hc-user' : 'hc-assistant'
                    } ${message.cards && message.cards.length > 0 ? 'hc-message-with-cards' : ''}`}
                  >
                    <strong>
                      {message.role === 'bot' && (
                        <RobotMascot portrait className="hc-message-avatar" />
                      )}
                      <span>{message.role === 'user' ? 'Tú' : 'Horus'}</span>
                    </strong>
                    {renderMessageContent(
                      message.text,
                      message.role,
                      Boolean(message.links?.some((l) => l.whatsapp))
                    )}

                    {/* Rich Cards */}
                    {message.cards && message.cards.length > 0 && (
                      <div className="hc-cards mt-3" aria-label="Contenidos relacionados">
                        {message.cards.map((card) => (
                          <section key={card.id} className="hc-card" aria-label={card.titulo}>
                            <div
                              className={`hc-card-cover ${
                                card.tipo === 'servicio' ? 'hc-card-cover-service' : ''
                              }`}
                            >
                              {card.imagen ? (
                                <Image
                                  src={card.imagen}
                                  alt={card.titulo}
                                  fill
                                  unoptimized
                                  sizes="(max-width: 480px) 100vw, 400px"
                                  className="object-cover"
                                />
                              ) : (
                                <span className="hc-card-placeholder" aria-hidden="true">
                                  {card.tipo === 'servicio' ? (
                                    <Laptop className="w-7 h-7" />
                                  ) : (
                                    <BookOpen className="w-7 h-7" />
                                  )}
                                </span>
                              )}
                            </div>
                            <div className="hc-card-body">
                              <span className="hc-card-kind">
                                {card.tipo === 'servicio'
                                  ? 'Servicio tecnológico'
                                  : 'Curso y capacitación'}
                              </span>
                              <h3>{card.titulo}</h3>
                              <p>{card.descripcion}</p>
                              {card.tipo === 'curso' ? (
                                <div className="hc-card-actions">
                                  <button
                                    type="button"
                                    className="hc-card-action-secondary"
                                    onClick={() => handleConsultarDesdeTarjeta(card.titulo)}
                                  >
                                    <span>Consultar aquí</span>
                                  </button>
                                  {card.url && (
                                    <Link
                                      href={card.url}
                                      onClick={() => setIsOpen(false)}
                                      className="hc-card-action"
                                      aria-label={`${card.accion}: ${card.titulo}`}
                                    >
                                      <span>{card.accion}</span>
                                      <ArrowRight className="w-4 h-4" aria-hidden="true" />
                                    </Link>
                                  )}
                                </div>
                              ) : (
                                card.url && (
                                  <Link
                                    href={card.url}
                                    onClick={() => setIsOpen(false)}
                                    className="hc-card-action"
                                    aria-label={`${card.accion}: ${card.titulo}`}
                                  >
                                    <span>{card.accion}</span>
                                    <ArrowRight className="w-4 h-4" aria-hidden="true" />
                                  </Link>
                                )
                              )}
                            </div>
                          </section>
                        ))}
                      </div>
                    )}

                    {message.retry && (
                      <div className="mt-2.5 flex flex-wrap gap-2">
                        <button
                          type="button"
                          className="hc-action"
                          onClick={() => handleRetry(message.id, message.retry as string)}
                          disabled={sending}
                        >
                          <span>Reintentar</span>
                        </button>
                      </div>
                    )}

                    {/* Action Links */}
                    {message.links && message.links.length > 0 && (
                      <div className="mt-2.5 flex flex-wrap gap-2">
                        {message.links.map((link, idx) =>
                          link.whatsapp ? (
                            <a
                              key={idx}
                              className="hc-action hc-action-whatsapp"
                              href={link.url}
                              target="_blank"
                              rel="noopener noreferrer"
                              onClick={() => registrarClicWhatsapp(message)}
                            >
                              <WhatsAppIcon className="w-4 h-4 shrink-0" />
                              <span>{link.label}</span>
                            </a>
                          ) : (
                            <Link
                              key={idx}
                              className="hc-action"
                              href={link.url}
                              onClick={() => setIsOpen(false)}
                            >
                              <span>{link.label}</span>
                            </Link>
                          )
                        )}
                      </div>
                    )}

                    {/* Quick Replies / Follow-up Chips */}
                    {message.quickReplies && message.quickReplies.length > 0 && (
                      <div className="hc-followup-chips" role="group" aria-label="Consultas rápidas sugeridas">
                        {message.quickReplies.map((qr, idx) => (
                          <button
                            key={idx}
                            type="button"
                            className="hc-followup-chip"
                            disabled={sending || message.id !== idUltimoBot}
                            onClick={() => handleSuggestionClick(qr.query)}
                          >
                            <span>{qr.label}</span>
                            <ArrowRight className="w-3.5 h-3.5 opacity-70" aria-hidden="true" />
                          </button>
                        ))}
                      </div>
                    )}

                    {/* Copy & Quick Feedback on Bot Replies */}
                    {message.role === 'bot' && message.id !== 'init-1' && !message.retry && (
                      <div
                        className="hc-msg-tools mt-3 pt-2.5 border-t border-slate-100 flex items-center justify-between gap-2"
                        aria-label="Acciones sobre la respuesta"
                      >
                        <button
                          type="button"
                          className="inline-flex items-center gap-1.5 px-3 min-h-11 rounded-lg border border-slate-200/80 bg-slate-50 text-xs font-semibold text-slate-600 hover:bg-blue-50 hover:text-blue-700 hover:border-blue-200 transition-colors cursor-pointer"
                          onClick={() => handleCopyMessage(message.id, message.text)}
                          aria-label="Copiar respuesta"
                        >
                          {copiedId === message.id ? (
                            <>
                              <Check className="w-3.5 h-3.5 text-emerald-600 shrink-0" aria-hidden="true" />
                              <span className="text-emerald-700">Copiado</span>
                            </>
                          ) : (
                            <>
                              <Copy className="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
                              <span>Copiar</span>
                            </>
                          )}
                        </button>
                        <div className="flex items-center gap-1.5">
                          <button
                            type="button"
                            className={`inline-flex items-center justify-center w-11 h-11 rounded-lg border transition-colors cursor-pointer ${
                              feedbackByMsg[message.id] === 'up'
                                ? 'bg-blue-50 border-blue-300 text-blue-700'
                                : 'border-slate-200/80 bg-slate-50 text-slate-500 hover:bg-blue-50 hover:text-blue-700 hover:border-blue-200'
                            }`}
                            onClick={() => handleFeedback(message.id, 'up')}
                            aria-pressed={feedbackByMsg[message.id] === 'up'}
                            aria-label="Respuesta útil"
                            title="Útil"
                          >
                            <ThumbsUp className="w-3.5 h-3.5" aria-hidden="true" />
                          </button>
                          <button
                            type="button"
                            className={`inline-flex items-center justify-center w-11 h-11 rounded-lg border transition-colors cursor-pointer ${
                              feedbackByMsg[message.id] === 'down'
                                ? 'bg-amber-50 border-amber-300 text-amber-700'
                                : 'border-slate-200/80 bg-slate-50 text-slate-500 hover:bg-amber-50 hover:text-amber-700 hover:border-amber-200'
                            }`}
                            onClick={() => handleFeedback(message.id, 'down')}
                            aria-pressed={feedbackByMsg[message.id] === 'down'}
                            aria-label="Necesito más ayuda"
                            title="No resolvió mi duda"
                          >
                            <ThumbsDown className="w-3.5 h-3.5" aria-hidden="true" />
                          </button>
                        </div>
                      </div>
                    )}
                  </article>
                ))}

                {/* Suggestions pill group (shown when conversation just started) */}
                {messages.length === 1 && messages[0].role === 'bot' && (
                  <div className="hc-suggestions" aria-label="Preguntas sugeridas">
                    <p>Puedes empezar por aquí</p>
                    <div>
                      {sugerenciasIniciales.map((sug, idx) => (
                        <button
                          key={idx}
                          type="button"
                          disabled={sending}
                          onClick={() => handleSuggestionClick(sug.query)}
                        >
                          <span className="icon-wrap">
                            {sug.query === '__orientador__' ? (
                              <Compass className="w-4 h-4" aria-hidden="true" />
                            ) : idx === 1 ? (
                              <GraduationCap className="w-4 h-4" aria-hidden="true" />
                            ) : (
                              <BookOpen className="w-4 h-4" aria-hidden="true" />
                            )}
                          </span>
                          <span>{sug.label}</span>
                          <ArrowRight className="arrow-icon" aria-hidden="true" />
                        </button>
                      ))}
                      {TECNOLOGIAS_VISIBLES && (
                        <button
                          type="button"
                          disabled={sending}
                          onClick={() =>
                            handleSuggestionClick('¿Qué servicios tecnológicos brindan?')
                          }
                        >
                          <span className="icon-wrap">
                            <Laptop className="w-4 h-4" aria-hidden="true" />
                          </span>
                          <span>Servicios tecnológicos</span>
                          <ArrowRight className="arrow-icon" aria-hidden="true" />
                        </button>
                      )}
                      {TECNOLOGIAS_VISIBLES && (
                        <button
                          type="button"
                          disabled={sending}
                          onClick={() =>
                            handleSuggestionClick('¿Cómo puedo solicitar una cotización?')
                          }
                        >
                          <span className="icon-wrap">
                            <MessageSquareMore className="w-4 h-4" aria-hidden="true" />
                          </span>
                          <span>Solicitar una cotización</span>
                          <ArrowRight className="arrow-icon" aria-hidden="true" />
                        </button>
                      )}
                    </div>
                  </div>
                )}

                {/* Thinking indicator */}
                {sending && (
                  <div className="hc-thinking" role="status">
                    <span className="hc-thinking-dots" aria-hidden="true">
                      <i />
                      <i />
                      <i />
                    </span>
                    <span>Buscando…</span>
                  </div>
                )}

                <div ref={messagesEndRef} />
              </div>

              {/* Anuncio para lectores de pantalla: solo la última respuesta, no todo el bloque de botones y acciones. */}
              <div className="sr-only" role="status" aria-live="polite" aria-atomic="true">
                {anuncio}
              </div>

              {/* Compose Box */}
              <div className="hc-compose">
                <div className="hc-compose-heading">
                  <label htmlFor="mensaje-chat">Escribe tu consulta</label>
                  {draft.length > 150 && (
                    <span>
                      <span>{draft.length}</span> / 2000
                    </span>
                  )}
                </div>
                <form
                  onSubmit={(e) => {
                    e.preventDefault();
                    handleSend();
                  }}
                >
                  <textarea
                    id="mensaje-chat"
                    ref={textareaRef}
                    value={draft}
                    onChange={(e) => {
                      setDraft(e.target.value);
                      e.target.style.height = 'auto';
                      e.target.style.height = `${Math.min(e.target.scrollHeight, 92)}px`;
                    }}
                    maxLength={2000}
                    rows={1}
                    autoComplete="off"
                    placeholder="¿Qué te gustaría saber?"
                    readOnly={sending}
                    aria-busy={sending}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        if (draft.trim() && !sending) {
                          handleSend();
                        }
                      }
                    }}
                    aria-describedby="hc-question-help"
                  />
                  <button
                    type="submit"
                    disabled={sending || !draft.trim()}
                    aria-label="Enviar consulta"
                  >
                    <Send className="w-4 h-4" aria-hidden="true" />
                  </button>
                </form>
                <p className="hc-compose-help" id="hc-question-help">
                  Enter para enviar <span>· Shift + Enter para otra línea</span>
                </p>
                <p className="hc-privacy-hint">
                  <Shield className="w-3 h-3" aria-hidden="true" />
                  No compartas datos personales en el chat.
                </p>
              </div>
            </div>
          </div>
        </section>
      )}
    </div>
  );
}
