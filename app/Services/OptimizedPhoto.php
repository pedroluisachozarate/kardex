<?php

namespace App\Services;

use App\Models\Photo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OptimizedPhoto
{
    public function path(Photo $photo): string
    {
        $disk = Storage::disk('local');
        $photo->refresh();
        abort_unless($disk->exists($photo->path), 404, 'La fotografía ya no está disponible.');
        if ($photo->optimized_at) {
            return $disk->path($photo->path);
        }
        try {
            Cache::lock('photo-promote-'.$photo->id, 60)->block(15, function () use ($photo, $disk) {
                $photo->refresh();
                if ($photo->optimized_at) {
                    return;
                }
                $original = $photo->path;
                $candidate = $this->generate($photo);
                $verified = @imagecreatefromstring(file_get_contents($candidate));
                if ($verified === false) {
                    throw new \RuntimeException('La imagen convertida no es válida.');
                }
                imagedestroy($verified);
                $relative = str_replace('\\', '/', substr($candidate, strlen($disk->path(''))));
                $relative = ltrim($relative, '/');
                DB::transaction(function () use ($photo, $disk, $original, $relative) {
                    $record = Photo::lockForUpdate()->findOrFail($photo->id);
                    if ($record->path !== $original) {
                        return;
                    }
                    $record->update(['path' => $relative, 'optimized_at' => now()]);
                    if ($relative !== $original) {
                        DB::afterCommit(function () use ($disk, $original) {
                            if (! Photo::where('path', $original)->exists()) {
                                $disk->delete($original);
                            }
                        });
                    }
                });
            });
        } catch (\Throwable $exception) {
            report($exception);
        }
        $photo->refresh();

        return $disk->path($photo->path);
    }

    protected function generate(Photo $photo): string
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($photo->path), 404, 'La fotografía ya no está disponible.');
        $key = hash('sha256', 'webp1920q85-v1|'.$photo->path.'|'.$disk->lastModified($photo->path).'|'.$disk->size($photo->path));
        $path = 'photo-optimized/'.$key.'.webp';
        if (! $disk->exists($path)) {
            Cache::lock('optimized-'.$key, 60)->block(15, function () use ($disk, $photo, $path) {
                if ($disk->exists($path)) {
                    return;
                }
                $source = @imagecreatefromstring($disk->get($photo->path));
                abort_if($source === false, 404, 'No se pudo abrir la fotografía.');
                $target = null;
                try {
                    $exif = function_exists('exif_read_data') ? @exif_read_data($disk->path($photo->path)) : false;
                    $orientation = (int) ($exif['Orientation'] ?? 1);
                    if (in_array($orientation, [2, 4, 5, 7])) {
                        imageflip($source, in_array($orientation, [4, 5]) ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
                    }
                    $angle = match ($orientation) {
                        3 => 180, 5, 6 => -90, 7, 8 => 90, default => 0
                    };
                    if ($angle) {
                        $rotated = imagerotate($source, $angle, 0);
                        imagedestroy($source);
                        $source = $rotated;
                    }
                    $scale = min(1, 1920 / max(imagesx($source), imagesy($source)));
                    $width = max(1, (int) round(imagesx($source) * $scale));
                    $height = max(1, (int) round(imagesy($source) * $scale));
                    $target = imagecreatetruecolor($width, $height);
                    imagealphablending($target, false);
                    imagesavealpha($target, true);
                    imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
                    imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
                    ob_start();
                    imagewebp($target, null, 85);
                    $bytes = ob_get_clean();
                    if (! $bytes || ! $disk->put($path, $bytes)) {
                        throw new \RuntimeException('No se pudo guardar la imagen optimizada.');
                    }
                } finally {
                    if ($target) {
                        imagedestroy($target);
                    }
                    imagedestroy($source);
                }
            });
        }

        // Avoid increasing the download size of images already compressed well.
        if ($disk->size($path) >= $disk->size($photo->path)) {
            $disk->delete($path);

            return $disk->path($photo->path);
        }

        return $disk->path($path);
    }
}
