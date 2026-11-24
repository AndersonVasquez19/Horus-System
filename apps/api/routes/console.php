<?php

use App\Services\OperacionHorus;
use App\Services\Panel\SaludSistema;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('horus:matriculas-notificar')->everyMinute();

// Vencimientos del Libro de Reclamaciones (Sprint 22): una vez al día, a las 08:00 de Lima. El bloqueo caduca a
// los 30 minutos para que una ejecución abandonada (proceso muerto) no impida las siguientes durante 24 h.
// Con el módulo desactivado el comando termina con éxito sin consultar ni escribir.
Schedule::command('horus:reclamaciones-vencimientos')
    ->dailyAt('08:00')
    ->timezone('America/Lima')
    ->withoutOverlapping(30);

Schedule::command('horus:recordatorios-diarios')
    ->dailyAt('07:00')
    ->timezone('America/Lima')
    ->withoutOverlapping(30);

Schedule::command('horus:avisos-despachar')
    ->everyMinute()
    ->withoutOverlapping(5);

// Salud del sistema: el latido demuestra que `schedule:run` sigue vivo (el panel avisa si envejece) y la vigilancia crea, como
// máximo una vez al día por comprobación, una notificación INTERNA a los administradores. No envía correo ni WhatsApp.
Schedule::call(fn () => SaludSistema::latir())->name('horus-latido')->everyMinute();
Schedule::command('horus:salud-vigilar')->everyFiveMinutes()->withoutOverlapping(10);

// Respaldo local diario de PostgreSQL (pg_dump). Apagado por defecto: solo se programa con RESPALDO_PROGRAMADO_ENABLED=true.
// El bloqueo caduca a la hora para que una ejecución abandonada no impida las siguientes.
if (config('respaldo.programado') === true) {
    Schedule::command('horus:respaldo')
        ->dailyAt(is_string(config('respaldo.hora')) ? config('respaldo.hora') : '02:00')
        ->timezone('America/Lima')
        ->withoutOverlapping(60);
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sin interruptores, no altera siquiera la lista de tareas de otros módulos.
app(OperacionHorus::class)->programar();
