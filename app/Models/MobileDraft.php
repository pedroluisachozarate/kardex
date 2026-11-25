<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileDraft extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'photos' => 'array', 'receipt' => 'array'];
}
