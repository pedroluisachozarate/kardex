<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photos', fn (Blueprint $table) => $table->timestamp('optimized_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('photos', fn (Blueprint $table) => $table->dropColumn('optimized_at'));
    }
};
