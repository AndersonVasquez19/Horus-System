
window.addEventListener('scroll', function() {
    const backToTop = document.getElementById('backToTop');
    if (backToTop) {
        if (window.pageYOffset > 300) {
            backToTop.classList.add('show');
        } else {
            backToTop.classList.remove('show');
        }
    }
});


document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        const href = this.getAttribute('href');
        if (href !== '#' && href !== '#tecnologias' && href !== '#educacion') {
            e.preventDefault();
            const target = document.querySelector(href);
            if (target) {
                const nav = document.querySelector('.navegacion');
                const navHeight = nav ? nav.offsetHeight : 0;
                const targetPosition = target.getBoundingClientRect().top + window.pageYOffset - navHeight - 20;
                window.scrollTo({ top: targetPosition, behavior: 'smooth' });
            }
        }
    });
});

const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -50px 0px'
};

const observer = new IntersectionObserver(function(entries) {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.style.opacity = '1';
            entry.target.style.transform = 'translateY(0)';
        }
    });
}, observerOptions);

document.querySelectorAll('.fade-in').forEach(el => {
    if (window.innerWidth <= 768) {
        el.style.opacity = '1';
        el.style.transform = 'none';
        el.style.transition = 'none';
    } else {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(el);
    }
});

window.addEventListener('scroll', () => {
    document.querySelectorAll('.politicas-seccion').forEach(section => {
        const rect = section.getBoundingClientRect();
        section.style.borderColor = (rect.top >= 0 && rect.top <= window.innerHeight / 2)
            ? 'var(--accent-gold)'
            : 'var(--border-color)';
    });
});
