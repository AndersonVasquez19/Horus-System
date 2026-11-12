'use client';

import { useState } from 'react';
import Image from 'next/image';
import { ZoomIn } from 'lucide-react';
import Lightbox from 'yet-another-react-lightbox';
import 'yet-another-react-lightbox/styles.css';
import { GALERIA_FOTOS, GALERIA_CATEGORIAS } from '@/data/galeria';

export function GalleryClient() {
  const [activeCategory, setActiveCategory] = useState<string>('all');
  const [lightboxIndex, setLightboxIndex] = useState<number>(-1);

  const filteredPhotos =
    activeCategory === 'all'
      ? GALERIA_FOTOS
      : GALERIA_FOTOS.filter((p) => p.cat === activeCategory);

  const slides = filteredPhotos.map((p) => ({
    src: p.src,
    alt: p.alt,
    title: p.label,
  }));

  return (
    <div>
      {/* Category Filter Chips */}
      <div className="flex flex-wrap items-center justify-center gap-2 mb-12">
        {GALERIA_CATEGORIAS.map((cat) => {
          const count =
            cat.id === 'all'
              ? GALERIA_FOTOS.length
              : GALERIA_FOTOS.filter((p) => p.cat === cat.id).length;

          const isActive = activeCategory === cat.id;

          return (
            <button
              key={cat.id}
              type="button"
              onClick={() => setActiveCategory(cat.id)}
              className={`rounded-full px-5 py-2 text-xs sm:text-sm font-semibold transition-all ${
                isActive
                  ? 'bg-gold text-[#0A2540] shadow-md scale-105'
                  : 'bg-white border border-stone-200 text-slate-700 hover:bg-stone-100 hover:text-[#0A2540]'
              }`}
            >
              {cat.label}{' '}
              <small className={`ml-1 text-[11px] font-bold ${isActive ? 'text-[#0A2540]' : 'text-gold-dark'}`}>
                {count}
              </small>
            </button>
          );
        })}
      </div>

      {/* Gallery Grid */}
      <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        {filteredPhotos.map((photo, index) => (
          <button
            key={photo.id}
            type="button"
            onClick={() => setLightboxIndex(index)}
            aria-label={`Ampliar foto: ${photo.label}`}
            className="group relative h-72 sm:h-80 w-full overflow-hidden rounded-2xl border border-stone-200 bg-white cursor-pointer shadow-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl hover:border-gold text-left"
          >
            <div className="relative w-full h-full overflow-hidden">
              <Image
                src={photo.src}
                alt={photo.alt}
                fill
                sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw"
                className="object-cover transition-transform duration-500 group-hover:scale-105"
                style={{ objectPosition: photo.pos || 'center' }}
              />

              {/* Zoom icon badge */}
              <div className="absolute top-4 right-4 flex h-9 w-9 items-center justify-center rounded-full bg-black/60 text-white backdrop-blur-md opacity-0 group-hover:opacity-100 transition-opacity">
                <ZoomIn className="h-4 w-4 text-gold" />
              </div>

              {/* Gradient Overlay & Caption */}
              <div className="absolute inset-0 bg-gradient-to-t from-black/85 via-black/30 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex flex-col justify-end p-5 text-white">
                <h3 className="text-base font-bold leading-snug">{photo.label}</h3>
                <em className="text-xs text-gold not-italic mt-0.5 capitalize">
                  {photo.cat.replace('-', ' ')}
                </em>
              </div>
            </div>
          </button>
        ))}
      </div>

      {/* "¿Quieres ver más? Visita nuestro Instagram" callout de galeria.blade.php */}
      <div className="mt-16 text-center">
        <p className="text-base text-slate-600 mb-3 font-medium">¿Quieres ver más?</p>
        <a
          href="https://www.instagram.com/horusgroupsrl/"
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-2.5 px-6 py-3 rounded-full bg-gradient-to-r from-purple-600 via-pink-600 to-amber-500 text-white font-semibold text-sm shadow-md hover:shadow-lg hover:brightness-110 hover:-translate-y-0.5 transition-all"
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg>
          <span>Visita nuestro Instagram</span>
        </a>
      </div>

      {/* Lightbox Component */}
      <Lightbox
        open={lightboxIndex >= 0}
        index={lightboxIndex}
        close={() => setLightboxIndex(-1)}
        slides={slides}
      />
    </div>
  );
}
