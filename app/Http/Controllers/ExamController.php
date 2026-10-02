<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ExamController extends Controller
{
    public function start(Request $request): RedirectResponse
    {
        $exam = $this->exam($request);
        $attempt = DB::table('exam_attempts')->where('exam_id', $exam->id)->where('student_id', $request->user()->id)->first();
        if ($attempt?->status === 'submitted') return redirect()->route('student.result');
        if (! $attempt) {
            $id = DB::table('exam_attempts')->insertGetId(['exam_id' => $exam->id, 'student_id' => $request->user()->id, 'started_at' => now(), 'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('activity_logs')->insert(['exam_id' => $exam->id, 'student_id' => $request->user()->id, 'event' => 'exam_started', 'metadata' => json_encode([])]);
        } else $id = $attempt->id;
        return redirect()->route('student.exam', $id);
    }

    public function show(Request $request, int $attempt): View|RedirectResponse
    {
        [$attempt, $exam] = $this->attempt($request, $attempt);
        if ($attempt->status === 'submitted') return redirect()->route('student.result');
        if ($this->expired($attempt, $exam)) return $this->submit($request, $attempt->id);
        $questions = DB::table('exam_question')->join('questions', 'questions.id', '=', 'exam_question.question_id')->where('exam_question.exam_id', $exam->id)->orderBy('exam_question.position')->orderBy('questions.id')->select('questions.id', 'questions.question_text', 'questions.choices', 'questions.type')->get();
        $answers = DB::table('attempt_answers')->where('attempt_id', $attempt->id)->pluck('answer', 'question_id');
        $deadline = $this->deadline($attempt, $exam);
        return view('student.exam', compact('attempt', 'exam', 'questions', 'answers', 'deadline'));
    }

    public function saveAnswer(Request $request, int $attempt): RedirectResponse
    {
        [$attempt, $exam] = $this->attempt($request, $attempt);
        abort_if($attempt->status === 'submitted' || $this->expired($attempt, $exam), 422, 'Waktu ujian telah berakhir.');
        $data = $request->validate(['question_id' => ['required', 'integer'], 'answer' => ['nullable', 'string', 'max:5000']]);
        abort_unless(DB::table('exam_question')->where('exam_id', $exam->id)->where('question_id', $data['question_id'])->exists(), 404);
        DB::table('attempt_answers')->updateOrInsert(['attempt_id' => $attempt->id, 'question_id' => $data['question_id']], ['answer' => $data['answer'] ?? null, 'updated_at' => now(), 'created_at' => now()]);
        return back()->with('saved', 'Jawaban tersimpan.');
    }

    public function submit(Request $request, int $attempt): RedirectResponse
    {
        [$attempt, $exam] = $this->attempt($request, $attempt);
        if ($attempt->status === 'submitted') return redirect()->route('student.result');
        $submittedAnswers = $request->validate(['answers' => ['nullable', 'array'], 'answers.*' => ['nullable', 'string', 'max:5000']])['answers'] ?? [];
        if ($submittedAnswers) {
            $validQuestionIds = DB::table('exam_question')->where('exam_id', $exam->id)->pluck('question_id')->map(fn ($id) => (string) $id)->all();
            foreach ($submittedAnswers as $questionId => $answer) {
                if (! in_array((string) $questionId, $validQuestionIds, true)) continue;
                DB::table('attempt_answers')->updateOrInsert(
                    ['attempt_id' => $attempt->id, 'question_id' => $questionId],
                    ['answer' => $answer, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }
        $questions = DB::table('exam_question')->join('questions', 'questions.id', '=', 'exam_question.question_id')->where('exam_question.exam_id', $exam->id)->select('questions.id', 'questions.correct_answer', 'questions.choices', 'questions.weight')->get();
        $answers = DB::table('attempt_answers')->where('attempt_id', $attempt->id)->pluck('answer', 'question_id');
        $correct = 0; $earned = 0; $total = max(1, $questions->sum('weight'));
        foreach ($questions as $question) {
            if ($this->isCorrectAnswer($answers->get($question->id), $question->correct_answer, $question->choices)) {
                $correct++;
                $earned += $question->weight;
            }
        }
        $used = max(0, min(now()->diffInSeconds(Carbon::parse($attempt->started_at)), $exam->duration_minutes * 60));
        DB::table('exam_attempts')->where('id', $attempt->id)->update(['submitted_at' => now(), 'score' => (int) round(($earned / $total) * 100), 'correct_count' => $correct, 'wrong_count' => $questions->count() - $correct, 'time_used_seconds' => $used, 'status' => 'submitted', 'updated_at' => now()]);
        DB::table('activity_logs')->insert(['exam_id' => $exam->id, 'student_id' => $request->user()->id, 'event' => 'exam_submitted', 'metadata' => json_encode([])]);
        return redirect()->route('student.result');
    }

    public function result(Request $request): View
    {
        $exam = $this->exam($request);
        $attempt = DB::table('exam_attempts')->where('exam_id', $exam->id)->where('student_id', $request->user()->id)->where('status', 'submitted')->first();
        abort_unless($attempt, 404, 'Hasil ujian belum tersedia.');
        return view('student.result', compact('exam', 'attempt'));
    }

    private function exam(Request $request): object { abort_unless($request->user()->role === 'student', 403); $exam = DB::table('exams')->where('id', $request->session()->get('active_exam_id'))->where('is_active', true)->first(); abort_unless($exam, 404, 'Ujian tidak ditemukan.'); return $exam; }
    private function attempt(Request $request, int $id): array { $attempt = DB::table('exam_attempts')->where('id', $id)->where('student_id', $request->user()->id)->first(); abort_unless($attempt, 404, 'Sesi ujian tidak ditemukan.'); $exam = $this->exam($request); abort_unless($attempt->exam_id === $exam->id, 404); return [$attempt, $exam]; }
    private function deadline(object $attempt, object $exam): Carbon { $a = Carbon::parse($attempt->started_at)->addMinutes($exam->duration_minutes); $b = Carbon::parse($exam->expires_at); return $a->lessThan($b) ? $a : $b; }
    private function expired(object $attempt, object $exam): bool { return now()->greaterThanOrEqualTo($this->deadline($attempt, $exam)); }

    private function isCorrectAnswer(?string $answer, ?string $correctAnswer, ?string $choicesJson): bool
    {
        $normalize = fn ($value) => mb_strtolower(trim((string) $value));
        $answer = $normalize($answer);
        $correct = $normalize($correctAnswer);
        if ($answer === '' || $correct === '') return false;
        if ($answer === $correct) return true;

        $choices = json_decode((string) $choicesJson, true) ?: [];
        $correctText = $normalize($choices[strtoupper($correctAnswer)] ?? '');
        $answerText = $normalize($choices[strtoupper((string) $answer)] ?? '');

        return ($correctText !== '' && $answer === $correctText)
            || ($answerText !== '' && $answerText === $correctText);
    }
}
