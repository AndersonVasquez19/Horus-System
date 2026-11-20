#!/bin/sh
# Arranque de la imagen de Laravel en Railway. HORUS_PROCESO elige el proceso: web | worker | scheduler.
# Nunca migra, siembra ni genera APP_KEY: esas operaciones son manuales y requieren autorización del propietario.
set -eu

cd /srv/horus/api

falta=""
for variable in APP_KEY HORUS_WEB_URL HORUS_WEB_SERVICE_TOKEN; do
    eval "valor=\${$variable:-}"
    [ -n "$valor" ] || falta="$falta $variable"
done
if [ -n "$falta" ]; then
    echo "[horus] Faltan variables obligatorias:$falta. Revisa docs/despliegue-vercel-railway.md." >&2
    exit 1
fi
if [ "${#HORUS_WEB_SERVICE_TOKEN}" -lt 32 ]; then
    echo "[horus] HORUS_WEB_SERVICE_TOKEN debe tener 32 caracteres o más." >&2
    exit 1
fi

# El volumen de Railway se monta como root sobre storage/app/public: se ajusta su dueño antes de ceder privilegios.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

como_www() {
    setpriv --reuid=www-data --regid=www-data --init-groups "$@"
}

# La configuración se cachea en cada arranque con las variables del servicio (no en la imagen: no contiene secretos).
como_www php artisan config:cache --no-interaction
como_www php artisan route:cache --no-interaction

case "${HORUS_PROCESO:-web}" in
    web)
        exec setpriv --reuid=www-data --regid=www-data --init-groups \
            frankenphp run --config /srv/horus/api/docker/Caddyfile --adapter caddyfile
        ;;
    worker)
        # Los reintentos los define cada job. --max-time recicla el proceso cada hora (Railway lo reinicia).
        exec setpriv --reuid=www-data --regid=www-data --init-groups \
            php artisan queue:work "${QUEUE_CONNECTION:-database}" \
                --queue="${HORUS_COLAS:-default}" --sleep=3 --max-time=3600 --no-interaction
        ;;
    scheduler)
        exec setpriv --reuid=www-data --regid=www-data --init-groups \
            php artisan schedule:work --no-interaction
        ;;
    *)
        echo "[horus] HORUS_PROCESO no válido: '${HORUS_PROCESO}'. Usa web, worker o scheduler." >&2
        exit 1
        ;;
esac
