<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use Tighten\Ziggy\BladeRouteGenerator;

/**
 * Les liens signés (confirmation d'adresse e-mail) sont générés avec le schéma https, puis
 * revalidés avec l'adresse que voit l'application. Derrière un proxy qui termine le TLS, la
 * requête arrive en HTTP : sans correctif, la signature ne correspond plus (403 « Invalid signature »).
 */
class SignedLinksBehindProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        BladeRouteGenerator::$generated = false;
        config(['cryptor.force_https' => true]);
        $this->app->getProvider(AppServiceProvider::class)->boot();
        $this->app['env'] = 'production';
    }

    /** Le lien tel que l'e-mail le contient (https), et l'utilisateur concerné. */
    private function verificationLink(): array
    {
        $user = User::factory()->unverified()->create();

        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->assertStringStartsWith('https://', $link, 'Le lien envoyé par e-mail doit être en https.');

        return [$user, $link];
    }

    /** Le lien tel que le proxy le retransmet à l'application : en HTTP simple. */
    private function asForwardedByProxy(string $httpsLink): string
    {
        return preg_replace('#^https://#', 'http://', $httpsLink);
    }

    public function test_the_link_is_valid_when_the_request_reaches_the_application_over_plain_http(): void
    {
        [$user, $link] = $this->verificationLink();

        $this->actingAs($user)
            ->get($this->asForwardedByProxy($link))
            ->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_the_link_is_valid_from_an_untrusted_forwarder(): void
    {
        [$user, $link] = $this->verificationLink();

        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.40.15']) // proxy absent de TRUSTED_PROXIES
            ->get($this->asForwardedByProxy($link))
            ->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_the_link_is_valid_even_if_the_trusted_proxy_announces_http_and_port_80(): void
    {
        [$user, $link] = $this->verificationLink();

        // Cas typique d'un proxy placé derrière un TLS terminé plus haut (Cloudflare « Flexible »).
        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.40.10'])
            ->withHeaders(['X-Forwarded-Proto' => 'http', 'X-Forwarded-Port' => '80'])
            ->get($this->asForwardedByProxy($link))
            ->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_tampered_link_is_still_refused(): void
    {
        [$user, $link] = $this->verificationLink();

        // Le correctif ne doit pas affaiblir la protection : un lien modifié reste invalide.
        $tampered = str_replace('/email/verify/'.$user->id.'/', '/email/verify/'.($user->id + 1).'/', $this->asForwardedByProxy($link));

        $this->actingAs($user)->get($tampered)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_link_is_still_refused(): void
    {
        $user = User::factory()->unverified()->create();
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->travel(2)->hours();

        $this->actingAs($user)->get($this->asForwardedByProxy($link))->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }
}
