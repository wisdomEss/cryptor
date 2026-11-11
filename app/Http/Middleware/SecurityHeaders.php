<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ajoute les en-têtes de sécurité HTTP standards à toutes les réponses web.
 *
 * La CSP stricte n'est appliquée qu'hors environnement local : en développement,
 * le serveur Vite (HMR) charge scripts et WebSocket depuis un autre port.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Nonce CSP : appliqué automatiquement aux balises Vite, et utilisé à la main
        // dans app.blade.php pour les scripts inline (Ziggy, thème sombre).
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        // Derrière un proxy TLS, isSecure() peut être faux : force_https l'emporte.
        if ($request->isSecure() || config('cryptor.force_https')) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        if (! app()->environment('local', 'testing')) {
            $directives = [
                "default-src 'self'",
                $this->sources('script-src', ["'self'", "'nonce-{$nonce}'"], config('cryptor.csp.script_src')),
                "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
                "img-src 'self' data:",
                "font-src 'self' data: https://fonts.bunny.net",
                $this->sources('connect-src', ["'self'"], config('cryptor.csp.connect_src')),
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ];

            // Le navigateur remonte lui-même en https toute ressource http : filet de sécurité
            // contre le contenu mixte.
            if (config('cryptor.force_https')) {
                $directives[] = 'upgrade-insecure-requests';
            }

            $headers['Content-Security-Policy'] = implode('; ', $directives);
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /** Directive CSP : sources de base + sources supplémentaires configurées (listes séparées par des espaces). */
    private function sources(string $directive, array $base, ?string $extra): string
    {
        $extras = preg_split('/\s+/', trim((string) $extra), -1, PREG_SPLIT_NO_EMPTY);

        // On n'accepte que des sources « sûres » : pas de jokers génériques ni de caractères de contrôle.
        $extras = array_filter($extras, fn ($source) => preg_match('/^(https?:\/\/[A-Za-z0-9.*-]+(:\d+)?|wss:\/\/[A-Za-z0-9.-]+(:\d+)?)$/', $source) === 1 && $source !== 'https://*');

        return $directive.' '.implode(' ', [...$base, ...$extras]);
    }
}
