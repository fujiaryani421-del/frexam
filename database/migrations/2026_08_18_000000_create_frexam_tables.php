<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->string('title'); $table->string('subject'); $table->unsignedSmallInteger('duration_minutes');
            $table->string('token', 32)->unique(); $table->timestamp('starts_at')->nullable(); $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true); $table->boolean('random_questions')->default(true); $table->boolean('random_choices')->default(false); $table->timestamps();
        });
        Schema::create('questions', function (Blueprint $table) {
            $table->id(); $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject'); $table->string('type')->default('multiple_choice'); $table->string('difficulty')->default('medium'); $table->unsignedSmallInteger('weight')->default(20);
            $table->text('question_text'); $table->json('choices')->nullable(); $table->text('correct_answer')->nullable(); $table->timestamps();
        });
        Schema::create('exam_question', function (Blueprint $table) {
            $table->id(); $table->foreignId('exam_id')->constrained()->cascadeOnDelete(); $table->foreignId('question_id')->constrained()->cascadeOnDelete(); $table->unsignedSmallInteger('position')->default(0); $table->unique(['exam_id', 'question_id']);
        });
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id(); $table->foreignId('exam_id')->constrained()->cascadeOnDelete(); $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('started_at'); $table->timestamp('submitted_at')->nullable(); $table->unsignedTinyInteger('score')->nullable(); $table->unsignedSmallInteger('correct_count')->default(0); $table->unsignedSmallInteger('wrong_count')->default(0); $table->unsignedInteger('time_used_seconds')->default(0); $table->string('status')->default('in_progress'); $table->timestamps(); $table->unique(['exam_id', 'student_id']);
        });
        Schema::create('attempt_answers', function (Blueprint $table) {
            $table->id(); $table->foreignId('attempt_id')->constrained('exam_attempts')->cascadeOnDelete(); $table->foreignId('question_id')->constrained()->cascadeOnDelete(); $table->text('answer')->nullable(); $table->timestamps(); $table->unique(['attempt_id', 'question_id']);
        });
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id(); $table->foreignId('exam_id')->constrained()->cascadeOnDelete(); $table->foreignId('student_id')->constrained('users')->cascadeOnDelete(); $table->string('event'); $table->json('metadata')->nullable(); $table->timestamp('occurred_at')->useCurrent();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('activity_logs'); Schema::dropIfExists('attempt_answers'); Schema::dropIfExists('exam_attempts'); Schema::dropIfExists('exam_question'); Schema::dropIfExists('questions'); Schema::dropIfExists('exams');
    }
};
