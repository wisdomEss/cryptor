<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tighten\Ziggy\BladeRouteGenerator;

class SecurityHeadersTest extends TestCase
{
    public function test_security_headers_are_present_on_responses(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('Permissions-Policy');
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security');
    }

    public function test_strict_csp_is_applied_outside_local_environment(): void
    {
        $this->app['env'] = 'production';

        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_csp_nonce_matches_inline_scripts_in_the_page(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/');

        preg_match("/'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $m);
        $this->assertNotEmpty($m[1] ?? null, 'La CSP doit contenir un nonce.');

        // Script Ziggy (@routes) et script du thème sombre : tous deux portent le nonce.
        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), 'nonce="'.$m[1].'"'));
    }

    public function test_api_responses_also_carry_security_headers(): void
    {
        $this->postJson('/api/public/verify', [])
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // ------------------------------------------- HTTPS derrière un proxy (cas de la production)

    /** Rejoue le démarrage de l'application avec force_https (le provider est déjà passé avant le test). */
    private function enableForceHttps(): void
    {
        config(['cryptor.force_https' => true]);
        $this->app->getProvider(AppServiceProvider::class)->boot();
    }

    /** Ziggy ne génère sa configuration qu'une fois par processus (drapeau statique) : on le réinitialise. */
    protected function setUp(): void
    {
        parent::setUp();

        BladeRouteGenerator::$generated = false;
    }

    public function test_without_force_https_a_proxied_request_generates_http_urls(): void
    {
        // La panne observée : la requête arrive en HTTP, donc les URLs générées le sont aussi.
        $html = $this->get('/')->getContent();

        $this->assertMatchesRegularExpression('#"http://[^"/]+/build/assets/#', $html);
        $this->assertDoesNotMatchRegularExpression('#"https://[^"/]+/build/assets/#', $html);
    }

    public function test_force_https_makes_every_generated_url_https(): void
    {
        $this->enableForceHttps();

        $html = $this->get('/')->getContent();

        $this->assertMatchesRegularExpression('#"https://[^"/]+/build/assets/#', $html, 'Les assets Vite doivent être en https.');
        $this->assertDoesNotMatchRegularExpression('#"http://[^"]*/build/#', $html, 'Aucun asset ne doit rester en http.');
        $this->assertStringContainsString('"url":"https:\/\/', $html, 'Les routes Ziggy doivent être en https.');
        $this->assertStringStartsWith('https://', route('login'));
        $this->assertStringStartsWith('https://', asset('build/x.css'));
    }

    public function test_force_https_sends_hsts_and_upgrades_insecure_requests(): void
    {
        $this->enableForceHttps();
        $this->app['env'] = 'production';

        $response = $this->get('/'); // la requête, elle, est en HTTP

        $response->assertHeader('Strict-Transport-Security');
        $this->assertStringContainsString('upgrade-insecure-requests', $response->headers->get('Content-Security-Policy'));
    }

    public function test_upgrade_insecure_requests_is_absent_when_https_is_not_forced(): void
    {
        $this->app['env'] = 'production';

        $this->assertStringNotContainsString('upgrade-insecure-requests', $this->get('/')->headers->get('Content-Security-Policy'));
    }

    public function test_force_https_is_on_by_default_only_when_app_url_is_https(): void
    {
        // Laravel lit l'environnement dans $_ENV / $_SERVER (putenv est désactivé).
        $evaluate = function (array $env): bool {
            $previous = [];
            foreach ($env as $key => $value) {
                $previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
                if ($value === null) {
                    unset($_ENV[$key], $_SERVER[$key]);
                } else {
                    $_ENV[$key] = $_SERVER[$key] = $value;
                }
            }

            try {
                return (bool) (require config_path('cryptor.php'))['force_https'];
            } finally {
                foreach ($previous as $key => [$fromEnv, $fromServer]) {
                    $fromEnv === null ? $this->unsetKey($_ENV, $key) : $_ENV[$key] = $fromEnv;
                    $fromServer === null ? $this->unsetKey($_SERVER, $key) : $_SERVER[$key] = $fromServer;
                }
            }
        };

        $this->assertTrue($evaluate(['APP_URL' => 'https://cryptor.example.org', 'CRYPTOR_FORCE_HTTPS' => null]));
        $this->assertFalse($evaluate(['APP_URL' => 'http://cryptor.example.org', 'CRYPTOR_FORCE_HTTPS' => null]));
        $this->assertFalse($evaluate(['APP_URL' => 'http://localhost', 'CRYPTOR_FORCE_HTTPS' => null]));
        // Réglage explicite : il l'emporte dans les deux sens.
        $this->assertTrue($evaluate(['APP_URL' => 'http://localhost', 'CRYPTOR_FORCE_HTTPS' => 'true']));
        $this->assertFalse($evaluate(['APP_URL' => 'https://cryptor.example.org', 'CRYPTOR_FORCE_HTTPS' => 'false']));
    }

    private function unsetKey(array &$array, string $key): void
    {
        unset($array[$key]);
    }

    public function test_a_request_from_the_trusted_proxy_is_recognised_as_https(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.40.10'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.5'])
            ->get('/');

        $response->assertHeader('Strict-Transport-Security');
    }

    public function test_forwarded_headers_from_an_untrusted_source_are_ignored(): void
    {
        // Un client quelconque ne peut pas se faire passer pour du HTTPS ni usurper son IP.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/');

        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    // --------------------------------------------------- sources CSP supplémentaires

    private function cspWith(array $config): string
    {
        $this->app['env'] = 'production';
        config($config);

        return $this->get('/')->headers->get('Content-Security-Policy');
    }

    public function test_extra_csp_sources_can_be_added_through_configuration(): void
    {
        $csp = $this->cspWith([
            'cryptor.csp.script_src' => 'https://static.cloudflareinsights.com',
            'cryptor.csp.connect_src' => 'https://cloudflareinsights.com',
        ]);

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[^']+' https:\/\/static\.cloudflareinsights\.com;/", $csp);
        $this->assertStringContainsString("connect-src 'self' https://cloudflareinsights.com;", $csp);
    }

    public function test_dangerous_csp_sources_are_dropped(): void
    {
        $csp = $this->cspWith(['cryptor.csp.script_src' => "* 'unsafe-inline' 'unsafe-eval' https://* data: javascript:alert(1) https://evil.test;script-src-elem https://ok.example.org"]);

        foreach (["'unsafe-inline'", "'unsafe-eval'", 'data:', 'javascript:', 'https://*', 'evil.test', 'script-src-elem'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, explode('style-src', $csp)[0], "{$forbidden} ne doit pas entrer dans script-src");
        }
        // La source légitime est conservée, et la directive reste stricte (seulement 'self', le nonce et elle).
        $this->assertMatchesRegularExpression("/^default-src 'self'; script-src 'self' 'nonce-[^']+' https:\/\/ok\.example\.org;/", $csp);
    }

    // ------------------------------------------------ taille des en-têtes (502 nginx)

    /** Octets occupés par les en-têtes de réponse, cookies compris. */
    private function headerBytes($response): int
    {
        $bytes = 0;

        foreach (explode("\r\n", (string) $response->baseResponse->headers) as $line) {
            if ($line !== '' && ! str_starts_with($line, 'HTTP/')) {
                $bytes += strlen($line) + 2;
            }
        }

        return $bytes;
    }

    /** @return array<string, array{0: string}> */
    public static function pages(): array
    {
        return ['accueil' => ['/'], 'connexion' => ['/login'], 'inscription' => ['/register'], 'mot de passe oublié' => ['/forgot-password']];
    }

    #[DataProvider('pages')]
    public function test_response_headers_stay_well_below_the_default_nginx_buffer(string $path): void
    {
        // Production, HTTPS forcé, cookies de session chiffrés : le pire cas.
        $this->enableForceHttps();
        $this->app['env'] = 'production';

        $response = $this->get($path);

        // nginx rejette (502) toute réponse dont les en-têtes dépassent fastcgi_buffer_size (4 Ko par défaut).
        $this->assertLessThan(3000, $this->headerBytes($response), "En-têtes de {$path} trop volumineux pour le tampon nginx par défaut.");
        $this->assertFalse($response->headers->has('Link'), 'Aucun en-tête Link de préchargement : Vite met déjà les <link> dans le HTML.');
    }

    public function test_assets_are_still_preloaded_through_the_html(): void
    {
        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('rel="modulepreload"', $html);
    }
}
