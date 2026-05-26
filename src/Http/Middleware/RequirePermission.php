<?php

namespace Anon\Core\Http\Middleware;

use Closure;
use Anon\Core\Facade\Auth;
use Anon\Core\Http\Request;
use Anon\Core\Http\Response;
use Anon\Core\Exception\Http;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permissions, ?string $guard = null): Response
    {
        $guard = $guard ?: null;
        $permissionList = array_values(array_filter(array_map('trim', explode('|', $permissions))));

        if (!Auth::check($guard)) {
            throw new Http(401, 'Unauthorized');
        }

        if (!Auth::hasPermission($permissionList, $guard)) {
            throw new Http(403, 'Forbidden');
        }

        return $next($request);
    }
}
