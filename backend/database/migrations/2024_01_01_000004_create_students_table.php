<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('student_code')->nullable();
            $table->date('birth_date')->nullable();
            // Зашифрованный путь к фото для сопоставления с лицом
            $table->string('face_encoding_path')->nullable();
            $table->boolean('consent_given')->default(false);
            $table->timestamp('consent_given_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'student_code']);
            $table->index('school_id');
        });

        // Связь студент ↔ класс (студент может быть в нескольких классах)
        Schema::create('classroom_student', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained()->cascadeOnDelete();
            $table->integer('seat_number')->nullable();
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('left_at')->nullable();

            $table->unique(['classroom_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_student');
        Schema::dropIfExists('students');
    }
};
