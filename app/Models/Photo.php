<?php

namespace App\Models;

use App\Services\OptimizedPhoto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Photo extends Model
{
    protected $table = 'photos';

    protected $guarded = [];

    protected $casts = ['optimized_at' => 'datetime'];

    protected static function booted(): void
    {
        static::created(function (Photo $photo) {
            DB::afterCommit(function () use ($photo) {
                if ($record = Photo::find($photo->id)) {
                    try {
                        app(OptimizedPhoto::class)->path($record);
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                }
            });
        });
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}
