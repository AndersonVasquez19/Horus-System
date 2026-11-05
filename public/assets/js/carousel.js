(function() {
    let slideActual = 0;
    let slides = [];
    let intervalo = null;
    
    function iniciar() {
        slides = document.querySelectorAll('.carrusel-diapos img');
        
        if (slides.length === 0) {
            console.log('No se encontraron imágenes del carrusel');
            return;
        }
        
        console.log('Carrusel iniciado con ' + slides.length + ' imágenes');
        
        const btnNext = document.querySelector('.carrusel-btn.next');
        const btnPrev = document.querySelector('.carrusel-btn.prev');
        
        if (btnNext) {
            btnNext.addEventListener('click', function() {
                cambiarSlide(slideActual + 1);
                reiniciarAutoplay();
            });
        }
        
        if (btnPrev) {
            btnPrev.addEventListener('click', function() {
                cambiarSlide(slideActual - 1);
                reiniciarAutoplay();
            });
        }
        
        const contenedor = document.querySelector('.carrusel-contenedor');
        if (contenedor) {
            contenedor.addEventListener('mouseenter', function() {
                detenerAutoplay();
            });
            
            contenedor.addEventListener('mouseleave', function() {
                iniciarAutoplay();
            });
        }
        
        iniciarAutoplay();
    }
    
    function cambiarSlide(n) {
        slides.forEach(function(slide) {
            slide.classList.remove('diapos-active');
            slide.classList.add('diapos');
        });
        
        slideActual = n;
        if (slideActual >= slides.length) {
            slideActual = 0;
        }
        if (slideActual < 0) {
            slideActual = slides.length - 1;
        }
        
        slides[slideActual].classList.remove('diapos');
        slides[slideActual].classList.add('diapos-active');
        
        console.log('Mostrando slide: ' + slideActual);
    }
    
    function siguienteSlide() {
        cambiarSlide(slideActual + 1);
    }
    
    function iniciarAutoplay() {
        intervalo = setInterval(siguienteSlide, 4000);
        console.log('Autoplay iniciado');
    }
    
    function detenerAutoplay() {
        if (intervalo) {
            clearInterval(intervalo);
            intervalo = null;
            console.log('Autoplay detenido');
        }
    }
    
    function reiniciarAutoplay() {
        detenerAutoplay();
        iniciarAutoplay();
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
