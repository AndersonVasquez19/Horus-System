@props(['title' => 'Horarios y Ubicación'])
<section class="rounded-[30px] border border-gold/20 bg-white p-6 shadow-lg shadow-gold/10">
  <x-ui.section-title>{{ $title }}</x-ui.section-title>
  <div class="mt-8 grid gap-6 lg:grid-cols-2">
    <div class="space-y-4">
      <div class="rounded-2xl border border-stone-200 bg-stone-50 p-5">
        <div class="mb-3 flex items-center gap-3 text-gold">
          <i class="fa-solid fa-clock text-xl"></i>
          <h3 class="text-lg font-semibold text-ink">Horario de atención</h3>
        </div>
        <ul class="space-y-2 text-sm text-slate-600">
          <li><strong>Lunes a viernes:</strong> 9:00 AM - 1:00 PM / 3:00 PM - 6:00 PM</li>
          <li><strong>Sábado:</strong> 9:00 AM - 1:00 PM</li>
        </ul>
      </div>
      <div class="rounded-2xl border border-stone-200 bg-stone-50 p-5">
        <div class="mb-3 flex items-center gap-3 text-gold">
          <i class="fa-solid fa-calendar-day text-xl"></i>
          <h3 class="text-lg font-semibold text-ink">Ubicación</h3>
        </div>
        <p class="text-sm leading-6 text-slate-600">Jr. José Gálvez N° 322, Cajamarca - Perú.</p>
      </div>
    </div>
    <div class="overflow-hidden rounded-[24px] border border-gold/20 bg-stone-100">
      <iframe src="https://www.google.com/maps/embed?pb=!1m14!1m8!1m3!1d989.6682159535815!2d-78.5107197!3d-7.1637652!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x91b25b809bf9b7e3%3A0x70d5a58b14aa7eff!2sPlazuela%20Bolognesi!5e0!3m2!1ses-419!2spe!4v1772667902105!5m2!1ses-419!2spe" class="h-[300px] w-full border-0" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe>
    </div>
  </div>
</section>
