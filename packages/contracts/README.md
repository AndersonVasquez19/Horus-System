# packages/contracts

Contratos HTTP de Horus System. Hoy contiene un único documento, [`openapi/horus-api.yaml`](openapi/horus-api.yaml), que
describe **lo que ya existe** (verificado en el código el 11/11/2026), no un diseño futuro.

## Qué cubre

- Laravel, `/api/auth/*` con JWT: `register`, `login`, `me`, `logout`.
- Laravel, chat público: `POST /chat/mensaje` (sesión y CSRF). La forma completa de la respuesta aún no está documentada;
  solo constan `respuesta` y `fuente`.
- Horus AI: `GET /health`, `GET /ready` y `POST /api/v1/chat` con `X-Horus-Service-Token`.

## Qué deja fuera, a propósito

- `/api/auth/email-verification/*`: suspendido tras `VerificacionRegistroHabilitada`.
- `/api/v1/catalog/*`, `/api/v1/info` y `/api/v1/recommend`: sincronización firmada y diagnóstico entre servicios.
- Todo `/admin`: sesión, CSRF y permisos de Spatie; no es una API pública.

## Reglas

1. Fuente de verdad: el código. Si el comportamiento cambia, se cambia primero la aplicación y después este documento.
2. No se generan clientes ni se enlaza código de ejecución desde aquí todavía.
3. No se documenta ningún secreto ni valor de entorno; solo nombres de cabeceras y variables.
4. La autenticación de Next.js (cookie HttpOnly, CSRF y BFF) está decidida pero **no implementada**; cuando exista, se
   añade aquí con su propio contrato.
