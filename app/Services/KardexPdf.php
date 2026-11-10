<?php

namespace App\Services;

use App\Models\Intervention;
use App\Models\Photo;
use App\Models\Template;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class KardexPdf
{
    public function data(Intervention $i): array
    {

        $i->loadMissing('asset.project.client', 'activities.photos', 'activities.performer');

        $snapshot = $i->status === 'finalizado' ? ($i->snapshot ?? []) : [];

        $record = array_merge($i->toArray(), $snapshot['record'] ?? []);

        $activities = collect($snapshot['activities'] ?? $i->activities->map(fn ($a) => [

            ...$a->toArray(), 'performer_name' => $a->performer?->name,

        ])->all());

        $photos = [];

        foreach ($activities as $index => $activity) {

            foreach ($activity['photos'] ?? [] as $photo) {

                $source = null;
                $photo['path'] = isset($photo['id']) ? (Photo::find($photo['id'])?->path ?? $photo['path']) : $photo['path'];

                if (Storage::disk('local')->exists($photo['path'])) {

                    $bytes = Storage::disk('local')->get($photo['path']);

                    $image = @imagecreatefromstring($bytes);

                    if ($image !== false) {

                        $scale = min(1, 1000 / max(imagesx($image), imagesy($image)));

                        $thumb = imagescale($image, max(1, (int) (imagesx($image) * $scale)), max(1, (int) (imagesy($image) * $scale)));

                        ob_start();

                        imagejpeg($thumb, null, 85);

                        $source = 'data:image/jpeg;base64,'.base64_encode(ob_get_clean());

                        imagedestroy($thumb);

                        imagedestroy($image);

                    }

                }

                $photos[] = ['source' => $source, 'activity' => ($index + 1).'. '.$activity['name'], 'description' => $photo['description'] ?? ''];

            }

        }

        $technical = Template::orderedTechnicalData(
            $snapshot['technical'] ?? $i->asset->technical_data ?? [],
            $snapshot['equipment'] ?? $i->asset->equipment->toArray(),
        );

        return compact('i', 'record', 'activities', 'photos') + [

            'client' => $snapshot['client'] ?? $i->asset->project->client->name,

            'project' => $snapshot['project'] ?? $i->asset->project->name,

            'technical' => $technical,

        ];

    }

    public function render(Intervention $i)
    {

        $pdf = Pdf::loadView('print', $this->data($i))->setPaper('letter')->setOptions([

            'defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isPhpEnabled' => false,

        ]);

        $pdf->render();

        $canvas = $pdf->getDomPDF()->getCanvas();

        $font = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans', 'normal');

        $canvas->page_text(560, 762, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.36, 0.43, 0.47]);

        return $pdf;

    }
}
