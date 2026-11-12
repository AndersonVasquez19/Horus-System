@use('App\Support\OldInput')
@extends('admin.layouts.app')

@section('title', 'Mi perfil')
@section('breadcrumb', 'Mi perfil')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="admin-page-header">
            <div>
                <p class="admin-eyebrow">Mi cuenta</p>
                <h1 class="admin-page-title">Mi perfil</h1>
            </div>
        </div>

        <form action="{{ route('admin.perfil.update') }}" method="POST" class="admin-card sm:p-8" autocomplete="off">
            @csrf
            @method('PUT')

            <div class="grid gap-5 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label for="name" class="admin-label">Nombre completo <span class="text-red-600">*</span></label>
                    <input id="name" name="name" type="text" value="{{ OldInput::value('name', $usuario->name) }}" class="admin-input" required />
                    @error('name')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="email" class="admin-label">Correo electrónico <span class="text-red-600">*</span></label>
                    <input id="email" name="email" type="email" value="{{ OldInput::value('email', $usuario->email) }}" class="admin-input" required />
                    @error('email')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2 border-t border-[#E2E8F0] pt-5">
                    <p class="admin-label">Cambiar la contraseña</p>
                    <p class="text-sm text-[#5B6B7A]">Déjalo en blanco si no quieres cambiarla. Al cambiarla se cierran tus otras sesiones.</p>
                </div>

                <div>
                    <label for="password" class="admin-label">Nueva contraseña</label>
                    <input id="password" name="password" type="password" class="admin-input" autocomplete="new-password" />
                    @error('password')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="admin-label">Confirmar nueva contraseña</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" class="admin-input" autocomplete="new-password" />
                </div>

                <div class="md:col-span-2 border-t border-[#E2E8F0] pt-5">
                    <label for="current_password" class="admin-label">Contraseña actual</label>
                    <input id="current_password" name="current_password" type="password" class="admin-input" autocomplete="current-password" />
                    <p class="mt-1 text-sm text-[#5B6B7A]">Obligatoria solo si cambias el correo o la contraseña.</p>
                    @error('current_password')
                        <p class="admin-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-6 flex justify-end border-t border-[#E2E8F0] pt-5">
                <button type="submit" class="admin-btn-primary">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    Guardar cambios
                </button>
            </div>
        </form>

        <section class="admin-card sm:p-8" aria-labelledby="sesiones-titulo">
            <h2 id="sesiones-titulo" class="text-lg font-semibold text-[#172B3A]">Sesiones activas</h2>
            <p class="mt-1 text-sm text-[#5B6B7A]">Dispositivos donde tu cuenta tiene una sesión abierta. Si no reconoces alguno, ciérralo y cambia tu contraseña.</p>

            @error('current_password', 'sesiones')
                <p class="admin-error mt-3" role="alert">{{ $message }}</p>
            @enderror

            @if (! $sesionesDisponibles)
                <p class="mt-4 text-sm text-[#5B6B7A]">La lista de sesiones no está disponible con la configuración actual del servidor.</p>
            @elseif ($sesiones === [])
                <p class="mt-4 text-sm text-[#5B6B7A]">No hay otras sesiones registradas.</p>
            @else
                <ul class="mt-4 divide-y divide-[#E2E8F0] rounded-xl border border-[#E2E8F0]">
                    @foreach ($sesiones as $sesion)
                        <li class="p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="break-words text-sm font-medium text-[#172B3A]">{{ $sesion['navegador'] ?? 'Navegador desconocido' }}</p>
                                    <p class="mt-1 text-xs text-[#5B6B7A]">
                                        IP {{ $sesion['ip'] ?? 'desconocida' }} ·
                                        Última actividad {{ \Illuminate\Support\Carbon::parse($sesion['ultima_actividad'])->format('d/m/Y H:i') }}
                                    </p>
                                </div>
                                @if ($sesion['actual'])
                                    <span class="inline-flex rounded-full border border-[#19A7A1]/30 bg-[#19A7A1]/10 px-2.5 py-0.5 text-xs font-semibold text-[#0F6E6A]">Esta sesión</span>
                                @else
                                    <details class="w-full sm:w-auto">
                                        <summary class="admin-btn-secondary cursor-pointer">Cerrar esta sesión</summary>
                                        <form action="{{ route('admin.perfil.sesiones.destroy', $sesion['identificador']) }}" method="POST" class="mt-3 flex flex-wrap items-end gap-2">
                                            @csrf
                                            @method('DELETE')
                                            <div>
                                                <label for="sesion-clave-{{ $sesion['identificador'] }}" class="admin-label">Contraseña actual</label>
                                                <input id="sesion-clave-{{ $sesion['identificador'] }}" name="current_password" type="password" class="admin-input" autocomplete="current-password" required />
                                            </div>
                                            <button type="submit" class="admin-btn-primary">Confirmar cierre</button>
                                        </form>
                                    </details>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($sesionesDisponibles)
                <form action="{{ route('admin.perfil.sesiones.destroy-otras') }}" method="POST" class="mt-6 border-t border-[#E2E8F0] pt-5">
                    @csrf
                    @method('DELETE')
                    <p class="admin-label">Cerrar todas las demás sesiones</p>
                    <p class="text-sm text-[#5B6B7A]">Cierra la sesión en todos tus otros dispositivos e invalida los accesos por API. Esta sesión se mantiene abierta.</p>
                    <div class="mt-3 flex flex-wrap items-end gap-3">
                        <div>
                            <label for="sesiones-clave-otras" class="admin-label">Contraseña actual</label>
                            <input id="sesiones-clave-otras" name="current_password" type="password" class="admin-input" autocomplete="current-password" required />
                        </div>
                        <button type="submit" class="admin-btn-primary">
                            <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                            Cerrar las demás sesiones
                        </button>
                    </div>
                </form>
            @endif
        </section>
    </div>
@endsection
