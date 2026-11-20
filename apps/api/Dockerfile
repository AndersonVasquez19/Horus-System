# syntax=docker/dockerfile:1
#
# Imagen de Laravel (API, PDF, correos) para Railway. Una sola imagen sirve a tres servicios, elegidos con la
# variable HORUS_PROCESO: `web` (FrankenPHP), `worker` (queue:work) y `scheduler` (schedule:work).
#
# CONTEXTO DE CONSTRUCCIÓN: la raíz del repositorio (Laravel lee también apps/ai/data/knowledge.json).
#   docker build -f apps/api/Dockerfile -t horus-api .
#
# La imagen NO ejecuta migraciones ni seeders: se aplican a mano y con autorización (docs/despliegue-vercel-railway.md).

FROM dunglas/frankenphp:1.13.4-php8.4-bookworm

# Extensiones: pdo_pgsql (PostgreSQL), bcmath (finanzas), gd y zip (PDF y exportaciones), intl, pcntl (señales del
# worker) y opcache. dom, mbstring, openssl, xml, ctype, fileinfo y tokenizer ya vienen en la imagen base.
RUN install-php-extensions pdo_pgsql bcmath gd intl zip pcntl opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY apps/api/docker/php.ini "$PHP_INI_DIR/conf.d/zz-horus.ini"

# Estructura del monorepo: /srv/horus/api (Laravel) y /srv/horus/ai/data (conocimiento compartido con Horus AI),
# de modo que `dirname(base_path()).'/ai/data/knowledge.json'` resuelva sin variables extra.
WORKDIR /srv/horus/api

# Dependencias primero (capa reutilizable mientras no cambie composer.lock). Sin dependencias de desarrollo.
COPY apps/api/composer.json apps/api/composer.lock apps/api/composer.phar ./
RUN php composer.phar install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY apps/api/ ./
COPY apps/ai/data/knowledge.json /srv/horus/ai/data/knowledge.json

RUN php composer.phar dump-autoload --optimize --no-dev --no-interaction \
    && rm composer.phar \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && ln -sfn ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache \
    && sed -i 's/\r$//' docker/entrypoint.sh docker/Caddyfile \
    && chmod +x docker/entrypoint.sh

# Caddy/FrankenPHP guarda su estado aquí; debe poder escribirlo el usuario sin privilegios.
ENV XDG_CONFIG_HOME=/srv/horus/caddy/config \
    XDG_DATA_HOME=/srv/horus/caddy/data \
    HORUS_PROCESO=web \
    PORT=8080
RUN mkdir -p /srv/horus/caddy/config /srv/horus/caddy/data && chown -R www-data:www-data /srv/horus/caddy

EXPOSE 8080

# El entrypoint arranca como root solo para ajustar permisos del volumen de Railway y luego cede a www-data.
ENTRYPOINT ["/srv/horus/api/docker/entrypoint.sh"]
CMD []
