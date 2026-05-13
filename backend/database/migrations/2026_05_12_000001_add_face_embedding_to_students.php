<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->json('face_embedding')->nullable()->after('face_encoding_path');
            $table->string('photo_path')->nullable()->after('face_encoding_path');
            $table->boolean('face_registered')->default(false)->after('photo_path');
            $table->timestamp('face_registered_at')->nullable()->after('face_registered');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'face_embedding',
                'photo_path',
                'face_registered',
                'face_registered_at',
            ]);
        });
    }
};
