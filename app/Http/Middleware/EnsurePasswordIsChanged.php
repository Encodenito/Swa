<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accounts created by an admin start with a temporary password that was shared by hand. Until the
 * owner sets their own, the only pages they can use are the password page and logout.
 */
class EnsurePasswordIsChanged
{
    private const ALLOWED_ROUTES = ['password.edit', 'password.update', 'logout', 'verification.notice', 'verification.verify', 'verification.send'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password && ! $request->routeIs(...self::ALLOWED_ROUTES)) {
            return redirect()->route('password.edit')->with('error', 'Please replace your temporary password before you continue.');
        }

        return $next($request);
    }
}
