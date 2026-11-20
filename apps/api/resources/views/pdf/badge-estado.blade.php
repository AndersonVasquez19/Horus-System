@php
    $estadoNormalizado = strtolower(trim((string) ($estado ?? '')));
    $badgeClass = match ($estadoNormalizado) {
        'pagada', 'activo', 'activa', 'aprobado', 'aprobada', 'completado', 'finalizado' => 'badge-success',
        'pendiente', 'en revision', 'en_revision', 'en proceso', 'en_proceso' => 'badge-warning',
        'anulada', 'anulado', 'rechazado', 'rechazada', 'cancelado', 'cancelada' => 'badge-danger',
        default => 'badge-neutral',
    };
@endphp

<span class="badge {{ $badgeClass }}">
    {{ ucfirst($estado ?? '—') }}
</span>
