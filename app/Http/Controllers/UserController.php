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

        // Spec L10: created accounts are always role "user" —
        // the single admin is seeded and never created through this UI.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Admin-created accounts skip email verification (S1: no public
        // signup). email_verified_at is not mass-assignable (Fillable) —
        // set it explicitly or these users bounce to /email/verify too.
        $user = User::create($validated);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole('user');

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
            // 'role' is intentionally absent: roles are immutable (spec L10).
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $isActive = array_key_exists('is_active', $validated)
            ? (bool) $validated['is_active']
            : (bool) $user->is_active;
        unset($validated['is_active']);

        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        // Spec L10: never deactivate the acting admin's own account.
        if ($user->is(auth()->user()) && ! $isActive) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        // Roles are immutable here: there is exactly one admin (seeded)
        // and everyone else is a user (spec L10).
        $user->update($validated + ['is_active' => $isActive]);

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
