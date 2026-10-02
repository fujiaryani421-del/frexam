<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('teaching_subject', 100)->nullable()->after('class_name');
            $table->string('teaching_class', 100)->nullable()->after('teaching_subject');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['teaching_subject', 'teaching_class']);
        });
    }
};
