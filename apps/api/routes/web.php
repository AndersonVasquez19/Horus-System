<?php

// Laravel ya no sirve páginas ni autenticación web: la interfaz es apps/web (Next.js) y el acceso va por
// /api/panel/v1/auth/* (sesión + CSRF, vía el BFF) y /api/auth/* (JWT). Este archivo queda vacío a propósito: el grupo de
// middleware `web` (sesión y CSRF) lo siguen usando las rutas del panel. Los enlaces antiguos (`/login`, `/admin/...`)
// los redirige Next con `redirects()` en next.config.ts.
