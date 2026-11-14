<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quand le site n'est servi qu'en HTTPS (config cryptor.force_https), la requête est présentée à
 * l'application comme sécurisée, même si le proxy qui termine le TLS la retransmet en HTTP simple.
 *
 * Pourquoi : `URL::forceScheme('https')` ne change que les URLs *générées*. Or un lien signé
 * (confirmation d'adresse e-mail) est généré en https puis revalidé avec l'URL de la requête
 * *reçue* : si celle-ci paraît en http, la signature ne correspond plus (« 403 Invalid signature »).
 *
 * On aligne donc aussi la requête reçue : drapeau HTTPS du serveur, et en-têtes X-Forwarded-*
 * d'un proxy de confiance qui annoncerait « http » / port 80 (TLS terminé encore plus haut).
 */
class ForceHttpsRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('cryptor.force_https')) {
            $request->server->set('HTTPS', 'on');
            $request->headers->set('X-Forwarded-Proto', 'https');

            // Port annoncé par un proxy de confiance : celui de l'en-tête Host s'il en contient un
            // (https sur un port non standard), sinon 443. Jamais « 80 », qui ferait écrire https://hôte:80.
            $port = preg_match('/:(\d{1,5})$/', (string) $request->headers->get('Host'), $matches) ? $matches[1] : '443';
            $request->headers->set('X-Forwarded-Port', $port);
        }

        return $next($request);
    }
}
