<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engagement_snapshots', function (Blueprint $table) {
            // Новый компонент score: поза тела (опциональный)
            $table->decimal('posture_score', 5, 2)->nullable()->after('presence_score');

            // Состояние позы и поднятая рука
            $table->string('posture_state', 30)->nullable()->after('emotion_confidence');
            $table->boolean('hand_raised')->default(false)->after('posture_state');

            // Производные метрики, заполняются ML-сервисом
            $table->string('attention_state', 20)->nullable()->after('hand_raised');
            // focused | distracted | drowsy | absent
            $table->decimal('confidence_overall', 4, 3)->nullable()->after('attention_state');

            // Диагностика — почему лицо не обнаружено и как был кадр
            $table->string('not_detected_reason', 40)->nullable()->after('face_detected');
            // too_dark | too_blurry | no_faces_in_fov | face_too_small | model_unavailable

            // JSON-поля: качество кадра и детальный breakdown
            $table->json('frame_quality')->nullable()->after('not_detected_reason');
            $table->json('score_breakdown')->nullable()->after('frame_quality');

            $table->index('attention_state');
        });
    }

    public function down(): void
    {
        Schema::table('engagement_snapshots', function (Blueprint $table) {
            $table->dropIndex(['attention_state']);
            $table->dropColumn([
                'posture_score',
                'posture_state',
                'hand_raised',
                'attention_state',
                'confidence_overall',
                'not_detected_reason',
                'frame_quality',
                'score_breakdown',
            ]);
        });
    }
};
