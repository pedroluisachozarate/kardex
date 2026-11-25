<?php

namespace App\Services;

use App\Models\MobileDraft;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SyncMobileDraft
{
    public function run(MobileDraft $draft, string $token): void
    {
        Cache::lock('mobile-draft-'.$draft->id, 300)->block(1, function () use ($draft, $token) {
            $draft->refresh();
            if ($draft->sync_status === 'synced') {
                return;
            }
            $draft->update(['sync_status' => 'syncing', 'sync_error' => null]);
            try {
                $gateway = app(MobileGateway::class);
                $receipt = $draft->receipt;
                if (! $receipt) {
                    $response = $gateway->request($token)->post('drafts', array_replace($draft->payload, ['uuid' => $draft->id]));
                    $receipt = $response->throw()->json();
                    if (($receipt['uuid'] ?? null) !== $draft->id || empty($receipt['intervention_id'])) {
                        throw new \RuntimeException('Confirmación de sincronización no válida.');
                    }
                    $draft->update(['receipt' => $receipt]);
                }
                $photos = $draft->photos;
                foreach ($photos as $index => $photo) {
                    if (! empty($photo['receipt'])) {
                        continue;
                    }
                    $disk = Storage::disk('local');
                    if (! $disk->exists($photo['path'])) {
                        throw new \RuntimeException('No se encuentra una foto local. No se eliminará el borrador.');
                    }
                    $handle = fopen($disk->path($photo['path']), 'rb');
                    try {
                        $response = $gateway->request($token)->attach('photo', $handle, $photo['name'])->post('drafts/'.$receipt['intervention_id'].'/photos', ['uuid' => $photo['uuid'], 'activity_index' => $photo['activity_index']]);
                        $photoReceipt = $response->throw()->json();
                    } finally {
                        fclose($handle);
                    }
                    if (($photoReceipt['uuid'] ?? null) !== $photo['uuid'] || empty($photoReceipt['photo_id'])) {
                        throw new \RuntimeException('La foto no fue confirmada por el servidor.');
                    }
                    $photos[$index]['receipt'] = $photoReceipt;
                    $draft->update(['photos' => $photos]);
                }
                $complete = $gateway->request($token)->post('drafts/'.$receipt['intervention_id'].'/complete', ['photos' => array_column($photos, 'uuid')])->throw()->json();
                if (($complete['complete'] ?? false) !== true || ($complete['intervention_id'] ?? null) !== $receipt['intervention_id']) {
                    throw new \RuntimeException('El servidor todavía no confirmó todas las fotos.');
                }
                $draft->update(['sync_status' => 'synced', 'sync_error' => null]);
            } catch (\Throwable $e) {
                $draft->update(['sync_status' => 'local', 'sync_error' => 'No se completó la sincronización. El borrador y las fotos siguen guardados en este dispositivo. Verifica conexión, sesión y configuración del proyecto.']);
                throw $e;
            }
        });
    }
}
