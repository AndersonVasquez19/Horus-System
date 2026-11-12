<!DOCTYPE html>
<html lang="es">
  <head>
    @include('partials.head')
  </head>
  <body @class(['public-page bg-[#0A2540] text-ink antialiased', 'home-page' => request()->routeIs('home')])>
    @include('components.navbar')

    <main>
      @yield('content')
    </main>

    @include('components.footer')
    @include('components.public.chat-widget')
    @stack('scripts')
  </body>
</html>
