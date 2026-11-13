<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;
use Tighten\Ziggy\BladeRouteGenerator;

/**
 * Parcours de connexion tel que le vit la production : TLS terminé en amont (Cloudflare + proxy),
 * l'application reçoit du HTTP depuis le proxy de confiance, cookies de session « Secure » et chiffrés.
 * Un 419 « Page Expired » ici signalerait un défaut de l'application elle-même.
 */
class LoginBehindProxyTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://cryptor.labsploit.com';

    protected function setUp(): void
    {
        parent::setUp();

        BladeRouteGenerator::$generated = false;

        config([
            'cryptor.force_https' => true,
            'session.secure' => true,
            'session.encrypt' => true,
            'session.domain' => null,
        ]);
        $this->app->getProvider(AppServiceProvider::class)->boot();
        $this->app['env'] = 'production';
    }

    /** Requêtes telles que le proxy de confiance les transmet. */
    private function viaProxy(): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '192.168.40.10'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.50']);
    }

    /** @return array<string, Cookie> cookies posés par la réponse, par nom */
    private function cookiesOf($response): array
    {
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }

        return $cookies;
    }

    public function test_the_login_page_sets_secure_session_and_csrf_cookies(): void
    {
        $cookies = $this->cookiesOf($this->viaProxy()->get(self::URL.'/login')->assertOk());

        $session = $cookies[config('session.cookie')] ?? null;
        $xsrf = $cookies['XSRF-TOKEN'] ?? null;

        $this->assertNotNull($session, 'Le cookie de session doit être posé dès la page de connexion.');
        $this->assertNotNull($xsrf, 'Le cookie XSRF-TOKEN doit être posé.');
        $this->assertTrue($session->isSecure());
        $this->assertTrue($session->isHttpOnly());
        $this->assertSame('lax', $session->getSameSite());
        $this->assertNull($session->getDomain(), 'Cookie limité à l\'hôte (pas de domaine partagé).');
        $this->assertTrue($xsrf->isSecure());
        $this->assertFalse($xsrf->isHttpOnly(), 'Le front doit pouvoir lire XSRF-TOKEN pour l\'envoyer en en-tête.');
    }

    public function test_login_succeeds_the_way_the_browser_does_it(): void
    {
        $user = User::factory()->create(['password' => 'Un mot de passe solide 42!']);

        // 1. Le navigateur charge la page de connexion et reçoit ses cookies (valeurs chiffrées).
        $cookies = $this->cookiesOf($this->viaProxy()->get(self::URL.'/login')->assertOk());
        $sent = [config('session.cookie') => $cookies[config('session.cookie')]->getValue(), 'XSRF-TOKEN' => $cookies['XSRF-TOKEN']->getValue()];

        // 2. Il poste le formulaire en renvoyant ses cookies, et le jeton CSRF dans X-XSRF-TOKEN (comme axios).
        $response = $this->viaProxy()
            ->withUnencryptedCookies($sent)
            ->withHeaders(['X-XSRF-TOKEN' => $cookies['XSRF-TOKEN']->getValue(), 'X-Inertia' => 'true', 'Accept' => 'text/html, application/xhtml+xml'])
            ->post(self::URL.'/login', ['email' => $user->email, 'password' => 'Un mot de passe solide 42!']);

        $this->assertNotSame(419, $response->getStatusCode(), 'Un 419 ici viendrait de l\'application.');
        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_without_the_cookies_the_login_is_refused_with_419(): void
    {
        // Ce que vit le navigateur quand les cookies n'arrivent pas (réponse rejetée par un proxy, cookies bloqués...).
        $user = User::factory()->create(['password' => 'Un mot de passe solide 42!']);

        $this->viaProxy()
            ->post(self::URL.'/login', ['email' => $user->email, 'password' => 'Un mot de passe solide 42!'])
            ->assertStatus(419);

        $this->assertGuest();
    }

    public function test_a_wrong_password_is_a_validation_error_not_a_419(): void
    {
        $user = User::factory()->create(['password' => 'Un mot de passe solide 42!']);
        $cookies = $this->cookiesOf($this->viaProxy()->get(self::URL.'/login'));

        $this->viaProxy()
            ->withUnencryptedCookies([config('session.cookie') => $cookies[config('session.cookie')]->getValue(), 'XSRF-TOKEN' => $cookies['XSRF-TOKEN']->getValue()])
            ->withHeaders(['X-XSRF-TOKEN' => $cookies['XSRF-TOKEN']->getValue(), 'Accept' => 'application/json'])
            ->postJson(self::URL.'/login', ['email' => $user->email, 'password' => 'mauvais'])
            ->assertStatus(422);
    }
}
