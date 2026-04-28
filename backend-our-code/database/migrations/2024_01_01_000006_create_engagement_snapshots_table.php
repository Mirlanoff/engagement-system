<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Основная timeseries таблица — снэпшоты каждые N секунд
        // При 20 студентах, 5-сек интервале, 45-мин уроке: ~10 800 строк/урок
        Schema::create('engagement_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('lesson_sessions')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('classroom_id')->constrained()->cascadeOnDelete();
            $table->string('camera_id', 50);
            $table->timestamp('captured_at');

            // ── Итоговый балл ──────────────────────────────────
            $table->decimal('engagement_score', 5, 2);  // 0.00 – 100.00

            // ── Компоненты score ───────────────────────────────
            $table->decimal('gaze_score', 5, 2)->nullable();      // взгляд на доску
            $table->decimal('emotion_score', 5, 2)->nullable();   // позитивная эмоция
            $table->decimal('head_pose_score', 5, 2)->nullable(); // правильная поза
            $table->decimal('presence_score', 5, 2)->nullable();  // лицо видно

            // ── Сырые данные от ML ─────────────────────────────
            $table->string('emotion', 30)->nullable();
            // neutral | happy | sad | angry | fearful | disgusted | surprised
            $table->decimal('emotion_confidence', 4, 3)->nullable();

            $table->decimal('gaze_yaw', 6, 2)->nullable();   // горизонтальный угол
            $table->decimal('gaze_pitch', 6, 2)->nullable(); // вертикальный угол

            $table->decimal('head_yaw', 6, 2)->nullable();
            $table->decimal('head_pitch', 6, 2)->nullable();
            $table->decimal('head_roll', 6, 2)->nullable();

            $table->boolean('face_detected')->default(true);
            $table->decimal('face_confidence', 4, 3)->nullable();
            $table->integer('face_bbox_x')->nullable();
            $table->integer('face_bbox_y')->nullable();
            $table->integer('face_bbox_w')->nullable();
            $table->integer('face_bbox_h')->nullable();

            $table->decimal('processing_time_ms', 7, 2)->nullable();

            $table->timestamps();

            // ── Индексы для аналитических запросов ────────────
            $table->index(['session_id', 'captured_at']);
            $table->index(['student_id', 'captured_at']);
            $table->index(['classroom_id', 'captured_at']);
            $table->index('captured_at');
            $table->index('engagement_score');
        });

        // Агрегированные данные по минутам (для быстрой аналитики)
        Schema::create('engagement_aggregates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('lesson_sessions')->cascadeOnDelete();
            $table->foreignUuid('classroom_id')->constrained()->cascadeOnDelete();
            $table->timestamp('minute_at');  // начало минуты
            $table->integer('interval_minutes')->default(1);

            $table->decimal('avg_score', 5, 2);
            $table->decimal('min_score', 5, 2);
            $table->decimal('max_score', 5, 2);
            $table->decimal('std_dev', 5, 2)->nullable();
            $table->integer('students_detected');
            $table->integer('snapshots_count');

            // Распределение по диапазонам
            $table->integer('high_engagement_count')->default(0);   // 75–100
            $table->integer('medium_engagement_count')->default(0); // 50–74
            $table->integer('low_engagement_count')->default(0);    // 0–49

            $table->timestamps();

            $table->unique(['session_id', 'minute_at', 'interval_minutes']);
            $table->index(['classroom_id', 'minute_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_aggregates');
        Schema::dropIfExists('engagement_snapshots');
    }
};
