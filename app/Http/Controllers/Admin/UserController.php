<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeRoleRequest;
use App\Http\Requests\Admin\StoreStaffRequest;
use App\Http\Requests\Staff\ReasonRequest;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'role' => ['nullable', Rule::enum(Role::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim($filters['q'] ?? '');

        $users = User::query()
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', like_pattern($search))->orWhere('email', 'like', like_pattern($search))))
            ->orderByRaw("FIELD(role, 'admin', 'staff', 'customer')")
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role->value,
                'verified' => $user->email_verified_at !== null,
                'active' => $user->deactivated_at === null,
                'is_me' => $user->is($request->user()),
                'created_at' => $user->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => ['role' => $filters['role'] ?? null, 'q' => $search],
            'roles' => collect(Role::cases())->map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()]),
        ]);
    }

    public function store(StoreStaffRequest $request, AccountService $accounts): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $data = $request->validated();
        $user = $accounts->createStaff($data['name'], $data['email'], $data['phone'] ?? null, $data['password'], Role::from($data['role']), $request->user());

        return back(303)->with('success', "{$user->name} can now log in as {$user->role->label()}. Share the temporary password privately.");
    }

    public function role(ChangeRoleRequest $request, User $user, AccountService $accounts): RedirectResponse
    {
        Gate::authorize('update', $user);

        $accounts->changeRole($user, Role::from($request->validated('role')), $request->validated('reason'), $request->user());

        return back(303)->with('success', "{$user->name} is now {$user->role->label()}.");
    }

    public function deactivate(ReasonRequest $request, User $user, AccountService $accounts): RedirectResponse
    {
        Gate::authorize('update', $user);

        $accounts->setActive($user, false, $request->validated('reason'), $request->user());

        return back(303)->with('success', "{$user->name} can no longer log in.");
    }

    public function reactivate(ReasonRequest $request, User $user, AccountService $accounts): RedirectResponse
    {
        Gate::authorize('update', $user);

        $accounts->setActive($user, true, $request->validated('reason'), $request->user());

        return back(303)->with('success', "{$user->name} can log in again.");
    }
}
