<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('role:staff,admin'). Coarse gate per route group; policies still apply per model.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $allowed = array_map(fn (string $role) => Role::from($role), $roles);

        abort_unless($user && in_array($user->role, $allowed, true), 403);

        return $next($request);
    }
}
