<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with('role')->orderBy('id')->get();
        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        return view('admin.users.form', [
            'user'  => new User(['status' => true]),
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nickname' => 'required|string|max:50',
            'name'     => 'nullable|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
            'role_id'  => 'required|exists:roles,id',
            'status'   => 'nullable|boolean',
        ]);

        User::create([
            'name'     => $validated['name'],
            'nickname' => $validated['nickname'] ?? null,
            'email'    => $validated['email'],
            'password' => $validated['password'], // cast 'hashed'
            'role_id'  => $validated['role_id'],
            'status'   => $request->boolean('status'),
        ]);

        return redirect()->route('admin.users.index')->with('success', "เพิ่มผู้ใช้ {$validated['nickname']} แล้ว");
    }

    public function edit(User $user)
    {
        return view('admin.users.form', [
            'user'  => $user,
            'roles' => Role::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'nickname' => 'required|string|max:50',
            'name'     => 'nullable|string|max:255',
            'email'    => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role_id'  => 'required|exists:roles,id',
            'status'   => 'nullable|boolean',
        ]);

        // กันปิดใช้งาน/ถอด role ตัวเอง จนล็อกตัวเองออก
        if ($user->id === $request->user()->id && !$request->boolean('status')) {
            return back()->with('error', 'ปิดใช้งานบัญชีตัวเองไม่ได้');
        }

        $user->update([
            'name'     => $validated['name'],
            'nickname' => $validated['nickname'] ?? null,
            'email'    => $validated['email'],
            'role_id'  => $validated['role_id'],
            'status'   => $request->boolean('status'),
        ]);

        return redirect()->route('admin.users.index')->with('success', "แก้ไขผู้ใช้ {$user->nickname} แล้ว");
    }

    public function resetPassword(Request $request, User $user)
    {
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user->update(['password' => $validated['password']]); // cast 'hashed'
        return back()->with('success', "รีเซ็ตรหัสผ่านของ {$user->name} แล้ว");
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'ลบบัญชีตัวเองไม่ได้');
        }
        $name = $user->name;
        $user->delete(); // cascade: user_stocks + portfolios (FK cascadeOnDelete)
        return back()->with('success', "ลบผู้ใช้ {$name} แล้ว");
    }
}
