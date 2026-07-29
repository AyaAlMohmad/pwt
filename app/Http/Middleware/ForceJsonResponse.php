<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force every API request to be treated as JSON so that the framework
 * returns JSON envelopes (not HTML error pages) for all failure modes.
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->expectsJson()) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
