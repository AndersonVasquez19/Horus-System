'use client';

import { useState, useMemo } from 'react';
import Link from 'next/link';
import { Search, Plus, Minus, MessageCircle, Mail } from 'lucide-react';
import { FAQS, type FaqItem } from '@/data/faqs';
import { CONTACT_INFO } from '@/data/contactos';

export function FaqClient() {
  const [search, setSearch] = useState('');
  const [openId, setOpenId] = useState<string | null>('faq-1');

  const toggleAccordion = (id: string) => {
    setOpenId((prev) => (prev === id ? null : id));
  };

  const filteredFaqs = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return FAQS;
    return FAQS.filter(
      (item) =>
        item.question.toLowerCase().includes(q) ||
        item.answer.toLowerCase().includes(q) ||
        item.keywords.toLowerCase().includes(q)
    );
  }, [search]);

  // Group filtered items by category
  const categories = useMemo(() => {
    const groups: { [key: string]: { label: string; items: FaqItem[] } } = {};
    filteredFaqs.forEach((item) => {
      if (!groups[item.category]) {
        groups[item.category] = { label: item.categoryLabel, items: [] };
      }
      groups[item.category].items.push(item);
    });
    return Object.values(groups);
  }, [filteredFaqs]);

  return (
    <div className="max-w-4xl mx-auto">
      {/* Search Input Bar */}
      <div className="relative mb-12">
        <Search className="absolute left-5 top-1/2 -translate-y-1/2 h-5 w-5 text-gold" />
        <input
          type="text"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Busca por palabra clave (ej. certificados, pagos, modalidades, prácticas)..."
          className="w-full rounded-full border border-stone-300 bg-white py-4 pl-14 pr-6 text-sm text-slate-800 placeholder-slate-400 shadow-md focus:border-[#0A2540] focus:outline-none focus:ring-2 focus:ring-[#0A2540]/10 transition-all"
        />
        {search && (
          <button
            type="button"
            onClick={() => setSearch('')}
            className="absolute right-5 top-1/2 -translate-y-1/2 text-xs font-semibold text-slate-400 hover:text-slate-700"
          >
            Limpiar
          </button>
        )}
      </div>

      {filteredFaqs.length === 0 && (
        <div className="p-8 text-center text-slate-600 bg-white rounded-2xl border border-stone-200 shadow-sm">
          No encontramos resultados para tu búsqueda. Prueba con otros términos o{' '}
          <Link href="/contactos" className="text-gold-dark font-bold underline">
            contáctanos directamente
          </Link>
          .
        </div>
      )}

      {/* Accordion Categories */}
      <div className="space-y-10">
        {categories.map((group) => (
          <div key={group.label} className="space-y-4">
            <h3 className="text-xl font-bold text-[#0A2540] flex items-center gap-2 border-b border-stone-200 pb-2">
              <span className="h-2 w-2 rounded-full bg-gold"></span>
              <span>{group.label}</span>
            </h3>

            <div className="space-y-3">
              {group.items.map((item) => {
                const isOpen = openId === item.id;
                return (
                  <div
                    key={item.id}
                    className="rounded-2xl border border-stone-200 bg-white overflow-hidden transition-all duration-200 hover:border-gold/60 shadow-xs"
                  >
                    <button
                      type="button"
                      onClick={() => toggleAccordion(item.id)}
                      className="w-full flex items-center justify-between p-5 text-left text-[#0A2540] font-semibold transition-colors hover:text-gold-dark"
                      aria-expanded={isOpen}
                    >
                      <span className="flex items-center gap-3 pr-4">
                        <span className="text-xs font-bold text-gold-dark font-mono bg-stone-100 px-2 py-1 rounded-md">
                          {item.number}
                        </span>
                        <span className="text-sm sm:text-base">{item.question}</span>
                      </span>
                      <span className="shrink-0 flex h-7 w-7 items-center justify-center rounded-full bg-stone-100 text-slate-700">
                        {isOpen ? <Minus className="h-4 w-4" /> : <Plus className="h-4 w-4" />}
                      </span>
                    </button>

                    {isOpen && (
                      <div className="px-5 pb-5 pt-1 text-sm text-slate-600 leading-relaxed border-t border-stone-100 animate-in fade-in duration-200">
                        {item.answer}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          </div>
        ))}
      </div>

      {/* Direct Contact Banner exacto de preguntas-frecuentes.blade.php */}
      <div className="mt-16 rounded-3xl border border-gold/30 bg-[#0A2540] p-8 sm:p-10 text-center text-white shadow-xl">
        <h3 className="text-xl sm:text-2xl font-bold mb-3">
          ¿No encontraste lo que <span className="text-gold">buscabas?</span>
        </h3>
        <p className="text-sm sm:text-base text-white/80 max-w-xl mx-auto mb-6 leading-relaxed">
          Nuestro equipo está disponible para resolver cualquier consulta de forma personalizada. No dudes en
          contactarnos.
        </p>
        <div className="flex flex-wrap items-center justify-center gap-4">
          <a
            href={CONTACT_INFO.socials.whatsapp}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-2 rounded-xl bg-gold text-[#0A2540] px-6 py-3 text-xs sm:text-sm font-bold shadow-md hover:brightness-110 transition-all hover:-translate-y-0.5"
          >
            <MessageCircle className="h-4 w-4" />
            <span>Escribir por WhatsApp</span>
          </a>
          <Link
            href="/contactos"
            className="inline-flex items-center gap-2 rounded-xl border border-white/40 bg-white/10 px-6 py-3 text-xs sm:text-sm font-semibold text-white hover:bg-white/20 transition-all"
          >
            <Mail className="h-4 w-4" />
            <span>Enviar un mensaje</span>
          </Link>
        </div>
      </div>
    </div>
  );
}
