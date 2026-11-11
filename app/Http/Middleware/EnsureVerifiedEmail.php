<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `verified` : exige une adresse e-mail confirmée, sauf si l'instance a désactivé
 * cette exigence (config cryptor.require_verified_email).
 *
 * La valeur est lue à chaque requête (et non à l'enregistrement des routes) : elle reste
 * donc correcte avec `route:cache`.
 */
class EnsureVerifiedEmail
{
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null): Response
    {
        if (! config('cryptor.require_verified_email')) {
            return $next($request);
        }

        return (new EnsureEmailIsVerified)->handle($request, $next, $redirectToRoute);
    }
}
