<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->string('kliring_status', 20)->nullable()->index();
            $table->timestamp('kliring_at')->nullable();
            $table->text('kliring_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['kliring_status']);
            $table->dropColumn(['kliring_status', 'kliring_at', 'kliring_error']);
        });
    }
};
