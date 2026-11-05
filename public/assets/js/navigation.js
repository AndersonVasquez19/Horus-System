document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.querySelector('.menu-toggle');
    const navMenu = document.querySelector('.nav-menu');
    const navOverlay = document.querySelector('.nav-overlay');
    const menuDesplegables = document.querySelectorAll('.menu-desplegable');

    const BREAKPOINT = 1100;
    
    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            this.classList.toggle('active');
            navMenu.classList.toggle('active');
            navOverlay.classList.toggle('active');
            document.body.style.overflow = navMenu.classList.contains('active') ? 'hidden' : '';
        });
    }
    
    if (navOverlay) {
        navOverlay.addEventListener('click', function() {
            navMenu.classList.remove('active');
            menuToggle.classList.remove('active');
            navOverlay.classList.remove('active');
            document.body.style.overflow = '';
        });
    }
    
    menuDesplegables.forEach(menu => {
        const link = menu.querySelector('a');
        link.addEventListener('click', function(e) {
            if (window.innerWidth <= BREAKPOINT) {
                e.preventDefault();
                menu.classList.toggle('active');
            }
        });
    });
    
    const navLinks = document.querySelectorAll('.nav-menu a');
    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            if (!this.parentElement.classList.contains('menu-desplegable') || 
                this.parentElement.parentElement.classList.contains('submenu')) {
                if (window.innerWidth <= BREAKPOINT) {
                    navMenu.classList.remove('active');
                    menuToggle.classList.remove('active');
                    navOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
        });
    });
    
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.nav-pill') && !e.target.closest('.menu-toggle') && navMenu.classList.contains('active')) {
            navMenu.classList.remove('active');
            menuToggle.classList.remove('active');
            navOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
    
    window.addEventListener('resize', function() {
        if (window.innerWidth > BREAKPOINT) {
            navMenu.classList.remove('active');
            menuToggle.classList.remove('active');
            navOverlay.classList.remove('active');
            document.body.style.overflow = '';
            menuDesplegables.forEach(menu => menu.classList.remove('active'));
        }
    });
    
    const nav = document.querySelector('.navegacion');
    
    window.addEventListener('scroll', function() {
        if (window.pageYOffset > 100) {
            nav.classList.add('scrolled');
        } else {
            nav.classList.remove('scrolled');
        }
    });
});
