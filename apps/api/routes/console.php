<?php

use App\Services\OperacionHorus;
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

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sin interruptores, no altera siquiera la lista de tareas de otros módulos.
app(OperacionHorus::class)->programar();
