<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser protections for every page: no framing (clickjacking on "remove connection"), no MIME sniffing, and a
 * content policy that only allows Warren's own files. The policy is left out while the Vite dev server runs
 * (public/hot), because it serves scripts from another origin.
 */
class SecurityHeaders
{
    private const POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
        ."font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        if (! is_file(public_path('hot'))) {
            $response->headers->set('Content-Security-Policy', self::POLICY);
        }

        return $response;
    }
}
