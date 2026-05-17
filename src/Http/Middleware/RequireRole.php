<?php

namespace Anon\Core\Http\Middleware;

use Closure;
use Anon\Core\Facade\Auth;
use Anon\Core\Http\Request;
use Anon\Core\Http\Response;
use Anon\Core\Exception\HttpException;

class RequireRole
{
    public function handle(Request $request, Closure $next, string $roles, ?string $guard = null): Response
    {
        $guard = $guard ?: null;
        $roleList = array_values(array_filter(array_map('trim', explode('|', $roles))));

        if (!Auth::check($guard)) {
            throw new HttpException(401, 'Unauthorized');
        }

        if (!Auth::hasRole($roleList, $guard)) {
            throw new HttpException(403, 'Forbidden');
        }

        return $next($request);
    }
}
