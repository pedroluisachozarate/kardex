<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Template extends Model
{
    protected $table = 'templates';

    protected $guarded = [];

    protected $casts = ['fields' => 'array', 'preventive' => 'array', 'corrective' => 'array'];

    public function hasKardex(): bool
    {
        return ProjectEquipment::where('template_id', $this->id)->exists();
    }

    public function activitiesLocked(string $kind): bool
    {
        return Intervention::whereHas('asset', fn ($query) => $query->where('template_id', $this->id))
            ->where('type', $kind === 'preventive' ? 'preventivo' : 'correctivo')
            ->where(fn ($query) => $query->whereHas('activities')->orWhere('status', '!=', 'borrador'))->exists();
    }

    public function fullyLocked(): bool
    {
        return $this->hasKardex() && $this->activitiesLocked('preventive') && $this->activitiesLocked('corrective');
    }

    public function updateUnlockedData(array $data): void
    {
        if ($this->hasKardex()) {
            $custom = fn ($fields) => array_values(array_filter($fields, fn ($field) => ! self::identityColumn($field['name'])));
            abort_if($data['name'] !== $this->name || $custom($data['fields']) != $custom($this->fields), 409, 'Los campos técnicos ya tienen Kardex y no se pueden editar.');
            unset($data['name'], $data['fields']);
        }
        foreach (['preventive', 'corrective'] as $kind) {
            if ($this->activitiesLocked($kind)) {
                abort_if($data[$kind] !== $this->$kind, 409, 'Las actividades de este mantenimiento ya están en uso.');
                unset($data[$kind]);
            }
        }
        $this->update($data);
        foreach (['preventive' => 'preventivo', 'corrective' => 'correctivo'] as $kind => $type) {
            if (! array_key_exists($kind, $data)) {
                continue;
            }
            $drafts = Intervention::whereHas('asset', fn ($query) => $query->where('template_id', $this->id))
                ->where('type', $type)->where('status', 'borrador')->doesntHave('activities')->lockForUpdate()->get();
            foreach ($drafts as $draft) {
                foreach ($data[$kind] as $line) {
                    $parts = explode('|', $line, 2);
                    $draft->activities()->create(['name' => trim($parts[0]), 'procedure' => trim($parts[1] ?? '')]);
                }
            }
        }
    }

    public static function identityColumn(string $name): ?string
    {
        $name = preg_replace('/[^a-z0-9]/', '', Str::ascii(mb_strtolower($name)));

        return match ($name) {
            'nombre', 'nombreequipo', 'nombredelequipo' => 'name',
            'marca' => 'brand', 'modelo' => 'model',
            'serie', 'nroserie', 'nrodeserie', 'numeroserie', 'numerodeserie', 'nodeserie' => 'serial',
            'codigointerno' => 'code',
            default => null,
        };
    }

    public static function baseFields(): array
    {
        return array_map(fn ($name) => ['name' => $name, 'type' => 'texto', 'unit' => '', 'required' => false, 'options' => []],
            ['Marca', 'Modelo', 'N.º de serie', 'Código interno']);
    }

    public static function orderedTechnicalData(array $technical, array $equipment = []): array
    {
        $technical = array_filter($technical, fn ($name) => self::identityColumn($name) !== 'name', ARRAY_FILTER_USE_KEY);
        $base = [];
        foreach (self::baseFields() as $field) {
            $column = self::identityColumn($field['name']);
            $value = $equipment[$column] ?? null;
            foreach ($technical as $name => $data) {
                if (self::identityColumn($name) === $column) {
                    if (filled($data['value'] ?? null)) {
                        $value = $data['value'];
                    }
                    unset($technical[$name]);
                }
            }
            $base[$field['name']] = ['value' => $value, 'unit' => ''];
        }

        return $base + $technical;
    }

    public function getFieldsAttribute($value): array
    {
        $fields = json_decode($value ?? '[]', true) ?: [];
        $seen = [];
        $result = [];
        foreach ($fields as $field) {
            $column = self::identityColumn($field['name']);
            if ($column === 'name') {
                continue;
            }
            if ($column) {
                if (isset($seen[$column])) {
                    continue;
                }
                $seen[$column] = true;
                $field = [...$field, 'type' => 'texto', 'required' => false, 'unit' => '', 'options' => []];
            }
            $result[] = $field;
        }
        foreach (self::baseFields() as $field) {
            if (! isset($seen[self::identityColumn($field['name'])])) {
                $result[] = $field;
            }
        }

        return $result;
    }
}
