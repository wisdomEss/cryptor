<?php

use App\Http\Middleware\EnsureVerifiedEmail;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // `verified` est remplacé par une version pilotée par cryptor.require_verified_email.
        $middleware->alias(['verified' => EnsureVerifiedEmail::class]);

        $middleware->append(SecurityHeaders::class);

        // AddLinkHeadersForPreloadedAssets est volontairement absent : il recopie dans un en-tête HTTP
        // `Link` la liste des fichiers à précharger, que Vite met déjà dans le HTML (<link rel="modulepreload">).
        // Sur la page de connexion cet en-tête dépassait 5 Ko : nginx (tampon FastCGI de 4 Ko par défaut)
        // répondait « 502 Bad Gateway » et les cookies de session n'arrivaient jamais (d'où des erreurs 419).
        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
        ]);

        // Proxies dont on accepte les en-têtes X-Forwarded-* (IP réelle du client, schéma HTTPS).
        // TRUSTED_PROXIES : adresses séparées par des virgules, ou `*` (à n'utiliser que si le serveur
        // n'est joignable qu'à travers le proxy).
        $trusted = trim((string) env('TRUSTED_PROXIES', '192.168.40.10'));
        $middleware->trustProxies(at: $trusted === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $trusted)))));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
