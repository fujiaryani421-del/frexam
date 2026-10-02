<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->role === 'student') {
            $attempts = DB::table('exam_attempts')
                ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
                ->where('exam_attempts.student_id', $user->id)
                ->select('exam_attempts.*', 'exams.title', 'exams.subject')
                ->orderByDesc('exam_attempts.updated_at')
                ->get();
            return view('student.dashboard', compact('user', 'attempts'));
        }
        if ($user->role === 'admin') return redirect()->route('admin.overview');
        $stats = $user->role === 'admin'
            ? ['Guru' => DB::table('users')->where('role', 'teacher')->count(), 'Siswa' => DB::table('users')->where('role', 'student')->count(), 'Ujian' => DB::table('exams')->count(), 'Hasil Ujian' => DB::table('exam_attempts')->where('status', 'submitted')->count()]
            : ['Ujian Saya' => DB::table('exams')->where('teacher_id', $user->id)->count(), 'Ujian Aktif' => DB::table('exams')->where('teacher_id', $user->id)->where('is_active', true)->count(), 'Bank Soal' => DB::table('questions')->where('teacher_id', $user->id)->count(), 'Rata-rata Nilai' => (int) DB::table('exam_attempts')->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')->where('exams.teacher_id', $user->id)->avg('score')];
        return view('dashboard', compact('user', 'stats'));
    }

    public function prepare(Request $request): View
    {
        abort_unless($request->user()->role === 'student', 403);
        $exam = DB::table('exams')->where('id', $request->session()->get('active_exam_id'))->first();
        abort_unless($exam, 404, 'Ujian tidak ditemukan.');
        $questionCount = DB::table('exam_question')->where('exam_id', $exam->id)->count();
        return view('student.prepare', compact('exam', 'questionCount'));
    }

    public function token(Request $request): View
    {
        abort_unless($request->user()->role === 'student', 403);
        return view('student.token');
    }

    public function submitToken(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'student', 403);
        $data = $request->validate(['token' => ['required', 'string', 'max:32']]);
        $exam = DB::table('exams')->where('token', strtoupper(trim($data['token'])))->where('is_active', true)->where('expires_at', '>=', now())->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->first();
        if (! $exam) return back()->withErrors(['token' => 'Token ujian tidak valid atau ujian sudah tidak aktif.']);
        $request->session()->put('active_exam_id', $exam->id);
        return redirect()->route('student.prepare');
    }

    public function activity(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()->role === 'student', 403);
        $data = $request->validate(['event' => ['required', 'in:tab_hidden,fullscreen_exit,page_exit,system_ui_interruption'], 'attempt_id' => ['nullable', 'integer']]);
        $attempt = isset($data['attempt_id']) ? DB::table('exam_attempts')->where('id', $data['attempt_id'])->where('student_id', $request->user()->id)->where('status', 'in_progress')->first() : null;
        if ($attempt) DB::table('activity_logs')->insert(['exam_id' => $attempt->exam_id, 'student_id' => $request->user()->id, 'event' => $data['event'], 'metadata' => json_encode(['attempt_id' => $attempt->id, 'recorded_from' => 'browser'])]);
        return response()->json(['ok' => true]);
    }
}
