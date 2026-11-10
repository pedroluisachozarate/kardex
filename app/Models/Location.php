<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $table = 'locations';

    protected $guarded = [];

    protected $casts = [];

    public function getLabelAttribute()
    {
        return implode(' / ', array_filter([$this->building, $this->floor, $this->area]));
    }
}
