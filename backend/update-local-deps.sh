#!/bin/sh
set -e

# Criterio de aceptación: Mensaje sencillo al usuario
echo "=========================================================="
echo " [INFO] Ejecutando script de actualizacion de dependencias..."
echo " Sincronizando entorno local con el contenedor backend..."
echo "=========================================================="

# 1. Crear carpetas necesarias en el entorno local/contenedor
mkdir -p bootstrap/cache \
         storage/framework/cache/data \
         storage/framework/sessions \
         storage/framework/views \
         storage/logs

chmod -R 777 storage bootstrap/cache

# 2. Verificar y sincronizar dependencias de Composer
# El codigo se comparte con el anfitrion, pero vendor vive en un volumen Docker.
# composer install respeta composer.lock y sincroniza ese volumen al arrancar.
if [ -f "composer.json" ]; then
    echo " [INFO] Sincronizando dependencias de Composer en el contenedor..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# 3. Limpiar cache de Laravel
php artisan config:clear || true
php artisan cache:clear || true

echo " [OK] Dependencias y entorno local actualizados correctamente."
echo "=========================================================="

# Continuar con el proceso principal del contenedor (php artisan serve)
exec "$@"
