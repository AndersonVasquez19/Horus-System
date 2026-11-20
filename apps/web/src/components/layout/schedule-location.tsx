import { Clock, Calendar } from 'lucide-react';

export function ScheduleLocation({ title = 'Horarios y Ubicación' }: { title?: string }) {
  return (
    <section className="py-12 md:py-16 bg-[#F7F9FC]">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="rounded-[30px] border border-gold/20 bg-white p-6 sm:p-8 shadow-lg shadow-gold/10">
          <h2 className="text-2xl sm:text-3xl font-bold text-ink tracking-tight">
            {title}
          </h2>
          <div className="mt-2 h-1 w-16 rounded-full bg-gold"></div>

          <div className="mt-8 grid gap-6 lg:grid-cols-2 items-center">
            <div className="space-y-4">
              {/* Horario */}
              <div className="rounded-2xl border border-stone-200 bg-stone-50 p-5">
                <div className="mb-3 flex items-center gap-3 text-gold">
                  <Clock className="h-6 w-6" />
                  <h3 className="text-lg font-semibold text-ink">Horario de atención</h3>
                </div>
                <ul className="space-y-2 text-sm text-slate-600">
                  <li>
                    <strong>Lunes a viernes:</strong> 9:00 AM - 1:00 PM / 3:00 PM - 6:00 PM
                  </li>
                  <li>
                    <strong>Sábado:</strong> 9:00 AM - 1:00 PM
                  </li>
                </ul>
              </div>

              {/* Ubicación */}
              <div className="rounded-2xl border border-stone-200 bg-stone-50 p-5">
                <div className="mb-3 flex items-center gap-3 text-gold">
                  <Calendar className="h-6 w-6" />
                  <h3 className="text-lg font-semibold text-ink">Ubicación</h3>
                </div>
                <p className="text-sm leading-6 text-slate-600">
                  Jr. José Gálvez N° 322, Cajamarca - Perú.
                </p>
              </div>
            </div>

            {/* Mapa Embed */}
            <div className="overflow-hidden rounded-[24px] border border-gold/20 bg-stone-100 shadow-inner">
              <iframe
                title="Ubicación de Horus Group en Cajamarca"
                src="https://www.google.com/maps/embed?pb=!1m14!1m8!1m3!1d989.6682159535815!2d-78.5107197!3d-7.1637652!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x91b25b809bf9b7e3%3A0x70d5a58b14aa7eff!2sPlazuela%20Bolognesi!5e0!3m2!1ses-419!2spe!4v1772667902105!5m2!1ses-419!2spe"
                className="h-[300px] w-full border-0 block"
                loading="lazy"
                allowFullScreen
                referrerPolicy="no-referrer-when-downgrade"
              ></iframe>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
