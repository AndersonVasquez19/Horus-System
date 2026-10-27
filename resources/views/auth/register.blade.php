@extends('layouts.auth')

@section('title', 'Crear cuenta')

@section('form-content')
    <div class="auth-mobile-brand">
        <img src="{{ asset('assets/images/index/logos/logo-horus.png') }}" alt="Horus Group">
        <span>HORUS GROUP</span>
    </div>
    <div class="auth-heading">
        <span class="auth-eyebrow">EMPIEZA ALGO NUEVO</span>
        <h2>Crea tu cuenta</h2>
        <p>Únete a la comunidad Horus. Solo te tomará un minuto.</p>
    </div>

    @if ($errors->any())
        <div class="auth-notice auth-notice--error" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('register.post') }}" class="auth-form">
        @csrf
        <label class="auth-label" for="name">Nombre completo</label>
        <div class="auth-input-wrap"><i class="fa-regular fa-user" aria-hidden="true"></i><input id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" placeholder="¿Cómo te llamas?" maxlength="120" required autofocus></div>

        <label class="auth-label" for="email">Correo electrónico</label>
        <div class="auth-input-wrap"><i class="fa-regular fa-envelope" aria-hidden="true"></i><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" placeholder="tu@correo.com" required></div>

        <label class="auth-label" for="password">Contraseña</label>
        <div class="auth-input-wrap"><i class="fa-solid fa-lock" aria-hidden="true"></i><input id="password" name="password" type="password" autocomplete="new-password" placeholder="Al menos 10 caracteres" minlength="10" required></div>
        <p class="auth-hint">Usa mayúsculas, minúsculas y al menos un número.</p>

        <label class="auth-label" for="password_confirmation">Confirma tu contraseña</label>
        <div class="auth-input-wrap"><i class="fa-solid fa-check-double" aria-hidden="true"></i><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" placeholder="Repite tu contraseña" minlength="10" required></div>

        <label class="auth-consent"><input type="checkbox" name="terms" value="1" @checked(old('terms')) required><span>He leído y acepto las <a href="{{ route('legales.privacidad') }}" target="_blank" rel="noopener">políticas de privacidad</a>.</span></label>
        <button type="submit" class="auth-submit"><span>Crear mi cuenta</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
    </form>

    <p class="auth-switch">¿Ya tienes cuenta? <a href="{{ route('login') }}">Inicia sesión <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a></p>
    <a href="{{ route('home') }}" class="auth-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver al sitio Horus</a>
    <div class="auth-security"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><span>Registro protegido <b>·</b> Nunca compartimos tu contraseña</span></div>
@endsection
