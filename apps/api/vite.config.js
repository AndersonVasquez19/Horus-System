import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    // Servidor de desarrollo en IPv4: `public/hot` queda como http://127.0.0.1:5173, una fuente válida de
    // Content-Security-Policy. Con la IPv6 por defecto (http://[::1]:5173) el navegador descarta la fuente y,
    // con CSP_MODE=enforce, bloquea el CSS y el JS de Vite (la página se ve sin estilos).
    server: {
        host: '127.0.0.1',
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/admin-servicios.mjs', 'resources/js/admin-cotizaciones.mjs'],
            refresh: [
                'app/**/*.php',
                'lang/**',
                'resources/lang/**',
                'resources/views/**',
                'routes/**',
            ],
        }),
    ],
});
