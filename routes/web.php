<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExamController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\AdminController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.store');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/admin/pusat-kendali', [AdminController::class, 'overview'])->name('admin.overview');
    Route::get('/admin/riwayat-ujian', [AdminController::class, 'attempts'])->name('admin.attempts');
    Route::get('/admin/laporan/ujian/{exam}', [AdminController::class, 'examScoreReport'])->name('admin.exam-score-report');
    Route::get('/admin/menu/{page}', [AdminController::class, 'workspace'])->name('admin.workspace');
    Route::get('/admin/pengguna/{user}/detail', [AdminController::class, 'userDetail'])->name('admin.users.detail');
    Route::get('/admin/guru/{teacher}/kelas', [AdminController::class, 'teacherClasses'])->name('admin.teacher.classes');
    Route::get('/admin/guru/{teacher}/kelas/{class}', [AdminController::class, 'teacherClassStudents'])->name('admin.teacher.class-students');
    Route::get('/admin/pengguna', [UserManagementController::class, 'index'])->name('admin.users');
    Route::post('/admin/pengguna', [UserManagementController::class, 'store'])->name('admin.users.store');
    Route::post('/admin/pengguna/impor', [UserManagementController::class, 'import'])->name('admin.users.import');
    Route::patch('/admin/pengguna/{user}/status', [UserManagementController::class, 'toggle'])->name('admin.users.toggle');
    Route::get('/admin/pengguna/{user}/ubah', [UserManagementController::class, 'edit'])->name('admin.users.edit');
    Route::put('/admin/pengguna/{user}', [UserManagementController::class, 'update'])->name('admin.users.update');
    Route::delete('/admin/pengguna/{user}', [UserManagementController::class, 'destroy'])->name('admin.users.destroy');
    Route::get('/guru/soal', [TeacherController::class, 'questions'])->name('teacher.questions');
    Route::post('/guru/soal', [TeacherController::class, 'storeQuestion'])->name('teacher.questions.store');
    Route::post('/guru/soal/impor-pdf', [TeacherController::class, 'importPdf'])->name('teacher.questions.import-pdf');
    Route::get('/guru/soal/{question}/ubah', [TeacherController::class, 'editQuestion'])->name('teacher.questions.edit');
    Route::put('/guru/soal/{question}', [TeacherController::class, 'updateQuestion'])->name('teacher.questions.update');
    Route::delete('/guru/soal/{question}', [TeacherController::class, 'destroyQuestion'])->name('teacher.questions.destroy');
    Route::get('/guru/ujian', [TeacherController::class, 'exams'])->name('teacher.exams');
    Route::post('/guru/ujian', [TeacherController::class, 'storeExam'])->name('teacher.exams.store');
    Route::get('/guru/hasil', [TeacherController::class, 'results'])->name('teacher.results');
    Route::get('/guru/monitoring', [TeacherController::class, 'monitoring'])->name('teacher.monitoring');
    Route::get('/guru/notifikasi', [TeacherController::class, 'notifications'])->name('teacher.notifications');
    Route::get('/guru/kelas', [TeacherController::class, 'classes'])->name('teacher.classes');
    Route::get('/guru/kelas/{class}', [TeacherController::class, 'classStudents'])->name('teacher.class-students');
    Route::patch('/guru/ujian/{exam}/status', [TeacherController::class, 'toggleExam'])->name('teacher.exams.toggle');
    Route::patch('/guru/ujian/{exam}/token', [TeacherController::class, 'regenerateToken'])->name('teacher.exams.token');
    Route::delete('/guru/ujian/{exam}', [TeacherController::class, 'destroyExam'])->name('teacher.exams.destroy');
    Route::get('/ujian/persiapan', [DashboardController::class, 'prepare'])->name('student.prepare');
    Route::get('/siswa/token', [DashboardController::class, 'token'])->name('student.token');
    Route::post('/siswa/token', [DashboardController::class, 'submitToken'])->name('student.token.submit');
    Route::post('/siswa/aktivitas', [DashboardController::class, 'activity'])->name('student.activity');
    Route::post('/ujian/mulai', [ExamController::class, 'start'])->name('student.start');
    Route::get('/ujian/hasil/saya', [ExamController::class, 'result'])->name('student.result');
    Route::get('/ujian/{attempt}', [ExamController::class, 'show'])->name('student.exam');
    Route::post('/ujian/{attempt}/jawaban', [ExamController::class, 'saveAnswer'])->name('student.answer');
    Route::post('/ujian/{attempt}/kirim', [ExamController::class, 'submit'])->name('student.submit');
});
