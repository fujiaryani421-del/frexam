<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\Process\Process;

class TeacherController extends Controller
{
    public function questions(Request $request): View
    {
        $this->authorizeTeacher($request);
        $questions = DB::table('questions')->where('teacher_id', $request->user()->id)->latest()->get();
        return view('teacher.questions', compact('questions'));
    }

    public function storeQuestion(Request $request): RedirectResponse
    {
        $this->authorizeTeacher($request);
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:100'],
            'questions' => ['required', 'array', 'min:1', 'max:50'],
            'questions.*.question_text' => ['required', 'string'],
            'questions.*.choices' => ['required', 'array', 'min:2', 'max:26'],
            'questions.*.choices.*' => ['required', 'string', 'max:5000'],
            'questions.*.correct_answer' => ['required', 'string', 'max:2'],
            'questions.*.weight' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $now = now();
        foreach ($data['questions'] as $question) abort_unless(array_key_exists(strtoupper($question['correct_answer']), $this->choicesFromValues($question['choices'])), 422, 'Kunci jawaban harus sesuai dengan salah satu opsi.');
        DB::table('questions')->insert(collect($data['questions'])->map(fn ($question) => [
            'teacher_id' => $request->user()->id, 'subject' => $data['subject'], 'type' => 'multiple_choice',
            'difficulty' => 'medium', 'weight' => $question['weight'], 'question_text' => $question['question_text'],
            'choices' => json_encode($this->choicesFromValues($question['choices'])),
            'correct_answer' => strtoupper($question['correct_answer']), 'created_at' => $now, 'updated_at' => $now,
        ])->all());
        return back()->with('success', count($data['questions']).' soal berhasil ditambahkan ke bank soal.');
    }

    public function editQuestion(Request $request, int $question): View
    {
        $this->authorizeTeacher($request);
        $question = DB::table('questions')->where('id', $question)->where('teacher_id', $request->user()->id)->first();
        abort_unless($question, 404, 'Soal tidak ditemukan.');
        $choices = json_decode($question->choices, true) ?: [];
        return view('teacher.question-edit', compact('question', 'choices'));
    }

    public function updateQuestion(Request $request, int $question): RedirectResponse
    {
        $this->authorizeTeacher($request);
        $data = $request->validate(['subject' => ['required', 'string', 'max:100'], 'question_text' => ['required', 'string'], 'choices' => ['required', 'array', 'min:2', 'max:26'], 'choices.*' => ['required', 'string', 'max:5000'], 'correct_answer' => ['required', 'string', 'max:2'], 'weight' => ['required', 'integer', 'min:1', 'max:100']]);
        $choices = $this->choicesFromValues($data['choices']);
        abort_unless(array_key_exists(strtoupper($data['correct_answer']), $choices), 422, 'Kunci jawaban harus sesuai dengan salah satu opsi.');
        abort_unless(DB::table('questions')->where('id', $question)->where('teacher_id', $request->user()->id)->exists(), 404);
        DB::table('questions')->where('id', $question)->update(['subject' => $data['subject'], 'question_text' => $data['question_text'], 'choices' => json_encode($choices), 'correct_answer' => strtoupper($data['correct_answer']), 'weight' => $data['weight'], 'updated_at' => now()]);
        return redirect()->route('teacher.questions')->with('success', 'Soal berhasil diperbarui.');
    }

    public function destroyQuestion(Request $request, int $question): RedirectResponse
    {
        $this->authorizeTeacher($request);
        DB::table('questions')->where('id', $question)->where('teacher_id', $request->user()->id)->delete();
        return back()->with('success', 'Soal berhasil dihapus.');
    }

    public function importPdf(Request $request): RedirectResponse
    {
        $this->authorizeTeacher($request);
        $data = $request->validate(['subject' => ['required', 'string', 'max:100'], 'pdf' => ['required', 'file', 'mimes:pdf', 'max:10240']]);
        $path = $data['pdf']->store('imports');
        $fullPath = Storage::path($path);
        $process = new Process(['pdftotext', '-layout', $fullPath, '-']);
        $process->setTimeout(45)->run();
        Storage::delete($path);
        if (! $process->isSuccessful() || trim($process->getOutput()) === '') return back()->withErrors(['pdf' => 'Teks PDF tidak dapat dibaca. Pastikan PDF bukan hasil scan gambar.']);
        $questions = array_slice($this->parsePdfQuestions($process->getOutput()), 0, 50);
        if (empty($questions)) return back()->withErrors(['pdf' => 'Soal belum dapat dikenali. Pastikan setiap soal memiliki nomor, minimal dua opsi (misalnya A. dan B.), serta baris Kunci Jawaban: A.']);
        DB::table('questions')->insert(collect($questions)->map(fn ($question) => ['teacher_id' => $request->user()->id, 'subject' => $data['subject'], 'type' => 'multiple_choice', 'difficulty' => 'medium', 'weight' => 20, 'question_text' => $question['text'], 'choices' => json_encode($question['choices']), 'correct_answer' => $question['answer'], 'created_at' => now(), 'updated_at' => now()])->all());
        return back()->with('success', count($questions).' soal berhasil dibuat dari daftar nomor pada PDF.');
    }

    public function exams(Request $request): View
    {
        $this->authorizeTeacher($request);
        $exams = DB::table('exams')->where('teacher_id', $request->user()->id)->latest()->get();
        $questions = DB::table('questions')->where('teacher_id', $request->user()->id)->orderBy('subject')->get();
        return view('teacher.exams', compact('exams', 'questions'));
    }

    public function results(Request $request): View
    {
        $this->authorizeTeacher($request);
        $results = DB::table('exam_attempts')
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->join('users as students', 'students.id', '=', 'exam_attempts.student_id')
            ->where('exams.teacher_id', $request->user()->id)
            ->where('exam_attempts.status', 'submitted')
            ->select('exam_attempts.*', 'exams.title as exam_title', 'exams.subject', 'students.name as student_name', 'students.nisn')
            ->orderByDesc('exam_attempts.submitted_at')
            ->get();
        return view('teacher.results', compact('results'));
    }

    public function monitoring(Request $request): View
    {
        $this->authorizeTeacher($request);
        $activities = DB::table('activity_logs')
            ->join('exams', 'exams.id', '=', 'activity_logs.exam_id')
            ->join('users as students', 'students.id', '=', 'activity_logs.student_id')
            ->where('exams.teacher_id', $request->user()->id)
            ->whereIn('activity_logs.event', ['tab_hidden', 'fullscreen_exit', 'page_exit', 'system_ui_interruption'])
            ->select('activity_logs.*', 'exams.title as exam_title', 'students.name as student_name', 'students.nisn')
            ->orderByDesc('activity_logs.occurred_at')
            ->get();
        return view('teacher.monitoring', compact('activities'));
    }

    public function notifications(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorizeTeacher($request);
        $afterId = max(0, (int) $request->query('after', 0));
        $notifications = DB::table('activity_logs')->join('exams', 'exams.id', '=', 'activity_logs.exam_id')->join('users as students', 'students.id', '=', 'activity_logs.student_id')->where('exams.teacher_id', $request->user()->id)->where('activity_logs.id', '>', $afterId)->whereIn('activity_logs.event', ['tab_hidden', 'fullscreen_exit', 'page_exit', 'system_ui_interruption'])->orderBy('activity_logs.id')->select('activity_logs.id', 'activity_logs.event', 'students.name as student_name', 'exams.title as exam_title')->get()->map(fn ($item) => ['id' => $item->id, 'message' => 'Siswa “'.$item->student_name.'” '.match ($item->event) {
            'tab_hidden' => 'meninggalkan tab',
            'fullscreen_exit' => 'keluar dari layar penuh',
            'system_ui_interruption' => 'membuka bilah sistem atau mengalihkan fokus ujian',
            default => 'mencoba meninggalkan halaman',
        }.'.', 'exam' => $item->exam_title]);
        return response()->json(['notifications' => $notifications]);
    }

    public function classes(Request $request): View
    {
        $this->authorizeTeacher($request);
        $classes = $this->teachingClasses($request->user()->teaching_class)->map(fn ($class) => (object) ['name' => $class, 'student_count' => User::where('role', 'student')->where('class_name', $class)->count()]);
        return view('teacher.classes', compact('classes'));
    }

    public function classStudents(Request $request, string $class): View
    {
        $this->authorizeTeacher($request);
        abort_unless($this->teachingClasses($request->user()->teaching_class)->contains($class), 403, 'Kelas ini tidak Anda ampu.');
        $students = User::where('role', 'student')->where('class_name', $class)->orderBy('name')->get();
        return view('teacher.class-students', compact('class', 'students'));
    }

    public function storeExam(Request $request): RedirectResponse
    {
        $this->authorizeTeacher($request);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'subject' => ['required', 'string', 'max:100'], 'duration_minutes' => ['required', 'integer', 'min:1', 'max:300'], 'expires_at' => ['required', 'date', 'after:now'], 'question_ids' => ['required', 'array', 'min:1'], 'question_ids.*' => ['integer']]);
        $questionIds = collect($data['question_ids'])->unique()->values();
        abort_unless(DB::table('questions')->where('teacher_id', $request->user()->id)->whereIn('id', $questionIds)->count() === $questionIds->count(), 422, 'Soal tidak valid.');
        $token = strtoupper(Str::random(6));
        while (DB::table('exams')->where('token', $token)->exists()) $token = strtoupper(Str::random(6));
        DB::transaction(function () use ($request, $data, $questionIds, $token) {
            $examId = DB::table('exams')->insertGetId(['teacher_id' => $request->user()->id, 'title' => $data['title'], 'subject' => $data['subject'], 'duration_minutes' => $data['duration_minutes'], 'token' => $token, 'expires_at' => $data['expires_at'], 'is_active' => true, 'random_questions' => false, 'random_choices' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('exam_question')->insert($questionIds->map(fn ($id, $position) => ['exam_id' => $examId, 'question_id' => $id, 'position' => $position + 1])->all());
        });
        return back()->with('success', 'Ujian berhasil dibuat. Token ujian: '.$token);
    }

    public function toggleExam(Request $request, int $exam): RedirectResponse
    {
        $this->authorizeTeacher($request);
        $exam = DB::table('exams')->where('id', $exam)->where('teacher_id', $request->user()->id)->first();
        abort_unless($exam, 404, 'Ujian tidak ditemukan.');
        DB::table('exams')->where('id', $exam->id)->update(['is_active' => ! $exam->is_active, 'updated_at' => now()]);
        return back()->with('success', 'Status ujian diperbarui.');
    }

    public function regenerateToken(Request $request, int $exam): RedirectResponse
    {
        $this->authorizeTeacher($request);
        abort_unless(DB::table('exams')->where('id', $exam)->where('teacher_id', $request->user()->id)->exists(), 404);
        do { $token = strtoupper(Str::random(6)); } while (DB::table('exams')->where('token', $token)->exists());
        DB::table('exams')->where('id', $exam)->update(['token' => $token, 'updated_at' => now()]);
        return back()->with('success', 'Token baru berhasil dibuat: '.$token);
    }

    public function destroyExam(Request $request, int $exam): RedirectResponse
    {
        $this->authorizeTeacher($request);
        DB::table('exams')->where('id', $exam)->where('teacher_id', $request->user()->id)->delete();
        return back()->with('success', 'Ujian berhasil dihapus.');
    }

    private function authorizeTeacher(Request $request): void { abort_unless($request->user()->role === 'teacher', 403); }

    private function parsePdfQuestions(string $text): array
    {
        $text = trim(preg_replace("/\r\n?|\f/", "\n", $text));
        preg_match_all('/(?:^|\n)\s*(\d{1,3})\s*[\.)\:]?\s+(.+?)(?=\n\s*\d{1,3}\s*[\.)\:]?\s+|\z)/s', $text, $blocks, PREG_SET_ORDER);
        $questions = [];
        foreach ($blocks as $block) {
            $body = trim($block[2]);
            if (! preg_match('/^\s*(?:kunci\s*(?:jawaban)?|jawaban)\s*[:\-]?\s*([A-Z])\s*$/im', $body, $answerMatch)) continue;
            $body = preg_replace('/^\s*(?:kunci\s*(?:jawaban)?|jawaban)\s*[:\-]?\s*[A-Z]\s*$/im', '', $body);
            preg_match_all('/^\s*([A-Z])\s*[\.)\:\-]\s*(.+?)(?=^\s*[A-Z]\s*[\.)\:\-]\s*|\z)/ims', trim($body), $optionMatches, PREG_SET_ORDER);
            if (count($optionMatches) < 2) continue;
            $firstOptionAt = strpos($body, $optionMatches[0][0]);
            $questionText = trim(substr($body, 0, $firstOptionAt));
            $choices = [];
            foreach ($optionMatches as $option) $choices[strtoupper($option[1])] = trim($option[2]);
            $answer = strtoupper($answerMatch[1]);
            if ($questionText === '' || ! isset($choices[$answer])) continue;
            $questions[] = ['text' => $questionText, 'choices' => $choices, 'answer' => $answer];
        }
        return $questions;
    }

    private function choicesFromValues(array $values): array
    {
        $choices = [];
        foreach (array_values($values) as $index => $value) $choices[chr(65 + $index)] = trim($value);
        return $choices;
    }

    private function teachingClasses(?string $classes): \Illuminate\Support\Collection
    {
        return collect(explode(',', (string) $classes))->map(fn ($class) => trim($class))->filter()->unique()->values();
    }
}
