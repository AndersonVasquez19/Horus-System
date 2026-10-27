// ===== LISTA DE IMÁGENES COMPLETA =====
const imageList = [
    // ========== CAPACITACIONES (12 imágenes) ==========
    { category: 'capacitaciones', path: 'assets/images/galeria/capacitaciones/imagen 1.jpg', title: 'Capacitación 1' },
    { category: 'capacitaciones', path: 'assets/images/galeria/capacitaciones/imagen 2.jpg', title: 'Capacitación 2' },
    { category: 'capacitaciones', path: 'assets/images/galeria/capacitaciones/imagen 3.jpg', title: 'Capacitación 3' },
    { category: 'capacitaciones', path: 'assets/images/galeria/capacitaciones/imagen 4.jpg', title: 'Capacitación 4' },
    { category: 'capacitaciones', path: 'assets/images/galeria/capacitaciones/imagen 5.jpg', title: 'Capacitación 5' },
    { category: 'capacitaciones', path: 'assets/images/galeria/capacitaciones/imagen 6.jpg', title: 'Capacitación 6' },
    { category: 'capacitaciones', path: 'assets/images/galeria/capacitaciones/imagen 7.jpg', title: 'Capacitación 7' },
    { category: 'capacitaciones', path: 'assets/images/galeria/capacitaciones/imagen 8.jpg', title: 'Capacitación 8' },
    { category: 'capacitaciones', path: 'assets/images/galeria/capacitaciones/imagen 9.jpg', title: 'Capacitación 9' },
    { category: 'capacitaciones', path: 'assets/images/galeria/capacitaciones/imagen 10.jpg', title: 'Capacitación 10' },
    { category: 'capacitaciones', path: 'assets/images/galeria/capacitaciones/imagen 11.jpg', title: 'Capacitación 11' },
    { category: 'capacitaciones', path: 'assets/images/galeria/capacitaciones/imagen 12.jpg', title: 'Capacitación 12' },

    // ========== COLEGIO DE ABOGADOS (7 imágenes - optimizado) ==========
    { category: 'colegio-abogados', path: 'assets/images/galeria/colegio de abogados/imagen 1.jpg', title: 'Colegio de Abogados 1' },
    { category: 'colegio-abogados', path: 'assets/images/galeria/colegio de abogados/imagen 2.jpg', title: 'Colegio de Abogados 2' },
    { category: 'colegio-abogados', path: 'assets/images/galeria/colegio de abogados/imagen 3.jpg', title: 'Colegio de Abogados 3' },
    { category: 'colegio-abogados', path: 'assets/images/galeria/colegio de abogados/imagen 4.jpg', title: 'Colegio de Abogados 4' },
    { category: 'colegio-abogados', path: 'assets/images/galeria/colegio de abogados/imagen 7.jpg', title: 'Colegio de Abogados 7' },
    { category: 'colegio-abogados', path: 'assets/images/galeria/colegio de abogados/imagen 9.jpg', title: 'Colegio de Abogados 9' },
    { category: 'colegio-abogados', path: 'assets/images/galeria/colegio de abogados/imagen 10.jpg', title: 'Colegio de Abogados 10' },

   // ========== COLEGIO DE ENFERMEROS (17 imágenes) ==========
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 1.jpg', title: 'Colegio de Enfermeros 1' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 2.jpg', title: 'Colegio de Enfermeros 2' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 3.jpg', title: 'Colegio de Enfermeros 3' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 4.jpg', title: 'Colegio de Enfermeros 4' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 5.jpg', title: 'Colegio de Enfermeros 5' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 6.jpg', title: 'Colegio de Enfermeros 6' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 7.jpg', title: 'Colegio de Enfermeros 7' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 8.jpg', title: 'Colegio de Enfermeros 8' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 9.jpg', title: 'Colegio de Enfermeros 9' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 10.jpg', title: 'Colegio de Enfermeros 10' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 11.jpg', title: 'Colegio de Enfermeros 11' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 12.jpg', title: 'Colegio de Enfermeros 12' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 13.jpg', title: 'Colegio de Enfermeros 13' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 14.jpg', title: 'Colegio de Enfermeros 14' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 15.jpg', title: 'Colegio de Enfermeros 15' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 16.jpg', title: 'Colegio de Enfermeros 16' },
    { category: 'colegio-enfermeros', path: 'assets/images/galeria/colegio de enfermeros/imagen 17.jpg', title: 'Colegio de Enfermeros 17' },

    // ========== ISAM (8 imágenes) ==========
    { category: 'isam', path: 'assets/images/galeria/ISAM/imagen 1.jpg', title: 'ISAM 1' },
    { category: 'isam', path: 'assets/images/galeria/ISAM/imagen 2.jpg', title: 'ISAM 2' },
    { category: 'isam', path: 'assets/images/galeria/ISAM/imagen 3.jpg', title: 'ISAM 3' },
    { category: 'isam', path: 'assets/images/galeria/ISAM/imagen 4.jpg', title: 'ISAM 4' },
    { category: 'isam', path: 'assets/images/galeria/ISAM/imagen 5.jpg', title: 'ISAM 5' },
    { category: 'isam', path: 'assets/images/galeria/ISAM/imagen 6.jpg', title: 'ISAM 6' },
    { category: 'isam', path: 'assets/images/galeria/ISAM/imagen 7.jpg', title: 'ISAM 7' },
    { category: 'isam', path: 'assets/images/galeria/ISAM/imagen 8.jpg', title: 'ISAM 8' },

    // ========== NUESTRO SERVICIO TÉCNICO (10 imágenes) ==========
    { category: 'servicio-tecnico', path: 'assets/images/galeria/nuestro servicio tecnico/imagen 1.jpg', title: 'Servicio Técnico 1' },
    { category: 'servicio-tecnico', path: 'assets/images/galeria/nuestro servicio tecnico/imagen 2.jpg', title: 'Servicio Técnico 2' },
    { category: 'servicio-tecnico', path: 'assets/images/galeria/nuestro servicio tecnico/imagen 3.jpg', title: 'Servicio Técnico 3' },
    { category: 'servicio-tecnico', path: 'assets/images/galeria/nuestro servicio tecnico/imagen 4.jpg', title: 'Servicio Técnico 4' },
    { category: 'servicio-tecnico', path: 'assets/images/galeria/nuestro servicio tecnico/imagen 5.jpg', title: 'Servicio Técnico 5' },
    { category: 'servicio-tecnico', path: 'assets/images/galeria/nuestro servicio tecnico/imagen 6.jpg', title: 'Servicio Técnico 6' },
    { category: 'servicio-tecnico', path: 'assets/images/galeria/nuestro servicio tecnico/imagen 7.jpg', title: 'Servicio Técnico 7' },
    { category: 'servicio-tecnico', path: 'assets/images/galeria/nuestro servicio tecnico/imagen 8.jpg', title: 'Servicio Técnico 8' },
    { category: 'servicio-tecnico', path: 'assets/images/galeria/nuestro servicio tecnico/imagen 9.jpg', title: 'Servicio Técnico 9' },
    { category: 'servicio-tecnico', path: 'assets/images/galeria/nuestro servicio tecnico/imagen 10.jpg', title: 'Servicio Técnico 10' },

    // ========== PRÁCTICAS DE PRIMEROS AUXILIOS (7 imágenes) ==========
    { category: 'primeros-auxilios', path: 'assets/images/galeria/practicas de primeros auxilios/imagen 1.jpg', title: 'Primeros Auxilios 1' },
    { category: 'primeros-auxilios', path: 'assets/images/galeria/practicas de primeros auxilios/imagen 2.jpg', title: 'Primeros Auxilios 2' },
    { category: 'primeros-auxilios', path: 'assets/images/galeria/practicas de primeros auxilios/imagen 3.jpg', title: 'Primeros Auxilios 3' },
    { category: 'primeros-auxilios', path: 'assets/images/galeria/practicas de primeros auxilios/imagen 4.jpg', title: 'Primeros Auxilios 4' },
    { category: 'primeros-auxilios', path: 'assets/images/galeria/practicas de primeros auxilios/imagen 5.jpg', title: 'Primeros Auxilios 5' },
    { category: 'primeros-auxilios', path: 'assets/images/galeria/practicas de primeros auxilios/imagen 6.jpg', title: 'Primeros Auxilios 6' },
    { category: 'primeros-auxilios', path: 'assets/images/galeria/practicas de primeros auxilios/imagen 7.jpg', title: 'Primeros Auxilios 7' },
];

// ===== CONSTRUIR imageData =====
let imageData = {};
imageList.forEach((item, index) => {
    const id = index + 1;
    imageData[id] = {
        title: item.title,
        category: item.category,
        type: 'image', // todas son imágenes
        likes: 0,
        dislikes: 0,
        comments: [],
        imageUrl: item.path
    };
});

// ===== ESTADO DE VOTOS =====
let userVotes = {};
let currentImageId = 1;
let currentCommentsId = 1;
let activeCategory = 'all';
let activeType = 'image'; // Por defecto, imágenes (ya que no hay "todos")

// ===== ELEMENTOS DEL DOM =====
const galleryGrid = document.getElementById('galleryGrid');
const filterBtns = document.querySelectorAll('.filter-btn[data-filter]');
const typeFilterBtns = document.querySelectorAll('.type-filter-btn');
const modal = document.getElementById('imageModal');
const modalContainer = document.getElementById('modalContainer');
const modalTitle = document.getElementById('modalTitle');
const modalCategory = document.getElementById('modalCategory');
const modalLikeBtn = document.getElementById('modalLike');
const modalDislikeBtn = document.getElementById('modalDislike');
const modalCommentBtn = document.getElementById('modalComment');
const modalShareBtn = document.getElementById('modalShare');
const modalDownloadBtn = document.getElementById('modalDownload');
const modalClose = document.getElementById('modalClose');
const modalPrev = document.getElementById('modalPrev');
const modalNext = document.getElementById('modalNext');
const commentsModal = document.getElementById('commentsModal');
const commentsBody = document.getElementById('commentsBody');
const commentsClose = document.getElementById('commentsClose');
const commentInput = document.getElementById('commentInput');
const submitComment = document.getElementById('submitComment');
const shareModal = document.getElementById('shareModal');
const shareClose = document.getElementById('shareClose');
const copyLinkBtn = document.getElementById('copyLink');
const shareOptions = document.querySelectorAll('.share-option:not(.copy-link)');
const toast = document.getElementById('toast');
const toastMessage = document.getElementById('toastMessage');

// ===== FUNCIONES AUXILIARES =====
function showToast(message, icon = 'check-circle') {
    toastMessage.textContent = message;
    toast.querySelector('i').className = `fas fa-${icon}`;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3000);
}

function updateCounts(id) {
    const data = imageData[id];
    document.querySelectorAll(`.gallery-item[data-id="${id}"] .like-btn .count`).forEach(el => el.textContent = data.likes);
    document.querySelectorAll(`.gallery-item[data-id="${id}"] .dislike-btn .count`).forEach(el => el.textContent = data.dislikes);
    document.querySelectorAll(`.gallery-item[data-id="${id}"] .comment-btn .count`).forEach(el => el.textContent = data.comments.length);
    if (currentImageId == id) {
        modalLikeBtn.querySelector('.count').textContent = data.likes;
        modalDislikeBtn.querySelector('.count').textContent = data.dislikes;
        modalCommentBtn.querySelector('.count').textContent = data.comments.length;
    }
}

function updateLikeDislikeButtons(id) {
    const vote = userVotes[id] || { like: false, dislike: false };
    document.querySelectorAll(`.gallery-item[data-id="${id}"] .like-btn`).forEach(btn => btn.classList.toggle('liked', vote.like));
    document.querySelectorAll(`.gallery-item[data-id="${id}"] .dislike-btn`).forEach(btn => btn.classList.toggle('disliked', vote.dislike));
    if (currentImageId == id) {
        modalLikeBtn.classList.toggle('liked', vote.like);
        modalDislikeBtn.classList.toggle('disliked', vote.dislike);
    }
}

// ===== RENDERIZAR GALERÍA =====
function renderGallery() {
    const fragment = document.createDocumentFragment();
    
    Object.keys(imageData).forEach(id => {
        const item = imageData[id];
        const galleryItem = document.createElement('div');
        galleryItem.className = 'gallery-item';
        galleryItem.dataset.category = item.category;
        galleryItem.dataset.id = id;
        galleryItem.dataset.type = item.type;

        const overlayTitle = item.title ? `<h3 class="image-title">${item.title}</h3>` : '';
        const overlayCategory = `<p class="image-category">${item.category.replace('-', ' ')}</p>`;

        galleryItem.innerHTML = `
            <div class="image-container">
                <img data-src="${item.imageUrl}" alt="${item.title || 'Imagen'}" class="gallery-image lazy-load">
                <div class="image-overlay">
                    <div class="overlay-content">
                        ${overlayTitle}
                        ${overlayCategory}
                    </div>
                </div>
            </div>
            <div class="image-actions">
                <div class="action-group left">
                    <button class="action-btn like-btn" data-id="${id}" title="Me gusta"><i class="far fa-heart"></i> <span class="count">${item.likes}</span></button>
                </div>
                <div class="action-group right">
                    <button class="action-btn comment-btn" data-id="${id}" title="Comentarios"><i class="far fa-comment"></i> <span class="count">${item.comments.length}</span></button>
                    <button class="action-btn share-btn" data-id="${id}" title="Compartir"><i class="fas fa-share-alt"></i></button>
                </div>
            </div>
        `;
        fragment.appendChild(galleryItem);
    });
    
    galleryGrid.innerHTML = '';
    galleryGrid.appendChild(fragment);
    attachEvents();
    initLazyLoading();
}

// ===== LAZY LOADING OPTIMIZADO =====
function initLazyLoading() {
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                const src = img.getAttribute('data-src');
                if (src) {
                    img.src = src;
                    img.removeAttribute('data-src');
                    img.classList.remove('lazy-load');
                    observer.unobserve(img);
                }
            }
        });
    }, {
        rootMargin: '50px'
    });

    document.querySelectorAll('.lazy-load').forEach(img => {
        imageObserver.observe(img);
    });
}

function attachEvents() {
    document.querySelectorAll('.like-btn').forEach(btn => {
        btn.addEventListener('click', (e) => { e.stopPropagation(); handleLike(btn.dataset.id); });
    });
    document.querySelectorAll('.dislike-btn').forEach(btn => {
        btn.addEventListener('click', (e) => { e.stopPropagation(); handleDislike(btn.dataset.id); });
    });
    document.querySelectorAll('.comment-btn').forEach(btn => {
        btn.addEventListener('click', (e) => { e.stopPropagation(); currentCommentsId = btn.dataset.id; openCommentsModal(btn.dataset.id); });
    });
    document.querySelectorAll('.share-btn').forEach(btn => {
        btn.addEventListener('click', (e) => { e.stopPropagation(); shareModal.classList.add('active'); });
    });
    document.querySelectorAll('.download-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = btn.dataset.id;
            downloadImage(imageData[id].imageUrl, `horus_${id}.jpg`);
        });
    });
    document.querySelectorAll('.gallery-item').forEach(item => {
        item.addEventListener('click', (e) => {
            if (e.target.closest('.action-btn')) return;
            openModal(item.dataset.id);
        });
    });
}

// ===== FILTROS OPTIMIZADOS =====
function applyFiltersOptimized() {
    return new Promise((resolve) => {
        const items = document.querySelectorAll('.gallery-item');
        const visibleItems = [];
        const hiddenItems = [];
        
        // Separar items visibles y ocultos
        items.forEach(item => {
            const category = item.dataset.category;
            const type = item.dataset.type;
            const categoryMatch = activeCategory === 'all' || category === activeCategory;
            const typeMatch = activeType === 'all' || type === activeType;
            
            if (categoryMatch && typeMatch) {
                visibleItems.push(item);
            } else {
                hiddenItems.push(item);
            }
        });
        
        // Ocultar primero (más rápido)
        hiddenItems.forEach(item => {
            item.style.display = 'none';
            item.classList.add('hidden');
        });
        
        // Mostrar después en lotes pequeños para mejor rendimiento
        let index = 0;
        function showBatch() {
            const batchSize = 8; // Mostrar 8 items a la vez
            const end = Math.min(index + batchSize, visibleItems.length);
            
            for (let i = index; i < end; i++) {
                visibleItems[i].style.display = '';
                visibleItems[i].classList.remove('hidden');
            }
            
            index = end;
            
            if (index < visibleItems.length) {
                requestAnimationFrame(showBatch);
            } else {
                // Reiniciar lazy loading cuando termine
                initLazyLoading();
                resolve();
            }
        }
        
        requestAnimationFrame(showBatch);
    });
}

// ===== FILTROS =====
function applyFilters() {
    applyFiltersOptimized();
}

filterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        // Prevenir clicks múltiples
        if (btn.classList.contains('active') || btn.disabled) return;
        
        // Deshabilitar todos los botones temporalmente
        filterBtns.forEach(b => {
            b.classList.remove('active');
            b.disabled = true;
        });
        
        btn.classList.add('active');
        activeCategory = btn.dataset.filter;
        
        // Aplicar filtros de forma optimizada
        applyFiltersOptimized().then(() => {
            // Rehabilitar botones cuando termine
            filterBtns.forEach(b => b.disabled = false);
            showToast(`Categoría: ${btn.textContent.trim()}`);
        });
    });
});

typeFilterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        typeFilterBtns.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeType = btn.dataset.type;
        applyFilters();
        showToast(`Mostrando: ${btn.querySelector('span').textContent}`);
    });
});

// ===== LIKE / DISLIKE =====
function handleLike(id) {
    if (!userVotes[id]) userVotes[id] = { like: false, dislike: false };
    const vote = userVotes[id];
    if (vote.like) {
        imageData[id].likes--;
        vote.like = false;
    } else {
        imageData[id].likes++;
        vote.like = true;
        if (vote.dislike) {
            imageData[id].dislikes--;
            vote.dislike = false;
        }
    }
    updateCounts(id);
    updateLikeDislikeButtons(id);
    showToast(vote.like ? '¡Te gusta!' : 'Ya no te gusta', 'heart');
}

function handleDislike(id) {
    if (!userVotes[id]) userVotes[id] = { like: false, dislike: false };
    const vote = userVotes[id];
    if (vote.dislike) {
        imageData[id].dislikes--;
        vote.dislike = false;
    } else {
        imageData[id].dislikes++;
        vote.dislike = true;
        if (vote.like) {
            imageData[id].likes--;
            vote.like = false;
        }
    }
    updateCounts(id);
    updateLikeDislikeButtons(id);
    showToast(vote.dislike ? 'No te gusta' : 'Ya no te disgusta', 'thumbs-down');
}

// ===== COMENTARIOS =====
function openCommentsModal(id) {
    const comments = imageData[id].comments;
    commentsBody.innerHTML = comments.map((comment, index) => `
        <div class="comment-item" data-index="${index}">
            <div class="comment-content">
                <span class="comment-author">Usuario:</span>
                <span class="comment-text">${comment}</span>
            </div>
            <button class="delete-comment" data-index="${index}"><i class="fas fa-trash-alt"></i></button>
        </div>`).join('');
    commentsModal.classList.add('active');
    commentInput.value = '';
    commentInput.focus();
    
    document.querySelectorAll('.delete-comment').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const index = btn.dataset.index;
            imageData[currentCommentsId].comments.splice(index, 1);
            updateCounts(currentCommentsId);
            openCommentsModal(currentCommentsId);
            showToast('Comentario eliminado');
        });
    });
}

commentsClose.addEventListener('click', () => commentsModal.classList.remove('active'));
document.querySelector('.comments-overlay').addEventListener('click', () => commentsModal.classList.remove('active'));

submitComment.addEventListener('click', () => {
    const text = commentInput.value.trim();
    if (text) {
        imageData[currentCommentsId].comments.push(text);
        updateCounts(currentCommentsId);
        openCommentsModal(currentCommentsId);
        showToast('Comentario agregado');
    }
});

commentInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') submitComment.click();
});

// ===== COMPARTIR =====
shareOptions.forEach(opt => {
    opt.addEventListener('click', (e) => {
        e.preventDefault();
        showToast(`Compartido en ${opt.dataset.platform}`, 'share-alt');
        shareModal.classList.remove('active');
    });
});
shareClose.addEventListener('click', () => shareModal.classList.remove('active'));
document.querySelector('.share-overlay').addEventListener('click', () => shareModal.classList.remove('active'));
copyLinkBtn.addEventListener('click', () => {
    navigator.clipboard.writeText(window.location.href).then(() => {
        showToast('Link copiado', 'link');
        shareModal.classList.remove('active');
    });
});

// ===== DESCARGA DIRECTA (INTENTO CON DOWNLOAD) =====
function downloadImage(url, filename) {
    // Crear un enlace temporal con el atributo download
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    showToast('Descarga iniciada (si no funciona, guarda manualmente)', 'download');
}

// ===== LIGHTBOX =====
function openModal(id) {
    currentImageId = id;
    const data = imageData[id];
    modalContainer.innerHTML = `<img src="${data.imageUrl}" alt="${data.title || ''}">`;
    modalTitle.textContent = data.title || '';
    modalCategory.textContent = data.category.replace('-', ' ');
    modalLikeBtn.querySelector('.count').textContent = data.likes;
    modalDislikeBtn.querySelector('.count').textContent = data.dislikes;
    modalCommentBtn.querySelector('.count').textContent = data.comments.length;
    updateLikeDislikeButtons(id);
    modal.classList.add('active');
}

modalClose.addEventListener('click', () => modal.classList.remove('active'));
document.querySelector('.modal-overlay').addEventListener('click', () => modal.classList.remove('active'));

modalPrev.addEventListener('click', () => {
    const ids = Object.keys(imageData).map(Number);
    const index = ids.indexOf(currentImageId);
    openModal(ids[(index - 1 + ids.length) % ids.length]);
});

modalNext.addEventListener('click', () => {
    const ids = Object.keys(imageData).map(Number);
    const index = ids.indexOf(currentImageId);
    openModal(ids[(index + 1) % ids.length]);
});

modalLikeBtn.addEventListener('click', () => handleLike(currentImageId));
modalDislikeBtn.addEventListener('click', () => handleDislike(currentImageId));
modalCommentBtn.addEventListener('click', () => {
    currentCommentsId = currentImageId;
    openCommentsModal(currentImageId);
});
modalShareBtn.addEventListener('click', () => shareModal.classList.add('active'));
modalDownloadBtn.addEventListener('click', () => {
    const item = imageData[currentImageId];
    downloadImage(item.imageUrl, `horus_${currentImageId}.jpg`);
});

// ===== SCROLL INDICATOR =====
document.querySelector('.scroll-indicator').addEventListener('click', () => {
    document.getElementById('gallery').scrollIntoView({ behavior: 'smooth' });
});

// ===== NAVEGACIÓN SUAVE =====
document.querySelectorAll('.nav-link').forEach(link => {
    link.addEventListener('click', (e) => {
        e.preventDefault();
        const target = document.querySelector(link.getAttribute('href'));
        if (target) {
            target.scrollIntoView({ behavior: 'smooth' });
        }
    });
});

// ===== INICIALIZAR =====
renderGallery();
Object.keys(imageData).forEach(id => userVotes[id] = { like: false, dislike: false });