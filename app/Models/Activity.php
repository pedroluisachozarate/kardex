<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $table = 'activities';

    protected $guarded = [];

    protected $casts = [];

    public function intervention()
    {
        return $this->belongsTo(Intervention::class);
    }

    public function photos()
    {
        return $this->hasMany(Photo::class);
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
