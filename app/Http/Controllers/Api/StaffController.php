<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    public function storeReceptionist(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => UserRole::Receptionist,
            'status' => 'active',
        ]);

        return response()->json($user, 201);
    }

    public function index(): JsonResponse
    {
        $staff = User::whereIn('role', [UserRole::Receptionist, UserRole::Admin])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'role', 'status']);

        return response()->json($staff);
    }
}
