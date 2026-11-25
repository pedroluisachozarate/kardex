<?php

namespace App\Http\Controllers;

use App\Models\Intervention;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\FileUploadController;

class ActivityPhotoUploadController extends Controller
{
    public function __invoke(Request $request, Project $project)
    {
        $user = $request->user();
        abort_unless($user->role === 'tecnico' && $project->users()->where('users.id', $user->id)->exists(), 403);
        if ($request->isJson() && $request->input('encoded_photo') !== null) {
            $encoded = $request->validate([
                'encoded_photo' => 'required|array:name,data',
                'encoded_photo.name' => 'required|string|max:255',
                'encoded_photo.data' => 'required|string|max:11184812',
            ], ['encoded_photo.data.max' => 'Cada foto debe pesar como máximo 8 MB.']);
            $bytes = base64_decode($encoded['encoded_photo']['data'], true);
            if ($bytes === false || $bytes === '' || strlen($bytes) > 8 * 1024 * 1024) {
                throw ValidationException::withMessages(['files.0' => 'La foto no es válida o supera los 8 MB. Vuelve a seleccionarla.']);
            }
            $temporary = tmpfile();
            abort_if($temporary === false, 500, 'No se pudo preparar la foto. Intenta nuevamente.');
            try {
                abort_if(fwrite($temporary, $bytes) !== strlen($bytes), 500, 'No se pudo recibir la foto completa.');
                $name = basename(str_replace('\\', '/', $encoded['encoded_photo']['name']));
                $file = new UploadedFile(stream_get_meta_data($temporary)['uri'], $name, null, null, true);
                return $this->store($request, $project, [$file]);
            } finally {
                fclose($temporary);
            }
        }

        return $this->store($request, $project, $request->file('files'));
    }

    private function store(Request $request, Project $project, mixed $files)
    {
        $editing = $request->filled('intervention_id');
        $data = Validator::make([...$request->all(), 'files' => $files], [
            'template_id' => ['required', Rule::exists('templates', 'id')->where('project_id', $project->id)],
            'type' => ['required', Rule::in(['preventivo', 'correctivo'])],
            'activity_index' => 'required|integer|min:0',
            'intervention_id' => 'nullable|integer',
            'files' => 'required|array|min:1|max:10',
            'files.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:8192',
        ], [
            'template_id.required' => 'No se recibió el tipo de equipo. Recarga el Kardex e intenta nuevamente.',
            'type.required' => 'No se recibió el tipo de mantenimiento. Recarga el Kardex e intenta nuevamente.',
            'activity_index.required' => 'No se recibió la actividad de la foto. Recarga el Kardex e intenta nuevamente.',
            'files.required' => 'No se recibió la foto. Vuelve a seleccionarla e intenta nuevamente.',
            'files.*.uploaded' => 'No se pudo recibir la foto. Revisa su tamaño e intenta nuevamente.',
            'files.*.image' => 'Selecciona imágenes JPG, PNG o WebP.',
            'files.*.mimes' => 'Selecciona imágenes JPG, PNG o WebP.',
            'files.*.max' => 'Cada foto debe pesar como máximo 8 MB.',
            'files.max' => 'Puedes agregar hasta 10 fotos por selección.',
        ])->validate();
        $template = $project->templates()->findOrFail($data['template_id']);
        $lines = $data['type'] === 'preventivo' ? $template->preventive : $template->corrective;
        $intervention = null;
        if ($editing) {
            $intervention = Intervention::whereHas('asset', fn ($query) => $query->where('project_id', $project->id))->with('activities')->findOrFail($data['intervention_id']);
            abort_unless(in_array($intervention->status, ['borrador', 'observado']), 409);
            abort_unless($intervention->type === $data['type'] && $intervention->asset->template_id === $template->id, 422);
            $lines = $intervention->activities->pluck('name')->all();
        }
        abort_unless(array_key_exists($data['activity_index'], $lines), 422);
        $references = (new FileUploadController)->validateAndStore($files, FileUploadConfiguration::disk());

        return response()->json(['token' => Crypt::encryptString(json_encode([
            'user_id' => $request->user()->id,
            'project_id' => $project->id,
            'intervention_id' => $intervention?->id,
            'bucket' => $template->id.'_'.$data['type'],
            'index' => $data['activity_index'],
            'expires' => now()->addMinutes(30)->timestamp,
            'references' => $references->all(),
        ], JSON_THROW_ON_ERROR))]);
    }
}
