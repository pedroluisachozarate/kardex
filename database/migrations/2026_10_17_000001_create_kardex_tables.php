<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('tecnico');
            $t->string('phone')->nullable();
            $t->boolean('active')->default(true);
            $t->boolean('must_change_password')->default(true);
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('contact')->nullable();
            $t->timestamps();
        });
        Schema::create('locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained();
            $t->string('building');
            $t->string('floor')->nullable();
            $t->string('area')->nullable();
            $t->timestamps();
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained();
            $t->string('name');
            $t->text('description');
            $t->unsignedInteger('next_kardex')->default(1);
            $t->timestamps();
        });
        Schema::create('project_user', function (Blueprint $t) {
            $t->foreignId('project_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->primary(['project_id', 'user_id']);
        });
        Schema::create('templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->string('name');
            $t->json('fields');
            $t->json('preventive');
            $t->json('corrective');
            $t->timestamps();
        });
        Schema::create('equipment', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('brand')->nullable();
            $t->string('model')->nullable();
            $t->string('serial')->nullable();
            $t->string('code')->nullable();
            $t->timestamps();
        });
        Schema::create('project_equipment', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->foreignId('equipment_id')->constrained('equipment');
            $t->foreignId('location_id')->constrained();
            $t->foreignId('template_id')->constrained();
            $t->unsignedInteger('number');
            $t->unsignedInteger('next_intervention')->default(1);
            $t->json('technical_data');
            $t->timestamps();
            $t->unique(['project_id', 'number']);
            $t->unique(['project_id', 'equipment_id']);
        });
        Schema::create('interventions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_equipment_id')->constrained('project_equipment');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('number');
            $t->string('type');
            $t->string('status')->default('borrador');
            $t->date('date');
            foreach (['fault', 'diagnosis', 'observations', 'conclusions', 'recommendations', 'fiscal', 'agent', 'review_note'] as $field) {
                $t->text($field)->nullable();
            }
            $t->json('measurements')->nullable();
            $t->json('parts')->nullable();
            $t->json('snapshot')->nullable();
            $t->timestamp('finalized_at')->nullable();
            $t->timestamps();
            $t->unique(['project_equipment_id', 'number']);
        });
        Schema::create('activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('intervention_id')->constrained();
            $t->string('name');
            $t->text('procedure')->nullable();
            $t->string('result')->default('pendiente');
            $t->text('notes')->nullable();
            $t->foreignId('performed_by')->nullable()->constrained('users');
            $t->date('performed_on')->nullable();
            $t->timestamps();
        });
        Schema::create('photos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('activity_id')->constrained();
            $t->foreignId('uploaded_by')->constrained('users');
            $t->string('path');
            $t->string('description')->nullable();
            $t->string('stage')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('intervention_id')->nullable()->constrained();
            $t->string('action');
            $t->json('details')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'photos', 'activities', 'interventions', 'project_equipment', 'equipment', 'templates', 'project_user', 'projects', 'locations', 'clients'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'phone', 'active', 'must_change_password']));
    }
};
