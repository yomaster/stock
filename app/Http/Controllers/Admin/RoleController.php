<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::withCount('users')->orderBy('id')->get();
        return view('admin.roles.index', compact('roles'));
    }

    public function create()
    {
        return view('admin.roles.form', ['role' => new Role(['permissions' => []])]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateRole($request);

        Role::create([
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'permissions' => $validated['permissions'] ?? [],
            'is_super'    => false,    // super role สร้างผ่าน UI ไม่ได้ (มาจาก seeder)
            'is_protected'=> false,
        ]);

        return redirect()->route('admin.roles.index')->with('success', "เพิ่มบทบาท {$validated['name']} แล้ว");
    }

    public function edit(Role $role)
    {
        return view('admin.roles.form', compact('role'));
    }

    public function update(Request $request, Role $role)
    {
        // role ที่ protected (super admin) ห้ามแก้ permissions — กัน lock-self
        if ($role->is_protected) {
            return back()->with('error', 'บทบาทนี้ถูกป้องกัน แก้ไขไม่ได้');
        }

        $validated = $this->validateRole($request, $role->id);

        $role->update([
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'permissions' => $validated['permissions'] ?? [],
        ]);

        return redirect()->route('admin.roles.index')->with('success', "แก้ไขบทบาท {$role->name} แล้ว");
    }

    public function destroy(Role $role)
    {
        if ($role->is_protected) {
            return back()->with('error', 'บทบาทนี้ถูกป้องกัน ลบไม่ได้');
        }
        if ($role->users()->exists()) {
            return back()->with('error', 'มีผู้ใช้อยู่ในบทบาทนี้ — ย้ายผู้ใช้ก่อนจึงจะลบได้');
        }
        $name = $role->name;
        $role->delete();
        return back()->with('success', "ลบบทบาท {$name} แล้ว");
    }

    private function validateRole(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name'          => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($ignoreId)],
            'description'   => 'nullable|string|max:255',
            'permissions'   => 'nullable|array',
            'permissions.*' => ['string', Rule::in(Role::validPermissionKeys())],
        ]);
    }
}
