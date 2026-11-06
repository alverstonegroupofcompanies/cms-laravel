<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    /**
     * List all staff members (Admin, Receptionist, Pharmacy, Lab).
     */
    public function index(): JsonResponse
    {
        $staff = User::whereIn('role', [
            UserRole::Admin,
            UserRole::Receptionist,
            UserRole::Pharmacy,
            UserRole::Lab,
        ])
        ->orderBy('role')
        ->orderBy('name')
        ->get(['id', 'name', 'email', 'phone', 'role', 'status', 'created_at']);

        return response()->json($staff);
    }

    /**
     * Create any staff account with custom ID/Email, password, and role.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'string', 'in:receptionist,pharmacy,lab,admin'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $roleEnum = match ($data['role']) {
            'receptionist' => UserRole::Receptionist,
            'pharmacy' => UserRole::Pharmacy,
            'lab' => UserRole::Lab,
            'admin' => UserRole::Admin,
            default => UserRole::Receptionist,
        };

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => $roleEnum,
            'status' => $data['status'] ?? 'active',
            'must_reset_password' => false,
        ]);

        return response()->json([
            'message' => "{$user->name} successfully registered as {$user->role->value}.",
            'user' => $user,
        ], 201);
    }

    /**
     * Backward-compatible receptionist creation.
     */
    public function storeReceptionist(Request $request): JsonResponse
    {
        $request->merge(['role' => 'receptionist']);
        return $this->store($request);
    }

    /**
     * Update an existing staff member.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'string', 'in:receptionist,pharmacy,lab,admin'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $roleEnum = match ($data['role']) {
            'receptionist' => UserRole::Receptionist,
            'pharmacy' => UserRole::Pharmacy,
            'lab' => UserRole::Lab,
            'admin' => UserRole::Admin,
            default => $user->role,
        };

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => $roleEnum,
            'status' => $data['status'],
        ]);

        return response()->json([
            'message' => "Staff member {$user->name} updated successfully.",
            'user' => $user,
        ]);
    }

    /**
     * Superadmin reset password for any staff member.
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:6'],
        ]);

        $user->update([
            'password' => Hash::make($data['password']),
            'must_reset_password' => false,
        ]);

        return response()->json([
            'message' => "Password for {$user->name} ({$user->email}) has been reset successfully.",
        ]);
    }

    /**
     * Delete or deactivate a staff user account.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user() && $request->user()->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own admin account.'], 422);
        }

        $userName = $user->name;
        $user->delete();

        return response()->json([
            'message' => "Staff member {$userName} has been removed.",
        ]);
    }
}
