@use('App\Support\OldInput')
@extends('layouts.auth')

@section('title', 'Iniciar sesión')

@section('form-content')
    <div class="auth-mobile-brand">
        <img src="{{ asset('assets/images/index/logos/logo-horus.png') }}" alt="Horus Group">
        <span>HORUS GROUP</span>
    </div>
    <div class="auth-heading">
        <span class="auth-eyebrow">QUÉ BUENO VERTE DE NUEVO</span>
        <h2>Inicia sesión</h2>
        <p>Accede a tu espacio Horus con tu correo y contraseña.</p>
    </div>

    @if (session('success'))
        <div class="auth-notice auth-notice--success" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>{{ session('success') }}</span></div>
    @endif
    @if ($errors->any())
        <div class="auth-notice auth-notice--error" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('login.post') }}" class="auth-form">
        @csrf
        <label class="auth-label" for="email">Correo electrónico</label>
        <div class="auth-input-wrap"><i class="fa-regular fa-envelope" aria-hidden="true"></i><input id="email" name="email" type="email" value="{{ OldInput::value('email') }}" autocomplete="username" placeholder="tu@correo.com" required autofocus></div>

        <div class="auth-label-row"><label class="auth-label" for="password">Contraseña</label><a href="{{ route('password.request') }}">¿La olvidaste?</a></div>
        <div class="auth-input-wrap"><i class="fa-solid fa-lock" aria-hidden="true"></i><input id="password" name="password" type="password" autocomplete="current-password" placeholder="Tu contraseña" required></div>

        <button type="submit" class="auth-submit"><span>Entrar a mi cuenta</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
    </form>

    <p class="auth-switch">¿Aún no tienes cuenta? <a href="{{ route('register') }}">Crea una gratis <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a></p>
    <a href="{{ route('home') }}" class="auth-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver al sitio Horus</a>
    <div class="auth-security"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><span>Conexión protegida <b>·</b> Tus datos permanecen seguros</span></div>
@endsection
