<?php

namespace Tests\Feature;

use App\Models\KeyPair;
use App\Models\Signature;
use App\Services\Crypto\CmsService;
use App\Services\Verification\SignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignatureVerifierTest extends TestCase
{
    use RefreshDatabase;

    private SignatureVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = new SignatureVerifier;
    }

    private function legacySignature(KeyPair $key, string $content, bool $withDigest): Signature
    {
        openssl_sign($content, $raw, $key->private_key, OPENSSL_ALGO_SHA256);

        $payload = ['signature' => base64_encode($raw)];
        if ($withDigest) {
            $payload['signedAttributes'] = ['messageDigest' => base64_encode(hash('sha256', $content, true))];
        }

        return Signature::factory()->create([
            'key_pair_id' => $key->id,
            'signature_value' => json_encode($payload),
        ])->load('keyPair');
    }

    public function test_legacy_json_signature_is_verified(): void
    {
        $key = KeyPair::factory()->create();

        $signature = $this->legacySignature($key, 'bonjour', withDigest: true);

        $this->assertTrue($this->verifier->verifySignature($signature, 'bonjour'));
        $this->assertFalse($this->verifier->verifySignature($signature, 'bonjour!'));
    }

    public function test_legacy_signature_without_digest_is_verified(): void
    {
        $key = KeyPair::factory()->create();

        $signature = $this->legacySignature($key, 'bonjour', withDigest: false);

        $this->assertTrue($this->verifier->verifySignature($signature, 'bonjour'));
        $this->assertFalse($this->verifier->verifySignature($signature, 'autre'));
    }

    public function test_raw_base64_signature_is_verified(): void
    {
        $key = KeyPair::factory()->create();
        openssl_sign('bonjour', $raw, $key->private_key, OPENSSL_ALGO_SHA256);

        $signature = Signature::factory()->create([
            'key_pair_id' => $key->id,
            'signature_value' => base64_encode($raw),
        ])->load('keyPair');

        $this->assertTrue($this->verifier->verifySignature($signature, 'bonjour'));
    }

    public function test_signature_made_with_another_key_is_rejected(): void
    {
        $key = KeyPair::factory()->create();
        $other = KeyPair::factory()->create();

        $signature = $this->legacySignature($other, 'bonjour', withDigest: true);
        $signature->setRelation('keyPair', $key);

        $this->assertFalse($this->verifier->verifySignature($signature, 'bonjour'));
    }

    public function test_corrupted_signature_value_returns_false_instead_of_throwing(): void
    {
        $key = KeyPair::factory()->create();

        $signature = Signature::factory()->create([
            'key_pair_id' => $key->id,
            'signature_value' => '{pas du json ni du base64 valide',
        ])->load('keyPair');

        $this->assertFalse($this->verifier->verifySignature($signature, 'bonjour'));
    }

    public function test_messages_are_localized(): void
    {
        $this->assertSame('The document is authentic and the signature is valid.', $this->verifier->message(true, true, true));
        $this->assertSame('Le document est authentique et la signature est valide.', $this->verifier->message(true, true, true, 'fr'));
        $this->assertSame('The document has been modified since it was signed.', $this->verifier->message(false, false, true));
        $this->assertSame('La signature est invalide ou a été altérée.', $this->verifier->message(false, true, false, 'fr'));
        $this->assertSame('The document has been modified and the signature is invalid.', $this->verifier->message(false, false, false));
    }

    public function test_external_signature_parsing(): void
    {
        $this->assertSame(['signature' => 'abc'], $this->verifier->parseExternalSignature('{"signature":"abc"}'));
        $this->assertSame('raw', $this->verifier->parseExternalSignature("\x00\x01\x02binaire")['format']);
        $this->assertNull($this->verifier->parseExternalSignature('simple texte sans signature'));
    }

    private function cmsSignature(KeyPair $signingKey, string $content, KeyPair $registeredKey): Signature
    {
        $file = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($file, $content);
        $cms = (new CmsService)->sign($file, $signingKey->certificate_data, $signingKey->private_key);
        @unlink($file);

        return Signature::factory()->create([
            'key_pair_id' => $registeredKey->id,
            'signature_value' => $cms,
        ])->load('keyPair');
    }

    public function test_cms_signature_is_verified_against_the_registered_key(): void
    {
        $key = KeyPair::factory()->create();
        $signature = $this->cmsSignature($key, 'bonjour', $key);

        $this->assertTrue($this->verifier->verifySignature($signature, 'bonjour'));
        $this->assertFalse($this->verifier->verifySignature($signature, 'bonjour modifié'));
    }

    public function test_cms_signed_by_another_certificate_is_rejected_even_if_cryptographically_valid(): void
    {
        $registered = KeyPair::factory()->create();
        $attacker = KeyPair::factory()->create();

        // Signature CMS parfaitement valide, mais faite avec la clé d'un tiers.
        $signature = $this->cmsSignature($attacker, 'bonjour', $registered);

        $this->assertFalse($this->verifier->verifySignature($signature, 'bonjour'));
    }

    public function test_external_cms_files_are_detected_in_pem_and_der(): void
    {
        $key = KeyPair::factory()->create();
        $signature = $this->cmsSignature($key, 'bonjour', $key);
        $cms = new CmsService;

        $this->assertSame('CMS', $this->verifier->parseExternalSignature($signature->signature_value)['format']);
        $this->assertSame('CMS', $this->verifier->parseExternalSignature($cms->pemToDer($signature->signature_value))['format']);
    }
}
