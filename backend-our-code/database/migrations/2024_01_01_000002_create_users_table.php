<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('role', ['admin', 'supervisor', 'teacher'])->default('teacher');
            $table->string('avatar_path')->nullable();
            $table->json('notification_preferences')->default('{}');
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
