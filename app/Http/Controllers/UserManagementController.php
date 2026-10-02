<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\Process\Process;

class UserManagementController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->role === 'admin', 403);
        $users = User::query();
        $admins = (clone $users)->where('role', 'admin')->orderBy('name')->get();
        $teachers = (clone $users)->where('role', 'teacher')->orderBy('name')->get();
        $students = (clone $users)->where('role', 'student')->orderBy('name')->get();
        return view('admin.users', compact('admins', 'teachers', 'students'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $role = $request->string('role')->value();
        abort_unless(in_array($role, ['admin', 'teacher', 'student'], true), 422);
        $identifier = $role === 'student' ? 'nisn' : ($role === 'teacher' ? 'nip' : 'username');
        $rules = ['name' => ['required', 'string', 'max:255'], 'role' => ['required', 'in:admin,teacher,student'], 'password' => ['required', 'string', 'min:6', 'confirmed'], $identifier => ['required', 'string', 'max:255', 'unique:users,'.$identifier]];
        if ($role === 'student') $rules['class_name'] = ['nullable', 'string', 'max:100'];
        if ($role === 'teacher') { $rules['teaching_subject'] = ['required', 'string', 'max:100']; $rules['teaching_class'] = ['required', 'string', 'max:100']; }
        $data = $request->validate($rules);
        User::create(['name' => $data['name'], 'email' => 'manual-'.$role.'-'.sha1($data[$identifier]).'@frexam.local', 'role' => $role, $identifier => $data[$identifier], 'class_name' => $data['class_name'] ?? null, 'teaching_subject' => $data['teaching_subject'] ?? null, 'teaching_class' => $data['teaching_class'] ?? null, 'password' => $data['password'], 'is_active' => true]);
        return redirect()->route('admin.users')->with('success', 'Akun berhasil dibuat.');
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        abort_if($request->user()->id === $user->id, 422, 'Akun sendiri tidak dapat dinonaktifkan.');
        $user->update(['is_active' => ! $user->is_active]);
        return back()->with('success', 'Status akun diperbarui.');
    }

    public function edit(Request $request, User $user): View
    {
        abort_unless($request->user()->role === 'admin', 403);
        return view('admin.user-edit', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $identifier = $user->role === 'student' ? 'nisn' : ($user->role === 'teacher' ? 'nip' : 'username');
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], $identifier => ['required', 'string', 'max:255', 'unique:users,'.$identifier.','.$user->id], 'class_name' => ['nullable', 'string', 'max:100'], 'teaching_subject' => [$user->role === 'teacher' ? 'required' : 'nullable', 'string', 'max:100'], 'teaching_class' => [$user->role === 'teacher' ? 'required' : 'nullable', 'string', 'max:100'], 'password' => ['nullable', 'string', 'min:6', 'confirmed']]);
        $changes = ['name' => $data['name'], $identifier => $data[$identifier], 'class_name' => $data['class_name'] ?? null, 'teaching_subject' => $data['teaching_subject'] ?? null, 'teaching_class' => $data['teaching_class'] ?? null];
        if (!empty($data['password'])) $changes['password'] = $data['password'];
        $user->update($changes);
        return redirect()->route('admin.users')->with('success', 'Akun berhasil diperbarui.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        abort_if($request->user()->id === $user->id, 422, 'Akun sendiri tidak dapat dihapus.');
        $user->delete();
        return back()->with('success', 'Akun berhasil dihapus.');
    }

    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['role' => ['required', 'in:student,teacher'], 'file' => ['required', 'file', 'mimes:xlsx,csv,txt,pdf', 'max:10240']]);
        $rows = $this->importRows($data['file']->getPathname(), $data['file']->getClientOriginalExtension());
        if (count($rows) < 2) return back()->withErrors(['file' => 'Data tidak ditemukan. Gunakan Excel/CSV atau PDF teks dengan satu data per baris.']);
        $headers = array_map(fn ($value) => Str::lower(trim((string) $value)), array_shift($rows));
        $created = 0; $skipped = 0;
        foreach ($rows as $row) {
            $row = array_pad(array_map(fn ($value) => trim((string) $value), $row), count($headers), '');
            $record = array_combine($headers, array_slice($row, 0, count($headers)));
            $name = $record['nama'] ?? $record['name'] ?? '';
            $identifier = $data['role'] === 'student' ? ($record['nisn'] ?? '') : ($record['nip'] ?? '');
            $field = $data['role'] === 'student' ? 'nisn' : 'nip';
            if ($name === '' || $identifier === '' || User::where($field, $identifier)->exists()) { $skipped++; continue; }
            User::create(['name' => $name, 'email' => 'import-'.$data['role'].'-'.sha1($identifier).'@frexam.local', 'role' => $data['role'], $field => $identifier, 'class_name' => $data['role'] === 'student' ? ($record['kelas'] ?? $record['class_name'] ?? null) : null, 'teaching_subject' => $data['role'] === 'teacher' ? ($record['mata pelajaran'] ?? $record['mapel'] ?? $record['teaching_subject'] ?? null) : null, 'teaching_class' => $data['role'] === 'teacher' ? ($record['kelas diampu'] ?? $record['kelas'] ?? $record['teaching_class'] ?? null) : null, 'password' => $record['password'] ?? $identifier, 'is_active' => true]);
            $created++;
        }
        return back()->with('success', "Impor selesai: {$created} akun dibuat, {$skipped} baris dilewati.");
    }

    private function importRows(string $path, string $extension): array
    {
        $extension = Str::lower($extension);
        if (in_array($extension, ['csv', 'txt'], true)) { $handle = fopen($path, 'r'); $rows = []; while (($row = fgetcsv($handle, 0, ',')) !== false) $rows[] = $row; fclose($handle); return $rows; }
        if ($extension === 'pdf') { $process = new Process(['pdftotext', '-layout', $path, '-']); $process->setTimeout(30)->run(); if (! $process->isSuccessful()) return []; return collect(preg_split('/\R/', $process->getOutput()))->map(fn ($line) => preg_split('/\s*(?:\||;|\t)\s*/', trim($line)))->filter(fn ($row) => count($row) > 1)->values()->all(); }
        if (! class_exists(\ZipArchive::class)) return [];
        $zip = new \ZipArchive(); if ($zip->open($path) !== true) return []; $strings = []; $shared = $zip->getFromName('xl/sharedStrings.xml'); if ($shared) { $sharedXml = simplexml_load_string($shared); foreach ($sharedXml->si as $item) $strings[] = (string) $item->t ?: implode('', $item->r->t ?? []); } $sheet = $zip->getFromName('xl/worksheets/sheet1.xml'); $zip->close(); if (! $sheet) return [];
        $xml = simplexml_load_string($sheet); $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'); return collect($xml->xpath('//x:sheetData/x:row') ?: [])->map(function ($row) use ($strings) { $values = []; foreach ($row->c as $cell) { $value = (string) $cell->v; $values[] = ((string) $cell['t'] === 's') ? ($strings[(int) $value] ?? '') : $value; } return $values; })->all();
    }
}
