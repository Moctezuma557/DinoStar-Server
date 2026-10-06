FROM php:8.4-cli

# Dependencias del sistema y extensiones de PHP (Se mantienen exactamente igual)
RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip libpq-dev libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_pgsql pcntl mbstring dom xml xmlwriter \
    && rm -rf /var/lib/apt/lists/*

# Copiar ejecutable de Composer desde la imagen oficial
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar el código base
COPY . .

# Copiar el script de sincronización de dependencias y darle permisos de ejecución
COPY update-local-deps.sh /usr/local/bin/update-local-deps.sh
RUN chmod +x /usr/local/bin/update-local-deps.sh

EXPOSE 8000

# ENTRYPOINT ejecuta el script cada vez que el contenedor arranca
ENTRYPOINT ["/usr/local/bin/update-local-deps.sh"]

# CMD pasa como argumento al ENTRYPOINT (se ejecuta al final del script con exec "$@")
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
