// Galería: filtros por categoría y visor de fotos. Las fotos se renderizan en el servidor (Blade).
(function () {
    'use strict';

    var grid = document.getElementById('galleryGrid');
    if (!grid) return;

    var shots = Array.prototype.slice.call(grid.querySelectorAll('.gal-shot'));
    var chips = Array.prototype.slice.call(document.querySelectorAll('.gal-chip'));
    var lightbox = document.getElementById('galLightbox');
    var lbImg = document.getElementById('galLbImg');
    var lbCaption = document.getElementById('galLbCaption');
    var lbCount = document.getElementById('galLbCount');
    var visible = shots.slice();
    var index = 0;
    var lastFocus = null;

    function applyFilter(filter) {
        visible = [];
        shots.forEach(function (shot) {
            var show = filter === 'all' || shot.dataset.category === filter;
            shot.hidden = !show;
            if (show) visible.push(shot);
        });
        chips.forEach(function (chip) {
            chip.setAttribute('aria-pressed', chip.dataset.filter === filter ? 'true' : 'false');
        });
    }

    chips.forEach(function (chip) {
        chip.addEventListener('click', function () { applyFilter(chip.dataset.filter); });
    });

    function show() {
        var shot = visible[index];
        lbImg.src = shot.dataset.full;
        lbImg.alt = shot.dataset.alt;
        lbCaption.textContent = shot.dataset.label;
        lbCount.textContent = (index + 1) + ' / ' + visible.length;
    }

    function openAt(shot) {
        index = visible.indexOf(shot);
        if (index < 0) return;
        lastFocus = document.activeElement;
        show();
        lightbox.hidden = false;
        document.getElementById('galLbClose').focus();
    }

    function close() {
        lightbox.hidden = true;
        lbImg.removeAttribute('src');
        if (lastFocus) lastFocus.focus();
    }

    function step(delta) {
        index = (index + delta + visible.length) % visible.length;
        show();
    }

    shots.forEach(function (shot) {
        shot.addEventListener('click', function () { openAt(shot); });
    });

    document.getElementById('galLbClose').addEventListener('click', close);
    document.getElementById('galLbPrev').addEventListener('click', function () { step(-1); });
    document.getElementById('galLbNext').addEventListener('click', function () { step(1); });
    lightbox.addEventListener('click', function (event) { if (event.target === lightbox) close(); });

    document.addEventListener('keydown', function (event) {
        if (lightbox.hidden) return;
        if (event.key === 'Escape') close();
        if (event.key === 'ArrowLeft') step(-1);
        if (event.key === 'ArrowRight') step(1);
    });
})();
