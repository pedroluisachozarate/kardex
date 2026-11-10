<?php

namespace App\Models;

use App\Services\ProjectCode;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $table = 'projects';

    protected $guarded = [];

    protected $casts = [];

    protected static function booted(): void
    {
        static::creating(function ($project) {
            $project->code = ProjectCode::reserve();
        });
        static::updating(function ($project) {
            if ($project->isDirty('code')) {
                $project->code = $project->getOriginal('code');
            }
        });
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    public function assets()
    {
        return $this->hasMany(ProjectEquipment::class);
    }

    public function templates()
    {
        return $this->hasMany(Template::class);
    }
}
