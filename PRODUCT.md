# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Público y potenciales estudiantes (Cajamarca y Perú):** personas que buscan formación teórico-práctica con certificación física y digital, consultan cursos vigentes por modalidad y solicitan matrícula o información directa (WhatsApp o formulario de contacto).
- **Equipo administrativo interno (`admin`, `coordinador`, `secretaria`):** personal de Horus Group SRL que gestiona estudiantes, cursos, ediciones, matrículas, pagos y la publicación editorial del catálogo web desde el panel `/admin`.

## Product Purpose

Horus System (Horus Group SRL — Tecnología & Educación) combina una web institucional pública con una plataforma de gestión académica (Horus Academic) para administrar operaciones internas y publicar de forma controlada cursos independientes con información verificada.

## Positioning

- Oferta de **cursos independientes** (sin carreras ni planes de estudio) con jerarquía **Curso → Edición → Sesión → Matrícula**, modalidades flexible (presencial, semipresencial, virtual) y certificación física y digital.
- **Separación estricta entre datos administrativos y publicación editorial:** activar un curso no lo publica; la ficha pública requiere autorización explícita (`cursos.publicar`) y vigencia por fecha (`America/Lima`).

## Operating Context

- Monorepo: `apps/web` (Next.js 16, React 19 — única interfaz web), `apps/api` (Laravel 12 — API, BFF `/api/panel/v1/*`, `/api/public/v1/*`, PDF y correos), `apps/ai` (Horus AI Python/FastAPI).
- El panel administrativo (`/admin/*`) opera tras BFF en Next.js con sesión y permisos Spatie en Laravel.
- Sin portal estudiantil ni docente habilitado por defecto; la matrícula y consultas públicas derivan a canales directos (WhatsApp / contacto).

## Capabilities and Constraints

- Identificación de estudiantes mediante DNI peruano (8 dígitos).
- Enlaces virtuales, precios administrativos, cupos y datos personales nunca se exponen en el catálogo público ni en respuestas del asistente.
- Finanzas con libro inmutable de pagos y reversos compensatorios.
- Imagen referencial de cursos publicados: opcional al publicar desde el panel (JPG/PNG/WebP hasta 2 MB), con respaldo visual limpio cuando no exista imagen.
- El catálogo del asistente (`chatbot.public_catalog_enabled`) permanece apagado por defecto salvo activación explícita.

## Brand Commitments

- **Nombre:** Horus Group SRL — Tecnología & Educación (Cajamarca, Perú).
- **Tono:** institucional, claro y directo en español.
- **Preservación de superficies públicas:** las páginas públicas y secciones existentes (como las tarjetas de Modalidades en `/educacion/cursos`) se mantienen intactas salvo indicación expresa.

## Evidence on Hand

- Sitio público y panel implementados en `apps/web/src/app/**`.
- Catálogo público de cursos servido desde `apps/api/app/Services/CatalogoPublicoCursos.php` y consumido en `apps/web/src/app/educacion/cursos/cursos-view.tsx`.
- Gestión editorial de cursos en `apps/api/app/Http/Controllers/Api/Panel/Academico/CursoPublicacionController.php` y `apps/web/src/components/academico/panel-publicacion.tsx`.

## Product Principles

- **Control editorial explícito:** nada administrativo se vuelve público automáticamente.
- **Claridad antes que densidad:** tarjetas y flujos con aire visual, jerarquía clara y acciones directas sin saturación.
- **Privacidad y veracidad por defecto:** no fabricar datos ni exponer información interna en superficies públicas.
