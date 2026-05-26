<?php

namespace Anon\Core\Http\Middleware;

use Closure;
use Anon\Core\Facade\Auth;
use Anon\Core\Http\Request;
use Anon\Core\Http\Response;
use Anon\Core\Exception\Http;

class Authenticate
{
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        if (!Auth::check($guard)) {
            throw new Http(401, 'Unauthorized');
        }

        return $next($request);
    }
}
