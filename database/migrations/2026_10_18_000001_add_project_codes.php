<?php

use App\Services\ProjectCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_codes', function (Blueprint $table) {
            $table->char('code', 2)->primary();
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->char('code', 2)->nullable()->unique();
        });
        DB::transaction(function () {
            foreach (DB::table('projects')->orderBy('id')->get() as $project) {
                DB::table('projects')->where('id', $project->id)->update(['code' => ProjectCode::reserve()]);
                $assets = DB::table('project_equipment')->where('project_id', $project->id)->orderBy('number')->get();
                $offset = ($assets->max('number') ?? 0) + $assets->count() + 1;
                foreach ($assets as $index => $asset) {
                    DB::table('project_equipment')->where('id', $asset->id)->update(['number' => $offset + $index]);
                }
                foreach ($assets as $index => $asset) {
                    DB::table('project_equipment')->where('id', $asset->id)->update(['number' => $index + 1]);
                }
                DB::table('projects')->where('id', $project->id)->update(['next_kardex' => $assets->count() + 1]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
        Schema::dropIfExists('project_codes');
    }
};
