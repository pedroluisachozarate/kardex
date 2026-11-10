<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectCode
{
    public static function reserve(): string
    {
        for ($index = 0; $index < 676; $index++) {
            $code = chr(65 + intdiv($index, 26)).chr(65 + $index % 26);
            if (DB::table('project_codes')->insertOrIgnore(['code' => $code])) {
                return $code;
            }
        }
        throw ValidationException::withMessages(['project' => 'Se agotaron los códigos únicos de dos letras.']);
    }
}
