<?php

namespace App\Services;

use App\Models\Photo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class PhotoThumbnail
{
    public function path(Photo $photo): string
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($photo->path), 404, 'La fotografía ya no está disponible.');
        $key = hash('sha256', $photo->path.'|'.$disk->lastModified($photo->path).'|'.$disk->size($photo->path));
        $path = 'photo-thumbnails/'.$key.'.jpg';
        if (! $disk->exists($path)) {
            Cache::lock('thumbnail-'.$key, 30)->block(10, function () use ($disk, $photo, $path) {
                if ($disk->exists($path)) {
                    return;
                }
                $source = @imagecreatefromstring($disk->get($photo->path));
                abort_if($source === false, 404, 'No se pudo generar la miniatura.');
                try {
                    $scale = min(1, 240 / max(imagesx($source), imagesy($source)));
                    $width = max(1, (int) round(imagesx($source) * $scale));
                    $height = max(1, (int) round(imagesy($source) * $scale));
                    $thumb = imagecreatetruecolor($width, $height);
                    imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
                    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
                    ob_start();
                    imagejpeg($thumb, null, 70);
                    $bytes = ob_get_clean();
                    imagedestroy($thumb);
                    if (! $disk->put($path, $bytes)) {
                        throw new \RuntimeException('No se pudo guardar la miniatura.');
                    }
                } finally {
                    imagedestroy($source);
                }
            });
        }

        return $disk->path($path);
    }
}
