# Compose supplies the backend image as the "backend" build context.
FROM backend

EXPOSE 8080
CMD ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]
