(function() {
    let slideActual = 0;
    let slides = [];
    let indicators = [];
    let intervalo = null;
    let isTransitioning = false;
    
    function iniciar() {
        const slidesContainer = document.querySelector('.convenios-carousel-slides');
        if (!slidesContainer) {
            console.log('No se encontró el contenedor de convenios');
            return;
        }
        
        slides = document.querySelectorAll('.convenio-slide');
        indicators = document.querySelectorAll('.convenio-indicator');
        
        if (slides.length === 0) {
            console.log('No se encontraron slides de convenios');
            return;
        }
        
        console.log('Carrusel de convenios iniciado con ' + slides.length + ' slides');
        
        indicators.forEach(function(indicator, index) {
            indicator.addEventListener('click', function() {
                if (!isTransitioning && index !== slideActual) {
                    cambiarSlide(index);
                    reiniciarAutoplay();
                }
            });
        });
        
        const wrapper = document.querySelector('.convenios-carousel-wrapper');
        if (wrapper) {
            wrapper.addEventListener('mouseenter', function() {
                detenerAutoplay();
            });
            
            wrapper.addEventListener('mouseleave', function() {
                iniciarAutoplay();
            });
        }
        
        let touchStartX = 0;
        let touchEndX = 0;
        
        const track = document.querySelector('.convenios-carousel-track');
        if (track) {
            track.addEventListener('touchstart', function(e) {
                touchStartX = e.changedTouches[0].screenX;
                detenerAutoplay();
            }, { passive: true });
            
            track.addEventListener('touchend', function(e) {
                touchEndX = e.changedTouches[0].screenX;
                handleSwipe();
                iniciarAutoplay();
            }, { passive: true });
        }
        
        function handleSwipe() {
            const swipeThreshold = 50;
            const diff = touchStartX - touchEndX;
            
            if (Math.abs(diff) > swipeThreshold && !isTransitioning) {
                if (diff > 0) {
                    cambiarSlide(slideActual + 1);
                } else {
                    cambiarSlide(slideActual - 1);
                }
            }
        }
        
        iniciarAutoplay();
        
        actualizarIndicadores();
    }
    
    function cambiarSlide(n) {
        isTransitioning = true;
        
        let nuevoSlide = n;
        if (nuevoSlide >= slides.length) {
            nuevoSlide = 0;
        }
        if (nuevoSlide < 0) {
            nuevoSlide = slides.length - 1;
        }
        
        slideActual = nuevoSlide;
        
        const slidesContainer = document.querySelector('.convenios-carousel-slides');
        if (slidesContainer) {
            const offset = -slideActual * 100;
            slidesContainer.style.transform = 'translateX(' + offset + '%)';
        }
        
        actualizarIndicadores();
        
        console.log('Mostrando convenio slide: ' + slideActual);
        
        setTimeout(function() {
            isTransitioning = false;
        }, 600);
    }
    
    function actualizarIndicadores() {
        indicators.forEach(function(indicator, index) {
            if (index === slideActual) {
                indicator.classList.add('active');
            } else {
                indicator.classList.remove('active');
            }
        });
    }
    
    function siguienteSlide() {
        cambiarSlide(slideActual + 1);
    }
    
    function iniciarAutoplay() {
        if (slides.length > 1) {
            intervalo = setInterval(siguienteSlide, 10000);
            console.log('Autoplay de convenios iniciado');
        }
    }
    
    function detenerAutoplay() {
        if (intervalo) {
            clearInterval(intervalo);
            intervalo = null;
            console.log('Autoplay de convenios detenido');
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
