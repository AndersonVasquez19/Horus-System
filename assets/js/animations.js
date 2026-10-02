class ScrollAnimations {
    constructor() {
        this.observerOptions = {
            threshold: 0.15,
            rootMargin: '0px 0px -50px 0px'
        };
        
        this.init();
    }
    
    init() {
        this.setupObserver();
    }
    
    setupObserver() {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, this.observerOptions);
        
        document.querySelectorAll('.stat').forEach((stat, index) => {
            stat.classList.add('fade-in');
            stat.style.transitionDelay = `${index * 0.12}s`;
            observer.observe(stat);
        });
        
        document.querySelectorAll('.carta').forEach((item, index) => {
            item.classList.add('scale-in');
            item.style.transitionDelay = `${index * 0.08}s`;
            observer.observe(item);
        });
        
        document.querySelectorAll('.carta-card').forEach((item, index) => {
            item.classList.add('fade-in');
            item.style.transitionDelay = `${index * 0.15}s`;
            observer.observe(item);
        });
        
        document.querySelectorAll('.convenio-item').forEach((item, index) => {
            item.classList.add('scale-in');
            item.style.transitionDelay = `${index * 0.08}s`;
            observer.observe(item);
        });
        
        const conveniosTitulo = document.querySelector('.convenios-titulo');
        if (conveniosTitulo) {
            conveniosTitulo.classList.add('fade-in');
            observer.observe(conveniosTitulo);
        }
        
        const seccionTitulo = document.querySelector('.seccion-titulo');
        if (seccionTitulo) {
            seccionTitulo.classList.add('fade-in');
            observer.observe(seccionTitulo);
        }
        
        const tituloCategorias = document.querySelector('.titulo-categorias');
        if (tituloCategorias) {
            tituloCategorias.classList.add('fade-in');
            observer.observe(tituloCategorias);
        }
        
        const textoIntro = document.querySelector('.texto-intro');
        if (textoIntro) {
            textoIntro.classList.add('slide-left');
            observer.observe(textoIntro);
        }
        
        const imagenIntro = document.querySelector('.imagen-intro');
        if (imagenIntro) {
            imagenIntro.classList.add('slide-right');
            observer.observe(imagenIntro);
        }
        
        const horariosColumna = document.querySelector('.horarios-columna');
        if (horariosColumna) {
            horariosColumna.classList.add('slide-left');
            observer.observe(horariosColumna);
        }
        
        const mapaColumna = document.querySelector('.mapa-columna');
        if (mapaColumna) {
            mapaColumna.classList.add('slide-right');
            observer.observe(mapaColumna);
        }
        
        document.querySelectorAll('.footer-seccion').forEach((section, index) => {
            section.classList.add('fade-in');
            section.style.transitionDelay = `${index * 0.1}s`;
            observer.observe(section);
        });
    }
    
}


class CounterAnimation {
    constructor() {
        this.counters = document.querySelectorAll('.stat h3');
        this.animated = new Set();
        this.init();
    }
    
    init() {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !this.animated.has(entry.target)) {
                    this.animateCounter(entry.target);
                    this.animated.add(entry.target);
                }
            });
        }, { threshold: 0.5 });
        
        this.counters.forEach(counter => observer.observe(counter));
    }
    
    animateCounter(element) {
        const text = element.textContent;
        const hasPlus = text.includes('+');
        const hasPercent = text.includes('%');
        const hasSlash = text.includes('/');
        
        if (hasSlash) return; 
        
        const number = parseInt(text.replace(/\D/g, ''));
        const duration = 2000;
        const steps = 60;
        const increment = number / steps;
        let current = 0;
        
        const timer = setInterval(() => {
            current += increment;
            if (current >= number) {
                current = number;
                clearInterval(timer);
            }
            
            let display = Math.floor(current).toString();
            if (hasPlus) display = '+' + display;
            if (hasPercent) display = display + '%';
            
            element.textContent = display;
        }, duration / steps);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new ScrollAnimations();
    new CounterAnimation();
});
