<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin user management (UserPolicy gates write actions).
 * Implemented by the user-management work unit.
 */
class UserController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        return Inertia::render('users/Index', [
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'users' => User::query()
                ->with('roles')
                ->orderBy('name')
                ->paginate(15)
                ->withQueryString()
                ->through(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->getRoleNames()->first() ?? 'user',
                    'is_active' => (bool) $user->is_active,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'created_at' => $user->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('users/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['nullable', 'string', 'in:admin,user'],
        ]);

        $role = $validated['role'] ?? 'user';
        unset($validated['role']);

        // Admin-created accounts skip email verification (S1: no public signup).
        $user = User::create($validated + ['email_verified_at' => now()]);
        $user->assignRole($role);

        return redirect()->route('users.index')->with('success', 'User created.');
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);

        return Inertia::render('users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->getRoleNames()->first() ?? 'user',
                'is_active' => (bool) $user->is_active,
            ],
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        // Partial updates are allowed (e.g. the activate/deactivate toggle
        // sends only is_active); name/email are only validated when present.
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['nullable', 'string', 'in:admin,user'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $role = $validated['role'] ?? null;
        $isActive = array_key_exists('is_active', $validated)
            ? (bool) $validated['is_active']
            : (bool) $user->is_active;
        unset($validated['role'], $validated['is_active']);

        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        // Guard: never deactivate or demote the acting admin's own account.
        if ($user->is(auth()->user()) && (! $isActive || ($role !== null && $role !== 'admin'))) {
            return back()->with('error', 'You cannot deactivate or demote your own account.');
        }

        $user->update($validated + ['is_active' => $isActive]);

        if ($role !== null) {
            $user->syncRoles([$role]);
        }

        return redirect()->route('users.index')->with('success', 'User updated.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        if ($user->is(auth()->user())) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted.');
    }
}
