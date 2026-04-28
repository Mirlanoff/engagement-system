<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Алерты (автоматические уведомления) ─────────────────
        Schema::create('engagement_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('lesson_sessions')->cascadeOnDelete();
            $table->foreignUuid('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('student_id')->nullable()->constrained()->nullOnDelete();
            // null = алерт по всему классу, not null = по конкретному студенту

            $table->enum('type', [
                'low_class_engagement',    // класс упал ниже порога
                'low_student_engagement',  // студент упал ниже порога
                'student_absent',          // лицо не обнаружено N минут
                'rapid_decline',           // резкое падение за короткое время
                'prolonged_low',           // низкий уровень > X минут
                'anomaly_detected',        // аномальное поведение
            ]);

            $table->enum('severity', ['info', 'warning', 'critical'])->default('warning');
            $table->decimal('trigger_score', 5, 2)->nullable();
            $table->decimal('threshold_score', 5, 2)->nullable();
            $table->string('message');
            $table->json('context')->default('{}');

            $table->boolean('is_acknowledged')->default(false);
            $table->foreignUuid('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgement_note')->nullable();

            $table->timestamp('triggered_at')->useCurrent();
            $table->timestamps();

            $table->index(['session_id', 'triggered_at']);
            $table->index(['classroom_id', 'is_acknowledged']);
            $table->index('type');
        });

        // ── Пороги алертов (настраиваются по классу/школе) ──────
        Schema::create('alert_thresholds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('classroom_id')->nullable()->constrained()->nullOnDelete();
            // null = глобальные настройки школы

            $table->decimal('low_class_threshold', 5, 2)->default(50.00);
            $table->decimal('low_student_threshold', 5, 2)->default(30.00);
            $table->integer('absent_minutes_threshold')->default(3);
            $table->integer('prolonged_low_minutes')->default(10);
            $table->decimal('rapid_decline_delta', 5, 2)->default(25.00);
            $table->integer('rapid_decline_window_seconds')->default(60);

            $table->boolean('notify_supervisor')->default(true);
            $table->boolean('notify_teacher')->default(true);
            $table->boolean('sound_alert')->default(false);

            $table->timestamps();

            $table->unique(['school_id', 'classroom_id']);
        });

        // ── AI Рекомендации (генерируются Claude API) ────────────
        Schema::create('ai_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('lesson_sessions')->cascadeOnDelete();
            $table->foreignUuid('generated_for')->constrained('users')->cascadeOnDelete();
            // кому адресована рекомендация

            $table->enum('type', [
                'post_lesson_summary',   // итог после урока
                'realtime_suggestion',   // подсказка во время урока
                'weekly_analysis',       // еженедельный анализ
                'student_insight',       // персонально о студенте
            ]);

            $table->text('content');           // Markdown текст рекомендации
            $table->json('key_insights')->default('[]');  // массив ключевых инсайтов
            $table->json('action_items')->default('[]');  // конкретные шаги

            $table->decimal('session_avg_score', 5, 2)->nullable();
            $table->json('input_data_summary')->default('{}'); // данные, на которых строилась рекомендация

            $table->string('model_used')->default('claude-sonnet-4-20250514');
            $table->integer('tokens_used')->nullable();

            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->integer('helpfulness_rating')->nullable(); // 1–5 оценка от учителя

            $table->timestamps();

            $table->index(['generated_for', 'is_read']);
            $table->index(['session_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_recommendations');
        Schema::dropIfExists('alert_thresholds');
        Schema::dropIfExists('engagement_alerts');
    }
};
