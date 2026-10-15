<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->string('review_path')->nullable()->index(); // rejected PDF kept for evaluation (file name in skripsi.rejected_dir)
            $table->timestamp('review_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['review_path']);
            $table->dropColumn(['review_path', 'review_at']);
        });
    }
};
