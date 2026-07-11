# syntax=docker/dockerfile:1
# ============================================================================
# Grimshade Backend — obraz produkcyjny (PHP 8.3-FPM). Identyczny wszędzie:
# lokalnie (docker compose), w CI i na produkcji.
# ============================================================================

# --- Etap 1: kod + zależności composer (z pełnym, zoptymalizowanym autoloaderem) ---
FROM composer:2 AS vendor
# INSTALL_DEV=true → z dev (pest/pint) do LOKALNYCH testów (compose ustawia to).
# Domyślnie false → obraz produkcyjny bez narzędzi deweloperskich.
ARG INSTALL_DEV=false
WORKDIR /app
COPY . .
RUN if [ "$INSTALL_DEV" = "true" ]; then \
      composer install --optimize-autoloader --no-scripts --no-interaction --prefer-dist; \
    else \
      composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --prefer-dist; \
    fi

# --- Etap 2: runtime (bez composera) ---------------------------------------
FROM php:8.3-fpm-alpine AS runtime

# Rozszerzenia PHP wymagane przez Laravel + Supabase Postgres.
RUN set -eux; \
    apk add --no-cache postgresql-libs libzip; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev libzip-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath zip; \
    apk del .build-deps

WORKDIR /var/www/html

# Cała aplikacja + vendor z etapu 1 (autoloader już zoptymalizowany).
COPY --from=vendor --chown=www-data:www-data /app /var/www/html

RUN chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 9000
CMD ["php-fpm"]
