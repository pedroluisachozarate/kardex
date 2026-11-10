<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Intervention extends Model
{
    protected $table = 'interventions';

    protected $guarded = [];

    protected $casts = ['snapshot' => 'array', 'measurements' => 'array', 'parts' => 'array', 'date' => 'date', 'finalized_at' => 'datetime'];

    public function getStatusLabelAttribute(): string
    {
        return ['borrador' => 'Borrador', 'revision' => 'En revisión', 'observado' => 'Observado', 'finalizado' => 'Finalizado'][$this->status] ?? ucfirst($this->status);
    }

    public function asset()
    {
        return $this->belongsTo(ProjectEquipment::class, 'project_equipment_id');
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function reviewValidationMessage(): ?string
    {
        if (! $this->activities()->exists() || $this->activities()->where('result', 'pendiente')->exists()) {
            return 'Completa el resultado de todas las actividades antes de enviar a revisión.';
        }
        if ($this->activities()->where('result', 'no_realizado')->get()->contains(fn ($activity) => blank($activity->notes))) {
            return 'Indica el motivo de las actividades no realizadas.';
        }
        foreach ($this->asset->template->fields as $field) {
            if ($field['required'] && blank($this->asset->technical_data[$field['name']]['value'] ?? null)) {
                return 'Completa los campos técnicos obligatorios antes de enviar a revisión.';
            }
        }
        if (blank($this->conclusions)) {
            return 'Completa las conclusiones antes de enviar a revisión.';
        }

        return null;
    }

    public function getProgressAttribute()
    {
        $items = $this->activities;
        $applicable = $items->where('result', '!=', 'no_aplica');

        return $items->isEmpty() ? 0 : ($applicable->isEmpty() ? 100 : (int) round(100 * $applicable->where('result', 'realizado')->count() / $applicable->count()));
    }
}
