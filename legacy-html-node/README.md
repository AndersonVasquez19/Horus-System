# Sitio HTML y API Node históricos

Esta carpeta conserva el sitio HTML original, sus recursos de `assets/` y la API Express/Sequelize de `src/`. Es independiente de la aplicación Laravel de la carpeta superior: usa su propio `package.json`, su propio `.env` y MySQL. Laravel usa `public/`, `resources/`, PostgreSQL por defecto y el `package.json` de la raíz.

## Preparación local

Desde esta carpeta, ejecuta `npm ci` y crea un `.env` local a partir de `.env.example`. Configura un destino MySQL de desarrollo que puedas modificar. El archivo `.env` está ignorado; no copies aquí el `.env` de Laravel.

Las páginas HTML enlazan entre sí y con `assets/` mediante rutas relativas. Sirve esta carpeta como raíz estática para verlas. La API Node no sirve esas páginas: expone `/api/contacto` y `/api/reclamaciones` por separado. Los formularios de contacto y reclamaciones apuntan actualmente a `http://localhost:3000` en `assets/js/contactos2.js` y `assets/js/libro-reclamaciones.js`; ajusta esas constantes si usas otro origen.

## Arranque y datos

`npm start` ejecuta `server.js` y `npm run dev` usa nodemon. **Antes de cualquiera de los dos**, comprueba el destino MySQL en este `.env`: el arranque ejecuta `sequelize.sync({ alter: true })` y puede modificar tablas. No lo apuntes a una base que debas conservar sin revisar ese efecto.

Las migraciones de Sequelize usan `.sequelizerc` y `src/database/`; ejecutarlas también escribe en MySQL. El `npm run dev` de la carpeta superior inicia Vite para Laravel y no este servidor.
