'use client';

import React, { useState, useEffect, useRef } from 'react';
import Link from 'next/link';
import Image from 'next/image';
import {
  X,
  Send,
  GraduationCap,
  Laptop,
  MessageSquareMore,
  BookOpen,
  ArrowRight,
  Shield,
  Check,
} from 'lucide-react';
import { RobotMascot } from './robot-mascot';
import { findAnswer, ChatCard, ChatLink } from './knowledge-base';

interface Message {
  id: string;
  role: 'user' | 'bot';
  text: string;
  cards?: ChatCard[];
  links?: ChatLink[];
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

export function ChatWidget() {
  const [isOpen, setIsOpen] = useState(false);
  const [messages, setMessages] = useState<Message[]>([INITIAL_MESSAGE]);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const launcherRef = useRef<HTMLButtonElement>(null);

  // Restore history from sessionStorage
  useEffect(() => {
    try {
      const stored = sessionStorage.getItem('horus_chat_history');
      if (stored) {
        const parsed = JSON.parse(stored);
        if (Array.isArray(parsed) && parsed.length > 0) {
          setMessages(parsed);
        }
      }
    } catch {
      // ignore storage errors
    }
  }, []);

  // Save history to sessionStorage
  useEffect(() => {
    try {
      if (messages.length > 1) {
        sessionStorage.setItem('horus_chat_history', JSON.stringify(messages.slice(-30)));
      }
    } catch {
      // ignore storage errors
    }
  }, [messages]);

  // Scroll to bottom on new messages
  useEffect(() => {
    if (isOpen) {
      messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }
  }, [messages, sending, isOpen]);

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

    window.addEventListener('keydown', handleKeyDown);
    window.addEventListener('horus-cotizacion', handleCotizacion);
    return () => {
      window.removeEventListener('keydown', handleKeyDown);
      window.removeEventListener('horus-cotizacion', handleCotizacion);
    };
  }, [isOpen]);

  const handleSend = (textToSend?: string) => {
    const text = (textToSend || draft).trim();
    if (!text || sending) return;

    const userMsg: Message = {
      id: `user-${Date.now()}`,
      role: 'user',
      text,
    };

    setMessages((prev) => [...prev, userMsg]);
    setDraft('');
    setSending(true);

    // Simulate smart responder thinking delay
    setTimeout(() => {
      const answer = findAnswer(text);
      const botMsg: Message = {
        id: `bot-${Date.now()}`,
        role: 'bot',
        text: answer.text,
        cards: answer.cards,
        links: answer.links,
      };

      setMessages((prev) => [...prev, botMsg]);
      setSending(false);
    }, 600);
  };

  const handleSuggestionClick = (query: string) => {
    handleSend(query);
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
        <span className="hc-launcher-orbit" aria-hidden="true" />
        <RobotMascot className="hc-launcher-robot" />
        <span className="hc-launcher-badge" aria-hidden="true">
          <MessageSquareMore className="w-4 h-4" />
        </span>
      </button>

      {/* Chat Dialog Panel */}
      {isOpen && (
        <section
          id="horus-chat-panel"
          className="hc-dialog animate-in fade-in zoom-in-95"
          role="dialog"
          aria-modal="false"
          aria-labelledby="hc-title"
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
                <p>Cursos, tecnología y orientación</p>
              </div>
              <button
                type="button"
                onClick={() => setIsOpen(false)}
                className="hc-close"
                aria-label="Cerrar chat"
              >
                <X className="w-5 h-5" aria-hidden="true" />
              </button>
            </header>

            {/* Subheader Toolbar */}
            <div className="hc-toolbar">
              <span>
                <i aria-hidden="true" />
                Información del catálogo
              </span>
              <span className="hc-toolbar-caption">Asistente virtual</span>
            </div>

            {/* Conversation Area */}
            <div className="hc-conversation">
              <div
                className="hc-log"
                role="log"
                tabIndex={0}
                aria-label="Conversación con Horus"
                aria-live="polite"
              >
                {messages.map((message) => (
                  <article
                    key={message.id}
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
                    <p>{message.text}</p>

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
                              <span className="hc-card-cover-label">Imagen de referencia</span>
                            </div>
                            <div className="hc-card-body">
                              <span className="hc-card-kind">
                                {card.tipo === 'servicio'
                                  ? 'Servicio tecnológico'
                                  : 'Curso y capacitación'}
                              </span>
                              <h3>{card.titulo}</h3>
                              <p>{card.descripcion}</p>
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
                              <span className="hc-card-source">
                                <Check className="w-3.5 h-3.5" aria-hidden="true" />
                                Catálogo público de Horus
                              </span>
                            </div>
                          </section>
                        ))}
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
                  </article>
                ))}

                {/* Suggestions pill group (shown when conversation just started) */}
                {messages.length === 1 && messages[0].role === 'bot' && (
                  <div className="hc-suggestions" aria-label="Preguntas sugeridas">
                    <p>Puedes empezar por aquí</p>
                    <div>
                      <button
                        type="button"
                        onClick={() => handleSuggestionClick('¿Qué capacitaciones ofrecen?')}
                      >
                        <span className="icon-wrap">
                          <GraduationCap className="w-4 h-4" aria-hidden="true" />
                        </span>
                        <span>Explorar capacitaciones</span>
                        <ArrowRight className="arrow-icon" aria-hidden="true" />
                      </button>
                      <button
                        type="button"
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
                      <button
                        type="button"
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
                      <button
                        type="button"
                        onClick={() => handleSuggestionClick('¿Cómo puedo matricularme?')}
                      >
                        <span className="icon-wrap">
                          <BookOpen className="w-4 h-4" aria-hidden="true" />
                        </span>
                        <span>Consultar matrícula</span>
                        <ArrowRight className="arrow-icon" aria-hidden="true" />
                      </button>
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
                    <span>Consultando información publicada…</span>
                  </div>
                )}

                <div ref={messagesEndRef} />
              </div>

              {/* Compose Box */}
              <div className="hc-compose">
                <div className="hc-compose-heading">
                  <label htmlFor="mensaje-chat">Escribe tu consulta</label>
                  <span>
                    <span>{draft.length}</span> / 2000
                  </span>
                </div>
                <form
                  onSubmit={(e) => {
                    e.preventDefault();
                    handleSend();
                  }}
                >
                  <label htmlFor="mensaje-chat" className="sr-only">
                    Escribe tu mensaje
                  </label>
                  <textarea
                    id="mensaje-chat"
                    ref={textareaRef}
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    maxLength={2000}
                    rows={1}
                    autoComplete="off"
                    placeholder="¿Qué te gustaría saber?"
                    disabled={sending}
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
