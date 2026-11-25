<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_tokens', fn (Blueprint $table) => $table->string('app_state_hash', 64)->nullable()->index());
        Schema::create('mobile_handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->string('state_hash', 64);
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_handoffs');
        Schema::table('mobile_tokens', function (Blueprint $table) {
            $table->dropIndex(['app_state_hash']);
            $table->dropColumn('app_state_hash');
        });
    }
};
