<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->char('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('mobile_drafts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('owner_id');
            $t->json('payload');
            $t->json('photos');
            $t->string('sync_status')->default('local');
            $t->json('receipt')->nullable();
            $t->text('sync_error')->nullable();
            $t->timestamps();
        });
        Schema::table('interventions', function (Blueprint $t) {
            $t->uuid('mobile_uuid')->nullable()->unique();
            $t->boolean('mobile_sync_complete')->default(true);
        });
        Schema::table('photos', function (Blueprint $t) {
            $t->uuid('mobile_uuid')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $t) {
            $t->dropUnique(['mobile_uuid']);
            $t->dropColumn('mobile_uuid');
        });
        Schema::table('interventions', function (Blueprint $t) {
            $t->dropUnique(['mobile_uuid']);
            $t->dropColumn(['mobile_uuid', 'mobile_sync_complete']);
        });
        Schema::dropIfExists('mobile_drafts');
        Schema::dropIfExists('mobile_tokens');
    }
};
