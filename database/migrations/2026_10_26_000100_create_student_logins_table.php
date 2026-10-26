<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per login code a student asked for over WhatsApp; the same row becomes the browser session once the code is used.
        Schema::create('student_logins', function (Blueprint $table) {
            $table->id();
            $table->string('nim', 40)->index();
            $table->string('phone', 20)->nullable()->index(); // 62812…, the number that messaged the library (null when WhatsApp hid it)
            $table->string('code_hash', 64)->unique();
            $table->timestamp('code_expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('session_hash', 64)->nullable()->unique();
            $table->timestamp('session_expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_logins');
    }
};
