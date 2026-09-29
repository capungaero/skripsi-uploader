<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->timestamps();
        });

        Schema::create('faculties', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('dspace_collection_uuid')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('study_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('level', 10)->default('S1');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('form_configs', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year', 20)->unique();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_config_id')->constrained()->cascadeOnDelete();
            $table->string('key', 50);
            $table->string('label');
            // text, email, tel, number, textarea, select, faculty, program
            $table->string('type', 20)->default('text');
            $table->boolean('required')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('active')->default(true);
            $table->string('placeholder')->nullable();
            $table->string('help')->nullable();
            $table->json('options')->nullable();
            $table->string('pattern')->nullable();
            $table->unsignedInteger('max_length')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['form_config_id', 'key']);
        });

        Schema::create('ai_criteria', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('label');
            $table->text('instruction');
            $table->json('page_keywords')->nullable();
            $table->boolean('needs_image')->default(false);
            $table->unsignedTinyInteger('min_score')->default(70);
            $table->boolean('required')->default(true);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->string('public_token', 64)->unique();
            $table->foreignId('form_config_id')->nullable()->constrained()->nullOnDelete();
            $table->string('academic_year', 20);
            $table->string('nim', 30);
            $table->string('nama');
            $table->string('no_wa', 30);
            $table->foreignId('faculty_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('study_program_id')->nullable()->constrained()->nullOnDelete();
            $table->text('judul');
            $table->json('extra')->nullable();
            $table->string('status', 30)->default('checking')->index();
            $table->boolean('counts_as_attempt')->default(true);
            $table->string('original_filename');
            $table->unsignedBigInteger('file_size');
            $table->string('file_hash', 64);
            $table->string('staging_path')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedTinyInteger('ai_score')->nullable();
            $table->json('rejection_reasons')->nullable();
            $table->string('cloud_provider', 20)->nullable();
            $table->string('cloud_file_id')->nullable();
            $table->string('cloud_path')->nullable();
            $table->string('share_url')->nullable();
            $table->boolean('cloud_locked')->default(false);
            $table->text('admin_note')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('dspace_item_uuid')->nullable();
            $table->string('dspace_handle')->nullable();
            $table->timestamp('deposited_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['nim', 'academic_year']);
            $table->index(['faculty_id', 'status']);
        });

        Schema::create('submission_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->json('criteria')->nullable();
            $table->unsignedTinyInteger('overall_score')->nullable();
            $table->boolean('passed')->default(false);
            $table->string('model')->nullable();
            $table->text('raw_response')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 20); // admin, student, system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->string('action', 60)->index();
            $table->foreignId('submission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('cloud_folders', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('path');
            $table->string('remote_id');
            $table->timestamps();
            $table->unique(['provider', 'path']);
        });
    }

    public function down(): void
    {
        foreach (['cloud_folders', 'activity_logs', 'submission_checks', 'submissions', 'ai_criteria',
            'form_fields', 'form_configs', 'study_programs', 'faculties', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
