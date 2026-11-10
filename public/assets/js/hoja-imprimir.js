// Botón «Imprimir» de la hoja de reclamación. Reemplaza el onclick en línea para no depender de
// 'unsafe-inline' en la Content-Security-Policy.
document.querySelectorAll('[data-imprimir]').forEach((boton) => {
    boton.addEventListener('click', () => window.print());
});
