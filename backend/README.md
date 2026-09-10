# DinoStar: Laravel y Reverb (actividad #6)

Desde la raiz del repositorio, con Docker Desktop en modo Linux y Docker Compose 2.17 o posterior:

```powershell
# Solo la primera vez: genera .env privado sin sobrescribir uno existente.
./reverb/prepare-env.ps1
docker compose up -d --build --wait backend reverb
docker compose ps
./reverb/verify.ps1
```

Laravel queda en http://localhost:8000 y su comprobacion de salud en http://localhost:8000/up. Reverb escucha WebSocket en localhost:8080 (no es una pagina web). Los contenedores se llaman `dinostar-backend` y `dinostar-reverb`.

Ambos usan PHP 8.4 con `pcntl`, `pdo_pgsql` y las extensiones requeridas por Laravel. El Dockerfile de Reverb hereda la imagen construida del backend mediante `additional_contexts`, por eso la construccion se realiza con Compose desde la raiz. Composer instala las versiones de `composer.lock`; su plataforma objetivo es PHP 8.4 aunque el PHP del equipo sea diferente.

La prueba abre un WebSocket, se suscribe a un canal temporal y comprueba un evento enviado desde Laravel. No escribe datos en la base. Si se cambia `REVERB_APP_KEY`, pasar el nuevo valor con `./reverb/verify.ps1 -AppKey valor`.

El `.env` de la raiz suministra las claves de ambos contenedores; `backend/.env` se reserva para Artisan local y no se copia a la imagen. Nunca confirmar ninguno de esos archivos privados. Los ejemplos no contienen secretos. Sesiones y cache usan archivos, y las colas son sincronas para esta entrega: no se ejecutan migraciones ni se requiere PostgreSQL para arrancar.

Los cambios de codigo requieren reconstruir las imagenes. No se montan carpetas que oculten `vendor`. Este Compose entrega unicamente Laravel y Reverb; la integracion del broker, PostgreSQL y frontend corresponde a otras actividades del equipo. Las variables de conexion a esos servicios preparan una integracion futura y no implican que existan en esta entrega. `pcntl` prepara el manejo de procesos/senales; no implementa por si mismo un cliente MQTT. La clave de base de datos se lee opcionalmente de `POSTGRES_PASSWORD`; no se incluye ninguna contrasena en Compose.

Para consultar fallos: `docker compose logs --tail=50 backend reverb`. Para detener solo estos servicios sin borrar volumenes: `docker compose stop backend reverb`.

Esta es una configuracion de desarrollo local con el servidor integrado de Laravel y canales publicos de prueba; no constituye un despliegue de produccion ni implementa autenticacion del dominio.

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
