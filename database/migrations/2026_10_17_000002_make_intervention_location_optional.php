<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_equipment', fn (Blueprint $table) => $table->unsignedBigInteger('location_id')->nullable()->change());
        Schema::table('interventions', fn (Blueprint $table) => $table->string('location_text', 500)->nullable());
        DB::table('interventions')->join('project_equipment', 'interventions.project_equipment_id', '=', 'project_equipment.id')
            ->join('locations', 'project_equipment.location_id', '=', 'locations.id')
            ->select('interventions.id', 'locations.building', 'locations.floor', 'locations.area')
            ->orderBy('interventions.id')->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('interventions')->where('id', $row->id)->update(['location_text' => implode(' / ', array_filter([$row->building, $row->floor, $row->area]))]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('interventions', fn (Blueprint $table) => $table->dropColumn('location_text'));
        // Keep the legacy link nullable: newly registered equipment has no location catalog entry.
    }
};
