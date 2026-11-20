<?php

declare(strict_types=1);

namespace App\Services;

use App\Console\Commands\ProcesarVencimientosReclamaciones;
use App\Models\CuotaObligacion;
use App\Models\MensajeContacto;
use App\Models\NotificacionInterna;
use App\Models\ObligacionPago;
use App\Models\SesionClase;
use App\Models\User;
use App\Support\Entrada;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/**
 * Recordatorios operativos del scheduler (Ola 3): cuotas por vencer, sesiones del día y mensajes de contacto sin atender.
 * Los plazos del Libro de Reclamaciones corren en {@see ProcesarVencimientosReclamaciones}.
 *
 * Primero registra {@see NotificacionInterna}; solo refleja por correo/WhatsApp cuando las banderas globales y las
 * preferencias/consentimiento del destinatario están activos. Apagado por defecto (`recordatorios.internos_enabled=false`).
 */
class RecordatoriosService
{
    public function __construct(
        private readonly GestionPagos $pagos,
        private readonly EnvioAvisosExternosService $externos,
    ) {}

    /**
     * @return array{cuotas: int, sesiones: int, mensajes: int, externos: int, omitido_desactivado?: bool}
     */
    public function ejecutar(bool $dryRun = false, int $limit = 200): array
    {
        if (config('recordatorios.internos_enabled') !== true) {
            return ['cuotas' => 0, 'sesiones' => 0, 'mensajes' => 0, 'externos' => 0, 'omitido_desactivado' => true];
        }

        $hoyLima = HoraLocal::ahora()->startOfDay();
        $hoyIso = $hoyLima->toDateString();
        $utcHoy = now('UTC')->toDateString();

        $conteos = ['cuotas' => 0, 'sesiones' => 0, 'mensajes' => 0, 'externos' => 0];

        $this->procesarCuotas($hoyLima, $hoyIso, $utcHoy, $dryRun, $limit, $conteos);
        $this->procesarSesiones($hoyIso, $utcHoy, $dryRun, $limit, $conteos);
        $this->procesarMensajes($hoyIso, $utcHoy, $dryRun, $conteos);

        return $conteos;
    }

    /**
     * @param  array{cuotas: int, sesiones: int, mensajes: int, externos: int}  $conteos
     */
    private function procesarCuotas(CarbonImmutable $hoyLima, string $hoyIso, string $utcHoy, bool $dryRun, int $limit, array &$conteos): void
    {
        $dias = max(1, Entrada::entero(config('recordatorios.cuotas_anticipacion_dias', 3)));
        $limiteFecha = $hoyLima->addDays($dias)->toDateString();

        $obligaciones = ObligacionPago::query()
            ->where('estado', 'vigente')
            ->with(['matricula.estudiante', 'cuotas' => fn ($q) => $q->orderBy('numero')])
            ->whereHas('cuotas', fn ($q) => $q->whereBetween('vence', [$hoyIso, $limiteFecha]))
            ->limit($limit)
            ->get();

        $destinatarios = $this->usuariosConPermiso('pagos.ver');

        foreach ($obligaciones as $obligacion) {
            $saldos = $this->pagos->saldos($obligacion);
            if ($saldos['confirmado'] >= $saldos['total']) {
                continue;
            }

            $pagadoPorCuota = $this->pagos->pagadoPorCuota($obligacion);
            $proxima = null;
            foreach ($obligacion->cuotas as $cuota) {
                $monto = GestionPagos::leer($cuota->monto);
                $saldo = $monto - ($pagadoPorCuota[$cuota->id] ?? 0);
                if ($saldo > 0) {
                    $vence = substr((string) $cuota->vence, 0, 10);
                    if ($vence >= $hoyIso && $vence <= $limiteFecha) {
                        $proxima = ['cuota' => $cuota, 'saldo' => GestionPagos::formato($saldo), 'vence' => $vence];
                    }

                    break;
                }
            }

            if ($proxima === null) {
                continue;
            }

            /** @var CuotaObligacion $cuota */
            $cuota = $proxima['cuota'];
            $vinculo = '/admin/academico/pagos/'.$obligacion->identificador;
            $titulo = "Cuota #{$cuota->numero} por vencer ({$proxima['vence']})";

            if ($this->yaNotificadoHoy('recordatorio_cuota', $vinculo, $titulo, $utcHoy)) {
                continue;
            }

            $conteos['cuotas']++;
            if ($dryRun) {
                continue;
            }

            $est = $obligacion->matricula?->estudiante;
            $nombreEst = $est ? trim($est->nombres.' '.$est->apellidos) : 'Estudiante';
            $mensaje = "{$nombreEst} tiene la cuota #{$cuota->numero} por S/ {$proxima['saldo']} con vencimiento el {$proxima['vence']}.";

            $conteos['externos'] += $this->notificarUsuarios(
                $destinatarios,
                'recordatorio_cuota',
                'recordatorio_cuotas',
                $titulo,
                $mensaje,
                $vinculo,
                "cuota-{$cuota->id}-{$hoyIso}",
                'horus_recordatorio_cuota',
                [(string) $cuota->numero, $proxima['vence']], // genérico: sin nombre del estudiante ni montos hacia terceros (Resend/Meta)
            );
        }
    }

    /**
     * @param  array{cuotas: int, sesiones: int, mensajes: int, externos: int}  $conteos
     */
    private function procesarSesiones(string $hoyIso, string $utcHoy, bool $dryRun, int $limit, array &$conteos): void
    {
        $sesiones = SesionClase::query()
            ->where('fecha', $hoyIso)
            ->where('estado', 'programada')
            ->with(['convocatoria.curso' => fn ($q) => $q->withTrashed()->select('id', 'codigo', 'nombre')])
            ->orderBy('hora_inicio')
            ->limit($limit)
            ->get();

        $destinatarios = $this->usuariosConPermiso('sesiones.ver');

        foreach ($sesiones as $sesion) {
            $conv = $sesion->convocatoria;
            if ($conv === null) {
                continue;
            }
            $vinculo = '/admin/academico/convocatorias/'.$conv->identificador;
            $cursoNombre = ($conv->curso->nombre ?? '') ?: $conv->codigo;
            $titulo = "Sesión #{$sesion->numero} hoy: {$conv->codigo}";

            if ($this->yaNotificadoHoy('recordatorio_sesion', $vinculo, $titulo, $utcHoy)) {
                continue;
            }

            $conteos['sesiones']++;
            if ($dryRun) {
                continue;
            }

            $rangoHora = substr((string) $sesion->hora_inicio, 0, 5).'–'.substr((string) $sesion->hora_fin, 0, 5);
            $mensaje = "Clase de {$cursoNombre} ({$conv->codigo}) programada hoy de {$rangoHora}.";

            $conteos['externos'] += $this->notificarUsuarios(
                $destinatarios,
                'recordatorio_sesion',
                'recordatorio_sesiones',
                $titulo,
                $mensaje,
                $vinculo,
                "sesion-{$sesion->id}-{$hoyIso}",
                'horus_recordatorio_sesion',
                [$conv->codigo, (string) $sesion->numero, $rangoHora],
            );
        }
    }

    /**
     * @param  array{cuotas: int, sesiones: int, mensajes: int, externos: int}  $conteos
     */
    private function procesarMensajes(string $hoyIso, string $utcHoy, bool $dryRun, array &$conteos): void
    {
        $horas = max(1, Entrada::entero(config('recordatorios.mensajes_sin_atender_horas', 24)));
        $pendientes = MensajeContacto::query()
            ->where('estado', MensajeContacto::ESTADO_NUEVO)
            ->where('created_at', '<=', now()->subHours($horas))
            ->count();

        if ($pendientes === 0) {
            return;
        }

        $vinculo = '/admin/academico/reclamaciones?vista=mensajes';
        $titulo = "Mensajes de contacto sin atender ({$pendientes})";
        if ($this->yaNotificadoHoy('recordatorio_contacto', $vinculo, null, $utcHoy)) {
            return;
        }

        $conteos['mensajes'] = $pendientes;
        if ($dryRun) {
            return;
        }

        $destinatarios = $this->usuariosConPermiso('reclamaciones.ver');
        $mensaje = "Hay {$pendientes} mensaje(s) desde Contáctanos con más de {$horas} horas sin atender.";

        $conteos['externos'] += $this->notificarUsuarios(
            $destinatarios,
            'recordatorio_contacto',
            'recordatorio_reclamaciones',
            $titulo,
            $mensaje,
            $vinculo,
            "contacto-resumen-{$hoyIso}",
            'horus_recordatorio_contacto',
            [(string) $pendientes, (string) $horas],
        );
    }

    /**
     * @param  Collection<int, User>  $usuarios
     * @param  list<string>  $parametrosWa
     */
    private function notificarUsuarios(
        Collection $usuarios,
        string $tipoNotificacion,
        string $clavePreferencia,
        string $titulo,
        string $mensaje,
        string $vinculo,
        string $baseClaveExterna,
        string $plantillaWa,
        array $parametrosWa,
    ): int {
        $encolados = 0;

        foreach ($usuarios as $usuario) {
            $prefs = $usuario->preferenciasNotificacion();
            if (! ($prefs[$clavePreferencia] ?? true)) {
                continue;
            }

            if ($prefs['interno'] ?? true) {
                NotificacionInterna::crearPersonal($usuario->id, [
                    'titulo' => $titulo,
                    'mensaje' => $mensaje,
                    'tipo' => $tipoNotificacion,
                    'vinculo' => $vinculo,
                ]);
            }

            if (($prefs['email'] ?? false) && config('recordatorios.mail_enabled') === true && filter_var($usuario->email, FILTER_VALIDATE_EMAIL)) {
                $this->externos->registrar(
                    'email',
                    $tipoNotificacion,
                    "email:{$baseClaveExterna}:u{$usuario->id}",
                    ['to' => $usuario->email, 'subject' => $titulo, 'text' => 'Tienes un aviso pendiente en Horus Academic. Ingresa al panel para ver el detalle.'],
                    $usuario->id,
                );
                $encolados++;
            }

            if (
                ($prefs['whatsapp'] ?? false)
                && ($prefs['whatsapp_consentimiento'] ?? false)
                && is_string($usuario->telefono)
                && trim($usuario->telefono) !== ''
                && config('recordatorios.whatsapp.enabled') === true
            ) {
                $this->externos->registrar(
                    'whatsapp',
                    $tipoNotificacion,
                    "wa:{$baseClaveExterna}:u{$usuario->id}",
                    [
                        'telefono' => $usuario->telefono,
                        'plantilla' => $plantillaWa,
                        'parametros' => $parametrosWa,
                        'consentimiento' => true,
                    ],
                    $usuario->id,
                );
                $encolados++;
            }
        }

        return $encolados;
    }

    /** @return Collection<int, User> */
    private function usuariosConPermiso(string $permiso): Collection
    {
        if (! Permission::query()->where('name', $permiso)->where('guard_name', 'web')->exists()) {
            return collect();
        }

        return User::permission($permiso)->get()->filter(fn (User $u): bool => ! $u->estaSuspendido())->values();
    }

    private function yaNotificadoHoy(string $tipo, string $vinculo, ?string $titulo, string $utcHoy): bool
    {
        return NotificacionInterna::query()
            ->where('tipo', $tipo)
            ->where('vinculo', $vinculo)
            ->when($titulo !== null, fn ($q) => $q->where('titulo', $titulo))
            ->whereDate('created_at', $utcHoy)
            ->exists();
    }
}
