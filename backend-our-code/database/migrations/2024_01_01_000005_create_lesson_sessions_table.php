<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject')->nullable();
            $table->enum('status', ['scheduled', 'active', 'paused', 'completed', 'cancelled'])
                  ->default('scheduled');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('duration_minutes')->storedAs(
                "EXTRACT(EPOCH FROM (ended_at - started_at)) / 60"
            )->nullable();
            // Итоговая агрегированная статистика урока
            $table->decimal('avg_engagement_score', 5, 2)->nullable();
            $table->decimal('min_engagement_score', 5, 2)->nullable();
            $table->decimal('max_engagement_score', 5, 2)->nullable();
            $table->integer('total_snapshots')->default(0);
            $table->integer('students_count')->default(0);
            $table->json('engagement_timeline')->default('[]');
            // [{"minute": 5, "avg_score": 78.5, "alert_count": 0}, ...]
            $table->json('meta')->default('{}');
            $table->timestamps();

            $table->index(['classroom_id', 'status']);
            $table->index(['started_at', 'ended_at']);
            $table->index('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_sessions');
    }
};
