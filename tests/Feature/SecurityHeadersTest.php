<?php

namespace Tests\Feature;

use Tests\TestCase;

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
}
