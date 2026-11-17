<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

/**
 * "auth", unless login is switched off (WARREN_AUTH=false). Checked per request so it can be tested and toggled.
 * Guests get a redirect to /login, or a 401 for JSON requests (the live view polls JSON).
 */
class EnsureAuthenticated extends Authenticate
{
    public function handle($request, Closure $next, ...$guards)
    {
        if (! config('warren.auth.enabled')) {
            return $next($request);
        }

        return parent::handle($request, $next, ...$guards);
    }
}
