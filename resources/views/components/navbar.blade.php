<nav x-data="nav()" class="relative z-40 px-4 pb-6 pt-4">
  <div class="nav-pill">
    <a href="{{ route('home') }}" class="flex items-center gap-3">
      <img src="{{ asset('assets/images/index/logos/logo-horus.png') }}" alt="Logo Horus Group" class="site-brand-logo" />
      <span class="text-base font-semibold tracking-wide text-white">Horus Group SRL</span>
    </a>

    <button type="button" @click="toggleMenu()" class="flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white/80 text-slate-700 lg:hidden" aria-label="Abrir menú">
      <span class="space-y-1.5">
        <span class="block h-0.5 w-5 rounded-full bg-current"></span>
        <span class="block h-0.5 w-5 rounded-full bg-current"></span>
        <span class="block h-0.5 w-5 rounded-full bg-current"></span>
      </span>
    </button>

    <ul class="hidden items-center gap-2 lg:flex">
      <li><a href="{{ route('home') }}" class="nav-link">Inicio</a></li>
      <li><a href="{{ route('quienes-somos') }}" class="nav-link">Quiénes Somos</a></li>

      <li class="relative" @mouseenter="subOpen = 'tecnologias'" @mouseleave="subOpen = null">
        <button type="button" @click="subOpen = subOpen === 'tecnologias' ? null : 'tecnologias'" class="nav-link">
          Tecnologías <i class="fa-solid fa-chevron-down text-[10px]"></i>
        </button>
        <ul x-show="subOpen === 'tecnologias'" x-transition class="nav-submenu">
          <li><a href="{{ route('tecnologias.cableado') }}" class="block rounded-xl px-3 py-2 text-sm hover:bg-gold/10">Cableado Estructurado</a></li>
          <li><a href="{{ route('tecnologias.camaras') }}" class="block rounded-xl px-3 py-2 text-sm hover:bg-gold/10">Cámaras de Seguridad</a></li>
          <li><a href="{{ route('tecnologias.soporte') }}" class="block rounded-xl px-3 py-2 text-sm hover:bg-gold/10">Soporte y Mantenimiento</a></li>
        </ul>
      </li>

      <li class="relative" @mouseenter="subOpen = 'educacion'" @mouseleave="subOpen = null">
        <button type="button" @click="subOpen = subOpen === 'educacion' ? null : 'educacion'" class="nav-link">
          Educación <i class="fa-solid fa-chevron-down text-[10px]"></i>
        </button>
        <ul x-show="subOpen === 'educacion'" x-transition class="nav-submenu">
          <li><a href="{{ route('educacion.asesoramiento') }}" class="block rounded-xl px-3 py-2 text-sm hover:bg-gold/10">Asesoramiento</a></li>
          <li><a href="{{ route('educacion.capacitaciones') }}" class="block rounded-xl px-3 py-2 text-sm hover:bg-gold/10">Capacitaciones</a></li>
          <li><a href="{{ route('educacion.cursos') }}" class="block rounded-xl px-3 py-2 text-sm hover:bg-gold/10">Cursos</a></li>
        </ul>
      </li>

      <li><a href="{{ route('market') }}" class="nav-link">Market</a></li>
      <li><a href="{{ route('galeria') }}" class="nav-link">Galería</a></li>
      <li><a href="{{ route('contactos') }}" class="nav-link">Contáctanos</a></li>
      @auth
        <li>
          <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="nav-link">Cerrar sesión</button>
          </form>
        </li>
      @endauth
    </ul>

    <div x-show="open" x-transition class="absolute left-0 right-0 top-[calc(100%+0.5rem)] mx-4 rounded-3xl border border-slate-200 bg-white/95 p-4 shadow-2xl lg:hidden">
      <ul class="space-y-2">
        <li><a href="{{ route('home') }}" class="block rounded-xl px-3 py-2 text-sm font-medium hover:bg-gold/10">Inicio</a></li>
        <li><a href="{{ route('quienes-somos') }}" class="block rounded-xl px-3 py-2 text-sm font-medium hover:bg-gold/10">Quiénes Somos</a></li>
        <li><a href="{{ route('tecnologias.cableado') }}" class="block rounded-xl px-3 py-2 text-sm font-medium hover:bg-gold/10">Cableado Estructurado</a></li>
        <li><a href="{{ route('tecnologias.camaras') }}" class="block rounded-xl px-3 py-2 text-sm font-medium hover:bg-gold/10">Cámaras de Seguridad</a></li>
        <li><a href="{{ route('tecnologias.soporte') }}" class="block rounded-xl px-3 py-2 text-sm font-medium hover:bg-gold/10">Soporte y Mantenimiento</a></li>
        <li><a href="{{ route('educacion.asesoramiento') }}" class="block rounded-xl px-3 py-2 text-sm font-medium hover:bg-gold/10">Asesoramiento</a></li>
        <li><a href="{{ route('educacion.capacitaciones') }}" class="block rounded-xl px-3 py-2 text-sm font-medium hover:bg-gold/10">Capacitaciones</a></li>
        <li><a href="{{ route('educacion.cursos') }}" class="block rounded-xl px-3 py-2 text-sm font-medium hover:bg-gold/10">Cursos</a></li>
        <li><a href="{{ route('market') }}" class="block rounded-xl px-3 py-2 text-sm font-medium hover:bg-gold/10">Market</a></li>
        <li><a href="{{ route('galeria') }}" class="block rounded-xl px-3 py-2 text-sm font-medium hover:bg-gold/10">Galería</a></li>
        <li><a href="{{ route('contactos') }}" class="block rounded-xl px-3 py-2 text-sm font-medium hover:bg-gold/10">Contáctanos</a></li>
        @auth
          <li>
            <form method="POST" action="{{ route('logout') }}">
              @csrf
              <button type="submit" class="block w-full rounded-xl px-3 py-2 text-left text-sm font-medium hover:bg-gold/10">Cerrar sesión</button>
            </form>
          </li>
        @endauth
      </ul>
    </div>
  </div>
</nav>
