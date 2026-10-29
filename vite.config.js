import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/admin-servicios.mjs'],
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
