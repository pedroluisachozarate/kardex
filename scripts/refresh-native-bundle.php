<?php
// Refresh application-only files after a verified full build; native dependencies remain unchanged.
$root = dirname(__DIR__);
$build = $root.'/native-build';
$version = (int)($argv[1] ?? 0);
if ($version < 3) { throw new RuntimeException('Indica un número de compilación nuevo.'); }
$files = ['public/js/kardex.js', 'app/Http/Controllers/ActivityPhotoUploadController.php', 'resources/views/partials/equipment-register.blade.php', 'public/js/maintenance-gallery.js', 'resources/views/partials/photo-gallery.blade.php', 'resources/views/partials/maintenance-detail.blade.php', 'public/js/dashboard-calendar.js', 'public/css/kardex-editor.css', 'public/js/workspace-experience.js', 'public/css/workspace-experience.css', 'public/js/kardex-alerts.js','app/Http/Controllers/MobileAppController.php', 'app/Http/Controllers/AuthController.php', 'app/Livewire/MobileWorkspace.php', 'app/Livewire/ProjectWorkspace.php', 'resources/views/dashboard.blade.php', 'resources/views/partials/mobile-summary-pagination.blade.php', 'resources/views/partials/mobile-editor.blade.php', 'resources/views/partials/state-filter-options.blade.php', 'resources/views/livewire/project-workspace.blade.php', 'resources/views/mobile-entry.blade.php', 'resources/views/mobile-return.blade.php', 'resources/views/layout.blade.php', 'resources/views/livewire/mobile-workspace.blade.php', 'resources/views/livewire/kardex-registration-sheet.blade.php', 'public/css/mobile-offline.css', 'public/css/native-shell.css', 'public/css/kardex.css', 'public/css/kardex-theme.css', 'public/css/kardex.bundle.min.css', 'public/css/kardex.bundle.manifest.json', 'resources/views/mobile.blade.php', 'resources/views/components/icon.blade.php', 'config/mobile.php', 'routes/web.php', 'resources/views/mobile-desktop-bootstrap.blade.php', 'public/js/desktop-offline.js'];
foreach ($files as $file) { if (! is_readable($root.'/'.$file)) { throw new RuntimeException('Falta el archivo '.$file); } }
$zip = new ZipArchive;
$archive = $build.'/nativephp/android/app/src/main/assets/laravel_bundle.zip';
if ($zip->open($archive) !== true) { throw new RuntimeException('Primero genera la compilación completa.'); }

foreach ($files as $file) {
    $source = file_get_contents($root.'/'.$file);
    if ($source === false || file_put_contents($build.'/'.$file, $source) === false || ! $zip->addFromString($file, $source)) { throw new RuntimeException('No se pudo actualizar '.$file); }
}
$environment = preg_replace('/^NATIVEPHP_APP_VERSION_CODE=.*$/m', 'NATIVEPHP_APP_VERSION_CODE='.$version, file_get_contents($build.'/.env'));
file_put_contents($build.'/.env', $environment);
$zip->addFromString('.env', $environment);
if (! $zip->close()) { throw new RuntimeException('No se pudo guardar el paquete.'); }
$metaPath = $build.'/nativephp/android/app/src/main/assets/bundle_meta.json';
$meta = json_decode(file_get_contents($metaPath), true, flags: JSON_THROW_ON_ERROR);
$meta['version_code'] = $version;
file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$gradlePath = $build.'/nativephp/android/app/build.gradle.kts';
$gradle = preg_replace('/versionCode = [0-9]+/', 'versionCode = '.$version, file_get_contents($gradlePath));
file_put_contents($gradlePath, $gradle);
echo "Paquete de aplicación actualizado; compilación $version.\n";