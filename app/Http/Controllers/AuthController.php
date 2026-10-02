<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $role = $request->string('role', 'student')->value();
        if ($role === 'student') {
            $data = $request->validate(['nisn' => ['required', 'string'], 'password' => ['required', 'string']]);
            $user = $this->existingUser('student', $data['nisn']);
            if (!$user || !$this->passwordMatches($data['password'], $user->password)) $user = $this->legacyUser('student', $data['nisn'], $data['password']);
            if (!$user || !($this->passwordMatches($data['password'], $user->password))) return back()->withErrors(['nisn' => 'NISN atau password tidak valid.'])->onlyInput('nisn');
            $user->forceFill(['last_login_at' => now()])->save();
            Auth::login($user);
            $request->session()->regenerate();
            $request->session()->forget('active_exam_id');
            return redirect()->route('dashboard');
        }
        abort_unless(in_array($role, ['admin', 'teacher'], true), 422);
        $identifier = $role === 'admin' ? 'username' : 'nip';
        $data = $request->validate([$identifier => ['required', 'string'], 'password' => ['required', 'string']]);
        $user = $this->existingUser($role, $data[$identifier]);
        if (!$user || !$this->passwordMatches($data['password'], $user->password)) $user = $this->legacyUser($role, $data[$identifier], $data['password']);
        if (!$user || !($this->passwordMatches($data['password'], $user->password))) return back()->withErrors([$identifier => ucfirst($identifier).' atau password tidak valid.'])->onlyInput($identifier);
        $user->forceFill(['last_login_at' => now()])->save();
        Auth::login($user); $request->session()->regenerate();
        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    private function legacyUser(string $role, string $identifier, string $password): ?User
    {
        $map = [
            'student' => ['table' => 'siswa', 'key' => 'nisn', 'password' => 'password', 'name' => 'nama_siswa', 'class' => 'jurusan', 'status' => 'status'],
            'teacher' => ['table' => 'guru', 'key' => 'nip', 'password' => 'password', 'name' => 'nama'],
            'admin' => ['table' => 'admin', 'key' => 'username_admin', 'password' => 'password', 'name' => 'nama_admin'],
        ][$role];
        if (!Schema::hasTable($map['table']) || !Schema::hasColumns($map['table'], [$map['key'], $map['password'], $map['name']])) return null;
        $record = DB::table($map['table'])->where($map['key'], $identifier)->first();
        if (!$record || !$this->passwordMatches($password, $record->{$map['password']})) return null;
        if (isset($map['status']) && ($record->{$map['status']} ?? 'aktif') !== 'aktif') return null;

        $userKey = $role === 'admin' ? 'username' : $map['key'];
        $legacyEmail = 'legacy-'.$role.'-'.sha1($identifier).'@frexam.local';
        $canStoreIdentifier = Schema::hasColumn('users', $userKey);
        // Akun yang sebelumnya telah disinkronkan mungkin belum menyimpan
        // kolom identitas. Selalu cari juga berdasarkan email internal agar
        // tidak mencoba membuat baris kedua dengan email yang sama.
        $user = User::where('email', $legacyEmail)->first();
        if (! $user && $canStoreIdentifier) $user = User::where($userKey, $identifier)->where('role', $role)->first();
        $user ??= new User(['email' => $legacyEmail]);
        $user->name = $record->{$map['name']};
        $user->role = $role;
        $user->is_active = true;
        $user->password = $record->{$map['password']};
        if ($canStoreIdentifier) $user->{$userKey} = $identifier;
        if ($role === 'student' && isset($map['class'])) $user->class_name = $record->{$map['class']} ?? null;
        if (!$user->exists && !$user->email) $user->email = $legacyEmail;
        $user->save();
        return $user;
    }

    private function passwordMatches(string $plain, string $stored): bool
    {
        try {
            if (str_starts_with($stored, '$2')) return Hash::check($plain, $stored);
        } catch (\RuntimeException) {
            // Password lama dapat berupa format hash lain atau teks biasa.
        }
        return hash_equals($stored, $plain);
    }

    private function existingUser(string $role, string $identifier): ?User
    {
        $key = ['student' => 'nisn', 'teacher' => 'nip', 'admin' => 'username'][$role];
        if (!Schema::hasTable('users') || !Schema::hasColumns('users', [$key, 'role', 'is_active'])) return null;
        return User::where($key, $identifier)->where('role', $role)->where('is_active', true)->first();
    }
}
