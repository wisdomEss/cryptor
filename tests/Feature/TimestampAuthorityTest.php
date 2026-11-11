<?php

namespace Tests\Feature;

use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\User;
use App\Services\Crypto\CmsService;
use App\Services\Crypto\TimestampAuthority;
use App\Services\Verification\SignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FakeTimestampServer;
use Tests\TestCase;

class TimestampAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private FakeTimestampServer $server;

    private TimestampAuthority $tsa;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FakeTimestampServer::available()) {
            $this->markTestSkipped('Binaire openssl indisponible.');
        }

        Storage::fake('local');
        $this->server = new FakeTimestampServer;
        $this->tsa = new TimestampAuthority;

        config([
            'cryptor.tsa.url' => 'https://tsa.test/tsr',
            'cryptor.tsa.ca_file' => $this->server->caFile,
        ]);
    }

    // ---------------------------------------------------------------- client

    public function test_disabled_by_default(): void
    {
        config(['cryptor.tsa.url' => null]);

        $this->assertFalse($this->tsa->enabled());
        $this->expectException(RuntimeException::class);

        $this->tsa->timestamp('données');
    }

    public function test_a_token_is_obtained_and_verified_against_the_trusted_authority(): void
    {
        $this->server->fakeHttp();

        $result = $this->tsa->timestamp('données à horodater');

        $this->assertSame('rfc3161', $result['type']);
        $this->assertSame(hash('sha256', 'données à horodater'), $result['hash']);
        $this->assertTrue($result['trusted']);
        $this->assertEqualsWithDelta(now()->timestamp, strtotime($result['gen_time']), 120);
        $this->assertSame(1, $this->server->requests);

        // La requête est bien du RFC 3161 (la TSA, qui l'a décodée avec openssl, l'a acceptée).
        Http::assertSent(fn ($request) => $request->hasHeader('Content-Type', 'application/timestamp-query'));

        // Le jeton stocké se re-vérifie plus tard, sans nonce.
        $verified = $this->tsa->verifyToken(base64_decode($result['token']), hash('sha256', 'données à horodater', true));
        $this->assertTrue($verified['valid']);
        $this->assertSame($result['serial'], $verified['serial']);
    }

    public function test_an_unknown_authority_is_reported_as_untrusted_but_the_token_is_still_checked(): void
    {
        config(['cryptor.tsa.ca_file' => null]);
        $this->server->fakeHttp();

        $this->assertFalse($this->tsa->timestamp('données')['trusted']);
    }

    public function test_an_authority_from_another_chain_is_not_trusted(): void
    {
        $other = new FakeTimestampServer;
        config(['cryptor.tsa.ca_file' => $other->caFile]); // on ne fait confiance qu'à une autre AC
        $this->server->fakeHttp();

        $this->assertFalse($this->tsa->timestamp('données')['trusted']);
    }

    public function test_a_token_for_other_data_is_rejected(): void
    {
        // La TSA répond à une requête portant sur d'autres données.
        $foreign = $this->server->reply($this->tsa->buildRequest(hash('sha256', 'autre chose', true), "\x01nonce123"));
        Http::fake(fn () => Http::response($foreign, 200));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match');

        $this->tsa->timestamp('données');
    }

    public function test_a_replayed_response_with_a_different_nonce_is_rejected(): void
    {
        // Même données, mais nonce différent : réponse rejouée.
        $replayed = $this->server->reply($this->tsa->buildRequest(hash('sha256', 'données', true), "\x01rejouee1"));
        Http::fake(fn () => Http::response($replayed, 200));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nonce');

        $this->tsa->timestamp('données');
    }

    public function test_a_tampered_token_is_rejected(): void
    {
        $this->server->fakeHttp(function (string $reply) {
            $reply[strlen($reply) - 10] = chr(ord($reply[strlen($reply) - 10]) ^ 0xFF);

            return $reply;
        });

        $this->expectException(RuntimeException::class);

        $this->tsa->timestamp('données');
    }

    public function test_a_rejection_status_is_reported(): void
    {
        // TimeStampResp { PKIStatusInfo { status = 2 (rejection) } } sans jeton
        Http::fake(fn () => Http::response("\x30\x05\x30\x03\x02\x01\x02", 200));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rejected');

        $this->tsa->timestamp('données');
    }

    public function test_http_errors_are_reported(): void
    {
        Http::fake(fn () => Http::response('Service Unavailable', 503));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');

        $this->tsa->timestamp('données');
    }

    /** @return array<string, array{0: string}> */
    public static function hostileResponses(): array
    {
        return [
            'vide' => [''],
            'octets aléatoires' => ["\x00\x01\x02\x03garbage"],
            'tronquée' => ["\x30\x10\x30\x03\x02"],
            'longueur énorme' => ["\x30\x84\x7f\xff\xff\xff\x30\x03\x02\x01\x00"],
            'longueur indéfinie' => ["\x30\x80\x30\x03\x02\x01\x00\x00\x00"],
            'longueur sur 9 octets' => ["\x30\x89\xff\xff\xff\xff\xff\xff\xff\xff\xff"],
            'octets en trop' => ["\x30\x05\x30\x03\x02\x01\x00\xde\xad"],
            'accordée sans jeton' => ["\x30\x05\x30\x03\x02\x01\x00"],
            'statut absent' => ["\x30\x02\x30\x00"],
        ];
    }

    #[DataProvider('hostileResponses')]
    public function test_malformed_or_hostile_responses_only_raise_a_clean_exception(string $response): void
    {
        $this->expectException(RuntimeException::class);

        // Aucun avertissement PHP ni autre type d'erreur ne doit s'échapper du décodeur DER.
        $this->tsa->extractToken($response);
    }

    public function test_oversized_response_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->tsa->extractToken(str_repeat("\x30", 300000));
    }

    // ----------------------------------------------------------- intégration

    private function signDocument(): array
    {
        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();

        $this->actingAs($user)->post(route('signatures.store'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'accord horodaté'),
            'key_pair_id' => $key->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ])->assertRedirect();

        auth()->logout();

        return [$user, Signature::firstOrFail()];
    }

    public function test_signatures_carry_a_third_party_timestamp_when_a_tsa_is_configured(): void
    {
        $this->server->fakeHttp();

        [, $signature] = $this->signDocument();

        $token = json_decode($signature->timestamp_token, true);
        $this->assertSame('rfc3161', $token['type']);
        // On horodate la signature elle-même (le .p7s en DER).
        $this->assertSame(hash('sha256', (new CmsService)->pemToDer($signature->signature_value)), $token['hash']);

        $status = (new SignatureVerifier)->timestampStatus($signature);
        $this->assertSame('trusted', $status['status']);
        $this->assertNotNull($status['time']);

        // Visible dans la vérification publique.
        $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'accord horodaté'),
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('details.timestamp.status', 'trusted');
    }

    public function test_the_timestamp_no_longer_matches_if_the_signature_is_replaced(): void
    {
        $this->server->fakeHttp();
        [, $signature] = $this->signDocument();

        // Un autre CMS valide, mais différent de celui qui a été horodaté.
        $other = KeyPair::factory()->create();
        $file = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($file, 'accord horodaté');
        $signature->update(['signature_value' => (new CmsService)->sign($file, $other->certificate_data, $other->private_key)]);
        @unlink($file);

        $this->assertSame('invalid', (new SignatureVerifier)->timestampStatus($signature->fresh())['status']);
    }

    public function test_signing_still_works_when_the_tsa_is_down(): void
    {
        Http::fake(fn () => Http::response('down', 503));
        Log::spy();

        [, $signature] = $this->signDocument();

        $this->assertSame('internal', json_decode($signature->timestamp_token, true)['type']);
        $this->assertSame('internal', (new SignatureVerifier)->timestampStatus($signature)['status']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Timestamp authority unavailable'));
    }

    public function test_internal_timestamp_is_used_when_no_tsa_is_configured(): void
    {
        config(['cryptor.tsa.url' => null]);
        Http::fake();

        [, $signature] = $this->signDocument();

        Http::assertNothingSent();
        $this->assertSame('internal', (new SignatureVerifier)->timestampStatus($signature)['status']);
    }

    public function test_legacy_signatures_without_a_typed_token_are_reported_as_internal(): void
    {
        $signature = Signature::factory()->create(['timestamp_token' => json_encode(['time' => now()->toIso8601String(), 'nonce' => 'x'])]);

        $this->assertSame('internal', (new SignatureVerifier)->timestampStatus($signature)['status']);
        $this->assertSame('none', (new SignatureVerifier)->timestampStatus(Signature::factory()->create(['timestamp_token' => null]))['status']);
    }
}
