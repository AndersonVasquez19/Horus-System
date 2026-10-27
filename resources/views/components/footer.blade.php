<footer class="mt-16 border-t-4 border-gold bg-[#0A1929] text-stone-200">
  <div class="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:px-6 lg:grid-cols-4 lg:px-8">
    <div>
      <div class="mb-4 flex items-center gap-3">
        <img src="{{ asset('assets/images/index/logos/logo-horus.png') }}" alt="Horus Group" class="site-brand-logo site-brand-logo--footer" />
        <div>
          <div class="text-lg font-semibold text-white">Horus Group SRL</div>
          <div class="text-xs uppercase tracking-[0.2em] text-gold">RUC 20611010977</div>
        </div>
      </div>
      <p class="text-sm leading-6 text-stone-300">Especialistas en soluciones tecnológicas, capacitaciones y soporte para hogares, empresas y profesionales en Cajamarca - Perú.</p>
    </div>

    <div>
      <h3 class="mb-4 text-sm font-semibold uppercase tracking-[0.2em] text-gold">Términos y condiciones</h3>
      <ul class="space-y-2 text-sm text-stone-300">
        <li><a href="{{ route('legales.devolucion') }}" class="hover:text-gold">Políticas de devolución</a></li>
        <li><a href="{{ route('legales.privacidad') }}" class="hover:text-gold">Políticas de privacidad</a></li>
        <li><a href="{{ route('legales.cookies') }}" class="hover:text-gold">Políticas de cookies</a></li>
        <li><a href="{{ route('legales.faq') }}" class="hover:text-gold">Preguntas frecuentes</a></li>
      </ul>
    </div>

    <div>
      <h3 class="mb-4 text-sm font-semibold uppercase tracking-[0.2em] text-gold">Contactos</h3>
      <ul class="space-y-3 text-sm text-stone-300">
        <li><i class="fa-solid fa-phone mr-2 text-gold"></i> +51 927 582 305</li>
        <li><i class="fa-solid fa-envelope mr-2 text-gold"></i> horusgroupcajamarca@gmail.com</li>
        <li><i class="fa-solid fa-location-dot mr-2 text-gold"></i> Cajamarca - Perú</li>
      </ul>
    </div>

    <div>
      <h3 class="mb-4 text-sm font-semibold uppercase tracking-[0.2em] text-gold">Síguenos</h3>
      <div class="mb-4 flex flex-wrap items-center gap-3 text-lg text-stone-200">
        <a href="https://www.facebook.com/share/174BEdCReB/" target="_blank" rel="noopener" class="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 hover:bg-gold hover:text-ink"><i class="fa-brands fa-facebook-f"></i></a>
        {{-- TODO: confirmar número --}}
        <a href="https://wa.me/51927582305" target="_blank" rel="noopener" class="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 hover:bg-gold hover:text-ink"><i class="fa-brands fa-whatsapp"></i></a>
        <a href="https://www.instagram.com/horusgroupsrl/" target="_blank" rel="noopener noreferrer" aria-label="Instagram de Horus Group" title="Instagram" class="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 transition hover:bg-gold hover:text-ink focus-visible:bg-gold focus-visible:text-ink"><i class="fa-brands fa-instagram" aria-hidden="true"></i></a>
        <a href="https://www.tiktok.com/@horus.group.srl?lang=es" target="_blank" rel="noopener noreferrer" aria-label="TikTok de Horus Group" title="TikTok" class="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 transition hover:bg-gold hover:text-ink focus-visible:bg-gold focus-visible:text-ink"><i class="fa-brands fa-tiktok" aria-hidden="true"></i></a>
        <a href="https://www.youtube.com/@HORUSGROUPSRL" target="_blank" rel="noopener noreferrer" aria-label="YouTube de Horus Group" title="YouTube" class="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 transition hover:bg-gold hover:text-ink focus-visible:bg-gold focus-visible:text-ink"><i class="fa-brands fa-youtube" aria-hidden="true"></i></a>
      </div>
      <a href="{{ route('legales.reclamaciones') }}" class="inline-flex items-center gap-3 rounded-2xl border border-gold/40 px-3 py-2 text-sm text-stone-200 hover:bg-gold/10">
        <i class="fa-solid fa-book-open text-gold"></i>
        <span>Libro de Reclamaciones</span>
      </a>
    </div>
  </div>

  <div class="border-t border-white/10 py-4 text-center text-sm text-stone-300">
    © 2026 Horus Group. Todos los derechos reservados.
  </div>
</footer>
