<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'username')) $table->string('username')->nullable()->unique()->after('name');
            if (! Schema::hasColumn('users', 'nisn')) $table->string('nisn')->nullable()->unique()->after('email');
            if (! Schema::hasColumn('users', 'nip')) $table->string('nip')->nullable()->unique()->after('nisn');
            if (! Schema::hasColumn('users', 'class_name')) $table->string('class_name')->nullable()->after('nip');
            if (! Schema::hasColumn('users', 'role')) $table->string('role')->default('student')->index()->after('class_name');
            if (! Schema::hasColumn('users', 'is_active')) $table->boolean('is_active')->default(true)->after('role');
        });
    }

    public function down(): void
    {
        // Kolom dapat telah dipakai oleh data pengguna sehingga tidak dihapus otomatis.
    }
};
