<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    public function index(): View
    {
        return view('admin-users');
    }

    public function list(Request $request): JsonResponse
    {
        return response()->json([
            'current_user_id' => $request->user()->id,
            'data' => User::query()
                ->where('role', 'admin')
                ->orderBy('name')
                ->orderBy('email')
                ->get(['id', 'name', 'email', 'role', 'created_at']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'admin',
        ]);

        return response()->json(['data' => $user->only(['id', 'name', 'email', 'role', 'created_at'])], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureManagedAdmin($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ]);

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return response()->json(['data' => $user->only(['id', 'name', 'email', 'role', 'created_at'])]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->ensureManagedAdmin($user);

        if ($request->user()->is($user)) {
            return response()->json(['message' => 'Akun yang sedang digunakan tidak dapat dihapus.'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'Akun admin berhasil dihapus.']);
    }

    private function ensureManagedAdmin(User $user): void
    {
        abort_unless($user->isAdmin(), 404);
    }
}
