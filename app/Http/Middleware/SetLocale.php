<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the request locale from the `Accept-Language` header.
 *
 * Only `en` and `ar` are supported; any other value (or missing header)
 * falls back to the application default locale.
 */
final class SetLocale
{
    private const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Accept-Language', '');
        $primary = strtolower(trim(explode(',', $header)[0]));
        $primary = substr($primary, 0, 2);

        $locale = in_array($primary, self::SUPPORTED, true)
            ? $primary
            : (string) config('app.locale', 'en');

        app()->setLocale($locale);

        return $next($request);
    }
}
