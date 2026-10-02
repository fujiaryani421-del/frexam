<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function overview(Request $request): View
    {
        $this->authorizeAdmin($request);
        $stats = [
            'Guru aktif' => User::where('role', 'teacher')->where('is_active', true)->count(),
            'Siswa aktif' => User::where('role', 'student')->where('is_active', true)->count(),
            'Ujian aktif' => DB::table('exams')->where('is_active', true)->count(),
            'Ujian selesai' => DB::table('exam_attempts')->where('status', 'submitted')->count(),
        ];
        $recentLogins = User::whereNotNull('last_login_at')->orderByDesc('last_login_at')->limit(8)->get();
        $recentAttempts = $this->attemptQuery()->orderByDesc('exam_attempts.submitted_at')->limit(8)->get();
        $alerts = DB::table('activity_logs')->whereIn('event', ['tab_hidden', 'fullscreen_exit', 'page_exit', 'system_ui_interruption'])->orderByDesc('occurred_at')->limit(6)->get()->each(fn ($alert) => $alert->event = $this->activityLabel($alert->event));
        return view('admin.overview', compact('stats', 'recentLogins', 'recentAttempts', 'alerts'));
    }

    public function attempts(Request $request): View
    {
        $this->authorizeAdmin($request);
        $attempts = $this->attemptQuery()->orderByDesc('exam_attempts.updated_at')->get();
        return view('admin.attempts', compact('attempts'));
    }

    public function userDetail(Request $request, User $user): View
    {
        $this->authorizeAdmin($request);
        $attempts = $user->role === 'student'
            ? $this->attemptQuery()->where('exam_attempts.student_id', $user->id)->orderByDesc('exam_attempts.updated_at')->get()
            : collect();
        $createdExams = $user->role === 'teacher' ? DB::table('exams')->where('teacher_id', $user->id)->latest()->get() : collect();
        return view('admin.user-detail', compact('user', 'attempts', 'createdExams'));
    }

    public function workspace(Request $request, string $page): View
    {
        $this->authorizeAdmin($request);
        $pages = [
            'classes' => ['Kelas', 'Data Akademik', 'Daftar kelas siswa yang tersedia di FRExam.', User::where('role', 'student')->select('class_name')->whereNotNull('class_name')->distinct()->orderBy('class_name')->pluck('class_name')],
            'subjects' => ['Mata Pelajaran', 'Data Akademik', 'Mata pelajaran yang digunakan pada bank soal dan ujian.', DB::table('questions')->select('subject')->distinct()->orderBy('subject')->pluck('subject')],
            'academic-years' => ['Tahun Ajaran', 'Data Akademik', 'Pengaturan tahun ajaran dapat digunakan untuk pengelompokan administrasi.', collect()],
            'exams' => ['Daftar Ujian', 'Manajemen Ujian', 'Semua ujian yang dibuat oleh guru.', DB::table('exams')->orderByDesc('created_at')->get()],
            'question-bank' => ['Bank Soal', 'Manajemen Ujian', 'Daftar seluruh soal yang tersimpan.', DB::table('questions')->orderByDesc('created_at')->get()],
            'schedules' => ['Jadwal Ujian', 'Manajemen Ujian', 'Jadwal diambil dari waktu mulai dan berakhir setiap ujian.', DB::table('exams')->orderBy('starts_at')->get()],
            'participants' => ['Peserta Ujian', 'Manajemen Ujian', 'Daftar peserta berdasarkan sesi ujian.', $this->attemptQuery()->orderByDesc('exam_attempts.updated_at')->get()],
            'live-exams' => ['Ujian Berlangsung', 'Monitoring Ujian', 'Sesi ujian yang masih sedang dikerjakan siswa.', $this->attemptQuery()->where('exam_attempts.status', 'in_progress')->orderByDesc('exam_attempts.updated_at')->get()],
            'participant-activity' => ['Aktivitas Peserta', 'Monitoring Ujian', 'Catatan perpindahan tab, keluar layar penuh, dan keluar halaman.', DB::table('activity_logs')->selectRaw("*, CASE event WHEN 'tab_hidden' THEN 'Berpindah tab' WHEN 'fullscreen_exit' THEN 'Keluar layar penuh' WHEN 'page_exit' THEN 'Meninggalkan halaman' WHEN 'system_ui_interruption' THEN 'Bilah sistem atau fokus ujian terbuka' ELSE event END as event_label")->orderByDesc('occurred_at')->get()],
            'exam-report' => ['Laporan Ujian', 'Laporan', 'Klik nama ujian untuk membuka rekap peserta dan nilai.', $this->reportExamList()],
            'scores' => ['Laporan Nilai', 'Laporan', 'Klik nama ujian untuk membuka rekap nilai seluruh peserta.', $this->reportExamList()],
            'participant-recap' => ['Rekap Peserta', 'Laporan', 'Rekap siswa yang sudah mengikuti ujian.', User::where('role', 'student')->orderBy('name')->get()],
        ];
        abort_unless(isset($pages[$page]), 404);
        [$title, $group, $description, $items] = $pages[$page];
        return view('admin.workspace', compact('title', 'group', 'description', 'items', 'page'));
    }

    public function examScoreReport(Request $request, int $exam): View
    {
        $this->authorizeAdmin($request);
        $exam = DB::table('exams')->leftJoin('users as teachers', 'teachers.id', '=', 'exams.teacher_id')
            ->where('exams.id', $exam)->select('exams.*', 'teachers.name as teacher_name')->first();
        abort_unless($exam, 404, 'Ujian tidak ditemukan.');
        $participants = $this->attemptQuery()->where('exam_attempts.exam_id', $exam->id)
            ->orderByDesc('exam_attempts.score')->orderBy('students.name')->get();
        $summary = [
            'participants' => $participants->count(),
            'completed' => $participants->where('status', 'submitted')->count(),
            'average' => (int) $participants->where('status', 'submitted')->avg('score'),
        ];
        return view('admin.exam-score-report', compact('exam', 'participants', 'summary'));
    }

    public function teacherClasses(Request $request, User $teacher): View
    {
        $this->authorizeAdmin($request);
        abort_unless($teacher->role === 'teacher', 404);
        $classes = collect(explode(',', (string) $teacher->teaching_class))->map(fn ($class) => trim($class))->filter()->unique()->values()->map(fn ($class) => (object) ['name' => $class, 'student_count' => User::where('role', 'student')->where('class_name', $class)->count()]);
        return view('admin.teacher-classes', compact('teacher', 'classes'));
    }

    public function teacherClassStudents(Request $request, User $teacher, string $class): View
    {
        $this->authorizeAdmin($request);
        abort_unless($teacher->role === 'teacher', 404);
        $classes = collect(explode(',', (string) $teacher->teaching_class))->map(fn ($item) => trim($item))->filter();
        abort_unless($classes->contains($class), 404, 'Kelas tidak ditemukan.');
        $students = User::where('role', 'student')->where('class_name', $class)->orderBy('name')->get();
        return view('admin.teacher-class-students', compact('teacher', 'class', 'students'));
    }

    private function attemptQuery()
    {
        return DB::table('exam_attempts')
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->join('users as students', 'students.id', '=', 'exam_attempts.student_id')
            ->leftJoin('users as teachers', 'teachers.id', '=', 'exams.teacher_id')
            ->select('exam_attempts.*', 'exams.title as exam_title', 'exams.subject', 'students.name as student_name', 'students.nisn', 'teachers.name as teacher_name');
    }

    private function reportExamList()
    {
        return DB::table('exams')->leftJoin('exam_attempts', 'exam_attempts.exam_id', '=', 'exams.id')
            ->select('exams.id', 'exams.title', 'exams.subject', DB::raw('COUNT(exam_attempts.id) as participant_count'))
            ->groupBy('exams.id', 'exams.title', 'exams.subject')->orderByDesc('exams.created_at')->get();
    }

    private function activityLabel(string $event): string
    {
        return ['tab_hidden' => 'Berpindah tab', 'fullscreen_exit' => 'Keluar layar penuh', 'page_exit' => 'Meninggalkan halaman'][$event] ?? $event;
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->role === 'admin', 403);
    }

}
