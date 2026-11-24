<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotificacionInterna;
use App\Models\User;
use App\Services\Panel\SaludSistema;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Evalúa la salud del sistema y avisa a los administradores con una notificación interna (como máximo una por comprobación y
 * día). No envía correos ni WhatsApp. Si el scheduler está caído este comando no corre: ese caso se ve al abrir el panel.
 */
class VigilarSalud extends Command
{
    protected $signature = 'horus:salud-vigilar {--simular : Muestra qué avisaría sin crear notificaciones}';

    protected $description = 'Avisa a los administradores (notificación interna) si una comprobación de salud está en alerta o error';

    public function handle(SaludSistema $salud): int
    {
        $simular = (bool) $this->option('simular');
        $resultado = $salud->evaluar();
        $avisos = array_values(array_filter(
            $resultado['comprobaciones'],
            static fn (array $c): bool => in_array($c['estado'], [SaludSistema::ALERTA, SaludSistema::ERROR], true),
        ));

        if ($avisos === []) {
            $this->info('Salud del sistema: todo en orden.');

            return self::SUCCESS;
        }

        $hoy = CarbonImmutable::now()->toDateString();
        $admins = User::query()->role('admin')->get();
        $creadas = 0;

        foreach ($avisos as $c) {
            $titulo = 'Alerta del sistema: '.$c['etiqueta'];
            $vinculo = '/admin/academico/respaldo?salud='.$c['clave'];
            $this->line(($simular ? '[Simulación] ' : '')."{$c['etiqueta']} ({$c['estado']}): {$c['detalle']}");
            if ($simular) {
                continue;
            }
            foreach ($admins as $admin) {
                $yaAvisado = NotificacionInterna::query()
                    ->where('user_id', $admin->id)->where('tipo', 'danger')->where('titulo', $titulo)
                    ->whereDate('created_at', $hoy)->exists();
                if ($yaAvisado || ! ($admin->preferenciasNotificacion()['interno'] ?? true)) {
                    continue;
                }
                NotificacionInterna::crearPersonal($admin->id, [
                    'titulo' => $titulo,
                    'mensaje' => $c['detalle'],
                    'tipo' => 'danger',
                    'vinculo' => $vinculo,
                ]);
                $creadas++;
            }
        }

        $this->info($simular ? 'Simulación: no se creó ninguna notificación.' : "Notificaciones creadas: {$creadas}.");

        return self::SUCCESS;
    }
}
