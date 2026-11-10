<?php

namespace App\Http\Controllers;

use App\Models\Intervention;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\FileUploadController;

class ActivityPhotoUploadController extends Controller
{
    public function __invoke(Request $request, Project $project)
    {
        $editing = $request->filled('intervention_id');
        $user = $request->user();
        abort_unless($user->role === 'tecnico' && $project->users()->where('users.id', $user->id)->exists(), 403);
        $data = $request->validate([
            'template_id' => ['required', Rule::exists('templates', 'id')->where('project_id', $project->id)],
            'type' => ['required', Rule::in(['preventivo', 'correctivo'])],
            'activity_index' => 'required|integer|min:0',
            'intervention_id' => 'nullable|integer',
            'files' => 'required|array|min:1|max:10',
            'files.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:8192',
        ], [
            'files.*.image' => 'Selecciona imágenes JPG, PNG o WebP.',
            'files.*.mimes' => 'Selecciona imágenes JPG, PNG o WebP.',
            'files.*.max' => 'Cada foto debe pesar como máximo 8 MB.',
            'files.max' => 'Puedes agregar hasta 10 fotos por selección.',
        ]);
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
        $references = (new FileUploadController)->validateAndStore($request->file('files'), FileUploadConfiguration::disk());

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
