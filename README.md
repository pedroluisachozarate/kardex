# Kardex

Sistema de gestión de mantenimiento de equipos por proyecto, con Kardex, actividades, evidencias fotográficas y exportación PDF.

## Requisitos

- PHP 8.2 o superior con GD, SQLite y las extensiones requeridas por Laravel.
- Composer y Node.js para instalar dependencias y generar los recursos.

## Instalación local

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
npm ci
node scripts/build-css.mjs
powershell -ExecutionPolicy Bypass -File serve.ps1
```

Configura la conexión a la base de datos en `.env` antes de ejecutar las migraciones. El servidor local inicia en http://127.0.0.1:8000.

## Pruebas

```powershell
php artisan test
```

Las credenciales, bases de datos locales, fotos, copias de seguridad y dependencias instaladas no se incluyen en el repositorio.
