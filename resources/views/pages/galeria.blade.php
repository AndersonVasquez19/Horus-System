@extends('layouts.app')

@section('title', 'Galería')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/galeria/galeria.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('assets/js/galeria.js') }}" defer></script>
@endpush

@section('content')
<div class="legacy-showcase">
<section class="hero">
        <canvas class="hero-particles" id="heroParticles"></canvas>
        <div class="hero-overlay"></div>

        <div class="hero-grid-lines">
            <span></span><span></span><span></span><span></span><span></span>
        </div>

        <div class="hero-content">
            <div class="hero-badge">
                <span class="badge-dot"></span>
                <span>Horus Group SRL</span>
                <span class="badge-dot"></span>
            </div>

            <div class="hero-ornament top">
                <span class="ornament-line"></span>
                <span class="ornament-diamond"></span>
                <span class="ornament-line"></span>
            </div>

            <h1 class="hero-title">
                <span class="title-line small-text">Nuestra</span>
                <span class="title-line highlight">GALERÍA</span>
                <span class="title-line small-text">Corporativa</span>
            </h1>

            <div class="hero-ornament bottom">
                <span class="ornament-line"></span>
                <span class="ornament-diamond"></span>
                <span class="ornament-line"></span>
            </div>

            <p class="hero-subtitle">
                <span class="subtitle-char">M</span><span class="subtitle-char">o</span><span class="subtitle-char">m</span><span class="subtitle-char">e</span><span class="subtitle-char">n</span><span class="subtitle-char">t</span><span class="subtitle-char">o</span><span class="subtitle-char">s</span>&nbsp;<span class="subtitle-char">q</span><span class="subtitle-char">u</span><span class="subtitle-char">e</span>&nbsp;<span class="subtitle-char">i</span><span class="subtitle-char">n</span><span class="subtitle-char">s</span><span class="subtitle-char">p</span><span class="subtitle-char">i</span><span class="subtitle-char">r</span><span class="subtitle-char">a</span><span class="subtitle-char">n</span>
            </p>


        </div>

        <div class="scroll-indicator" onclick="document.querySelector('.filter-section').scrollIntoView({behavior:'smooth'})">
            <span class="scroll-text">Explorar</span>
            <i class="fas fa-chevron-down"></i>
        </div>
    </section>

    <section class="filter-section">
        <div class="filter-container">
            <button class="filter-btn active" data-filter="all"><i class="fas fa-th"></i> TODOS</button>
            <button class="filter-btn" data-filter="capacitaciones"><i class="fas fa-chalkboard-teacher"></i>
                CAPACITACIONES</button>
            <button class="filter-btn" data-filter="servicio-tecnico"><i class="fas fa-tools"></i> SERVICIO
                TÉCNICO</button>
            <button class="filter-btn" data-filter="primeros-auxilios"><i class="fas fa-heartbeat"></i>
                CEREMONIAS</button>
        </div>
    </section>

    <section class="type-filter-section">
        <div class="type-filter-container">
            <button class="type-filter-btn active" data-type="image">
                <i class="fas fa-image"></i>
                <span>IMÁGENES</span>
            </button>
            <button class="type-filter-btn" data-type="video">
                <i class="fas fa-video"></i>
                <span>VÍDEOS</span>
            </button>
        </div>
    </section>

    <section class="gallery-section" id="gallery">
        <div class="gallery-grid" id="galleryGrid"></div>
    </section>

    <div class="modal" id="imageModal">
        <div class="modal-overlay"></div>
        <div class="modal-content">
            <button class="modal-close" id="modalClose"><i class="fas fa-times"></i></button>
            <button class="modal-nav prev" id="modalPrev"><i class="fas fa-chevron-left"></i></button>
            <button class="modal-nav next" id="modalNext"><i class="fas fa-chevron-right"></i></button>
            <div class="modal-image-container" id="modalContainer"></div>
            <div class="modal-info">
                <h3 class="modal-title" id="modalTitle"></h3>
                <p class="modal-category" id="modalCategory"></p>
                <div class="modal-actions">
                    <div class="action-group left">
                        <button class="action-btn like-btn modal-like" id="modalLike"><i class="far fa-heart"></i> <span
                                class="count">0</span></button>
                        <button class="action-btn dislike-btn modal-dislike" id="modalDislike"><i
                                class="far fa-thumbs-down"></i> <span class="count">0</span></button>
                    </div>
                    <div class="action-group right">
                        <button class="action-btn comment-btn modal-comment" id="modalComment"><i
                                class="far fa-comment"></i> <span class="count">0</span></button>
                        <button class="action-btn share-btn modal-share" id="modalShare"><i
                                class="fas fa-share-alt"></i></button>
                        <button class="action-btn download-btn modal-download" id="modalDownload"><i
                                class="fas fa-download"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="comments-modal" id="commentsModal">
        <div class="comments-overlay"></div>
        <div class="comments-content">
            <div class="comments-header">
                <h3><i class="far fa-comment"></i> Comentarios</h3>
                <button class="comments-close" id="commentsClose"><i class="fas fa-times"></i></button>
            </div>
            <div class="comments-body" id="commentsBody"></div>
            <div class="comments-footer">
                <input type="text" id="commentInput" placeholder="Escribe un comentario...">
                <button id="submitComment"><i class="fas fa-paper-plane"></i></button>
            </div>
        </div>
    </div>

    <div class="share-modal" id="shareModal">
        <div class="share-overlay"></div>
        <div class="share-content">
            <div class="share-header">
                <h3><i class="fas fa-share-alt"></i> Compartir</h3>
                <button class="share-close" id="shareClose"><i class="fas fa-times"></i></button>
            </div>
            <div class="share-body">
                <a href="#" class="share-option facebook" data-platform="facebook"><i
                        class="fab fa-facebook-f"></i><span>Facebook</span></a>
                <a href="#" class="share-option twitter" data-platform="twitter"><i
                        class="fab fa-twitter"></i><span>Twitter</span></a>
                <a href="#" class="share-option whatsapp" data-platform="whatsapp"><i
                        class="fab fa-whatsapp"></i><span>WhatsApp</span></a>
                <a href="#" class="share-option linkedin" data-platform="linkedin"><i
                        class="fab fa-linkedin-in"></i><span>LinkedIn</span></a>
                <a href="#" class="share-option email" data-platform="email"><i
                        class="fas fa-envelope"></i><span>Email</span></a>
                <button class="share-option copy-link" id="copyLink"><i class="fas fa-link"></i><span>Copiar
                        Link</span></button>
            </div>
        </div>
    </div>
</div>
@endsection

