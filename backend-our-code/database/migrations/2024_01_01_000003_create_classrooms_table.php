<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classrooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->integer('capacity')->default(30);
            $table->json('camera_config')->default('[]');
            // [{"id":"cam_1","rtsp_url":"rtsp://...","position":"front","is_active":true}]
            $table->json('detection_zones')->default('[]');
            // Зоны ROI для детекции лиц на каждой камере
            $table->json('settings')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'code']);
            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classrooms');
    }
};
