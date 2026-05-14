<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->string('head_teacher')->nullable()->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropColumn('head_teacher');
        });
    }
};
