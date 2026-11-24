# syntax=docker/dockerfile:1
#
# Horus AI (FastAPI) para Railway. Solo debe ser alcanzable por la RED PRIVADA de Railway (Laravel lo llama por
# http://<servicio>.railway.internal:8001): no generes un dominio público para este servicio.
#
# CONTEXTO DE CONSTRUCCIÓN: la raíz del repositorio (comparte el .dockerignore con Laravel).
#   docker build -f apps/ai/Dockerfile -t horus-ai .
#
# Modo léxico (por defecto). El modo semántico necesita el modelo MiniLM (477 MB, no versionado) y ~3 GB de RAM;
# no se incluye en esta imagen.

FROM python:3.13.16-slim-bookworm

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /srv/horus-ai

# Dependencias fijadas con hash (requirements.lock: FastAPI, Uvicorn, Pydantic, HTTPX; sin PyTorch).
COPY apps/ai/requirements.lock ./
RUN pip install --require-hashes --no-deps -r requirements.lock

COPY apps/ai/app ./app
COPY apps/ai/data ./data

RUN useradd --system --uid 10001 --home-dir /srv/horus-ai horus && chown -R horus:horus /srv/horus-ai
# El contenedor arranca como root SOLO para entregar la carpeta del catálogo al usuario `horus` y bajar de privilegios
# antes de abrir el puerto: Railway monta los volúmenes como root (docs.railway.com/volumes#permissions) y, sin este
# paso, un volumen en /data no sería escribible. El proceso de la aplicación siempre corre como uid 10001.

# Dentro del contenedor el servicio debe escuchar fuera de loopback para que Railway lo alcance por la red privada
# (IPv4 e IPv6). El secreto HORUS_AI_SERVICE_TOKEN sigue siendo obligatorio: sin él /api/v1/* responde 503.
ENV HORUS_AI_HOST=:: \
    HORUS_AI_ALLOW_NON_LOOPBACK=true \
    PORT=8001

EXPOSE 8001

# `python -m app` valida la configuración antes de abrir el puerto. Railway inyecta PORT.
# 1) Si la carpeta del catálogo existe (p. ej. el volumen en /data), pasa a ser de `horus`.
# 2) `setpriv` (util-linux, ya incluido en la imagen) baja a uid/gid 10001 sin grupos extra y reemplaza al proceso.
CMD ["sh", "-c", "d=$(dirname \"${HORUS_AI_CATALOG_PATH:-/srv/horus-ai/catalog.json}\"); [ -d \"$d\" ] && chown 10001:10001 \"$d\"; exec setpriv --reuid=10001 --regid=10001 --clear-groups sh -c 'HORUS_AI_PORT=\"${PORT:-8001}\" exec python -m app'"]
