<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectEquipment extends Model
{
    protected $table = 'project_equipment';

    protected $guarded = [];

    protected $casts = ['technical_data' => 'array'];

    public function getKardexCodeAttribute(): string
    {
        return $this->project->code.'-'.sprintf('%03d', $this->number);
    }

    public function getIdentityDataAttribute(): array
    {
        $data = $this->equipment->only('name', 'brand', 'model', 'serial', 'code');
        foreach ($this->technical_data ?? [] as $name => $field) {
            if (($column = Template::identityColumn($name)) && filled($field['value'] ?? null)) {
                $data[$column] = $field['value'];
            }
        }

        return $data;
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function equipment()
    {
        return $this->belongsTo(Equipment::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function template()
    {
        return $this->belongsTo(Template::class);
    }

    public function interventions()
    {
        return $this->hasMany(Intervention::class);
    }
}
