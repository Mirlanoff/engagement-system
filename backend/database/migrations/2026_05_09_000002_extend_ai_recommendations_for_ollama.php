<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_recommendations', function (Blueprint $table) {
            // Еженедельные отчёты не привязаны к одному уроку, а к классу.
            $table->foreignUuid('classroom_id')
                ->nullable()
                ->after('session_id')
                ->constrained()
                ->nullOnDelete();

            // Для weekly_analysis session_id отсутствует.
            $table->uuid('session_id')->nullable()->change();
        });

        // Сменим default `model_used` чтобы новые записи помечались как Ollama.
        DB::statement("ALTER TABLE ai_recommendations ALTER COLUMN model_used SET DEFAULT 'qwen2.5:7b-instruct'");
    }

    public function down(): void
    {
        Schema::table('ai_recommendations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('classroom_id');
            $table->uuid('session_id')->nullable(false)->change();
        });
        DB::statement("ALTER TABLE ai_recommendations ALTER COLUMN model_used SET DEFAULT 'claude-sonnet-4-20250514'");
    }
};
