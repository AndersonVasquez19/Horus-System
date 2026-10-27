@extends('layouts.app')

@section('title', 'Market')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/Market/market.css') }}">
@endpush

@section('content')
<div class="legacy-showcase">
<section class="coming-soon-section">
        <div class="coming-soon-container">
            <div class="coming-soon-icon">
                <i class="fa-solid fa-tools"></i>
            </div>
            <h1 class="coming-soon-title">Market</h1>
            <p class="coming-soon-subtitle">Próximamente</p>
            <p class="coming-soon-text">
                Estamos preparando algo nuevo y emocionante para ti. Nuestro marketplace estará disponible muy pronto
                con productos y servicios exclusivos.
            </p>

            <div class="coming-soon-features">
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <span class="feature-text">Productos</span>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-tags"></i>
                    </div>
                    <span class="feature-text">Ofertas</span>
                </div>
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-truck"></i>
                    </div>
                    <span class="feature-text">Envíos</span>
                </div>
            </div>

            <a href="{{ route('home') }}" class="back-button">
                <i class="fas fa-arrow-left"></i>
                Volver al Inicio
            </a>
        </div>
    </section>
</div>
@endsection

