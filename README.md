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

## Aplicación Android

La aplicación abre el sistema completo del servidor dentro de Android y conserva las funciones y permisos de cada usuario. El servidor debe estar encendido y accesible; la prueba por Wi-Fi usa `http://192.168.10.2:8002`. Para distribución se debe configurar un dominio HTTPS.

Para trabajar sin conexión, el técnico inicia sesión y selecciona **Descargar mis proyectos** en el menú. Se descargan los proyectos asignados, plantillas y datos de los equipos; las fotos existentes no se descargan. En **Borradores del celular** puede registrar equipos o mantenimientos y adjuntar fotos. **Sincronizar** envía los borradores y evidencias al servidor sin duplicarlos al reintentar. La revisión, administración y consulta de fotos del servidor requieren conexión. Los archivos locales se conservan si falla una descarga o sincronización.

Instala las actualizaciones del APK sobre la aplicación existente: desinstalarla elimina sus datos locales. La aplicación de prueba requiere Android 13 o superior.

La compilación está aislada en `native-build`, excluida de Git. `scripts/prepare-native.ps1` recibe las rutas de PHP 8.4 y Composer y la URL del servidor; únicamente para pruebas autorizadas con una IP privada HTTP se agrega `-LocalNetwork`. No copia la configuración, base de datos, fotos ni respaldos del servidor. `scripts/configure-native.ps1` aplica la navegación al servidor, el cambio a trabajo local, la cámara, archivos y descargas PDF; `scripts/run-native.ps1` configura las herramientas y compila Android. Requiere Java 17, Android SDK/NDK y 7-Zip. El script `refresh-native-bundle.php` es exclusivo para actualizar los archivos que enumera después de una compilación completa; cambios de dependencias o clases nuevas requieren compilar el paquete completo de nuevo.

## Módulos descargados en la PC

Con `KARDEX_DESKTOP_ORIGIN` configurado en el servidor, los técnicos también ven **Descargar mis proyectos** y **Borradores del celular** en el menú de la web. `scripts/serve-desktop.ps1` ejecuta los mismos componentes, vistas y servicios de Android con PHP 8.4 y las dependencias de `native-build`, sin generar una APK. No hay una interfaz alternativa para el navegador.

La descarga utiliza la sesión web existente y una confirmación de un solo uso vinculada a la sesión del dispositivo. El marco de conexión solo intercambia el identificador aleatorio de esa sesión con el servidor configurado; no transmite credenciales ni tokens a la página padre. La APK conserva su destino local `http://127.0.0.1`; la PC usa exclusivamente el origen configurado. El servidor central mantiene `KARDEX_MOBILE_ENABLED=false`.

Para esta instalación el servidor central usa el puerto 8002 y los módulos de la PC el 8003. El proceso de la PC tiene una cookie distinta y guarda sus datos en `tmp/desktop-mobile/database.sqlite`; debe permanecer encendido para usar esos módulos desde el navegador. Los borradores locales de un celular siguen en ese celular; al sincronizar se registran en el servidor. Borrar o reemplazar la base de la PC elimina su copia local, por eso el script la conserva al reiniciar.
