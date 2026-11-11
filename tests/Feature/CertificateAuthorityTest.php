<?php

namespace Tests\Feature;

use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\User;
use App\Services\Crypto\CertificateAuthority;
use App\Services\Crypto\CmsService;
use App\Services\Verification\SignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificateAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private CertificateAuthority $ca;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->ca = new CertificateAuthority;
    }

    /** Clé + certificat émis par l'AC interne. */
    private function issuedKey(?User $user = null, int $days = 30): KeyPair
    {
        $key = KeyPair::factory()->for($user ?? User::factory()->create())->create();
        $issued = $this->ca->issue($key->private_key, $this->ca->subjectFor('Alice', 'alice@example.org'), $days);
        $key->update(['certificate_data' => $issued['certificate'], 'certificate_issuer' => $issued['issuer']]);

        return $key->fresh();
    }

    public function test_the_authority_is_created_on_first_use_without_any_setup(): void
    {
        $this->assertFalse($this->ca->exists());

        $cert = $this->ca->certificate();

        $this->assertTrue($this->ca->exists());
        $parsed = openssl_x509_parse($cert);
        $this->assertSame('CRYPTOR Internal CA', $parsed['subject']['CN']);
        $this->assertStringContainsString('CA:TRUE', $parsed['extensions']['basicConstraints']);
        $this->assertStringContainsString('Certificate Sign', $parsed['extensions']['keyUsage']);
        $this->assertSame($cert, $this->ca->certificate(), 'Un second appel ne doit pas régénérer l\'AC.');
    }

    public function test_the_authority_private_key_is_encrypted_at_rest(): void
    {
        $this->ca->ensure();

        $stored = Storage::disk('local')->get('pki/ca.key');

        $this->assertStringNotContainsString('PRIVATE KEY', $stored);
    }

    public function test_issued_certificate_is_signed_by_the_authority_and_limited_to_signing(): void
    {
        $key = $this->issuedKey();
        $parsed = openssl_x509_parse($key->certificate_data);

        $this->assertTrue($this->ca->isIssuedByCa($key->certificate_data));
        $this->assertSame('CRYPTOR Internal CA', $parsed['issuer']['CN']);
        $this->assertStringContainsString('CA:FALSE', $parsed['extensions']['basicConstraints']);
        $this->assertStringContainsString('Digital Signature', $parsed['extensions']['keyUsage']);
        $this->assertStringNotContainsString('Certificate Sign', $parsed['extensions']['keyUsage']);

        // Le certificat porte bien la clé de l'utilisateur.
        $this->assertSame(
            preg_replace('/\s+/', '', $key->public_key),
            preg_replace('/\s+/', '', (new CmsService)->publicKeyFromCertificate($key->certificate_data))
        );
    }

    public function test_certificate_lifetime_cannot_exceed_the_configured_maximum(): void
    {
        $key = KeyPair::factory()->create();

        $issued = $this->ca->issue($key->private_key, $this->ca->subjectFor('Alice'), 99999);

        $this->assertLessThanOrEqual((int) config('cryptor.certificate.max_days'), now()->diffInDays($issued['valid_to']) + 1);
    }

    public function test_serial_numbers_are_unique(): void
    {
        $this->assertNotSame($this->issuedKey()->certificate_data, $this->issuedKey()->certificate_data);
        $this->assertNotSame(
            $this->ca->serialOf($this->issuedKey()->certificate_data),
            $this->ca->serialOf($this->issuedKey()->certificate_data)
        );
    }

    public function test_chain_validation_statuses(): void
    {
        // trusted
        $key = $this->issuedKey();
        $this->assertSame('trusted', $this->ca->validateChain($key->certificate_data)['status']);

        // self_signed : ancien certificat auto-signé (factory)
        $legacy = KeyPair::factory()->create();
        $this->assertSame('self_signed', $this->ca->validateChain($legacy->certificate_data)['status']);

        // untrusted : émis par une autre AC
        $foreignCa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => 'Foreign CA'], $foreignCa);
        $foreignCert = openssl_csr_sign($csr, null, $foreignCa, 30);
        $leaf = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $leafCsr = openssl_csr_new(['commonName' => 'Mallory'], $leaf);
        openssl_x509_export(openssl_csr_sign($leafCsr, $foreignCert, $foreignCa, 30), $foreignLeaf);
        $this->assertSame('untrusted', $this->ca->validateChain($foreignLeaf)['status']);

        // invalid
        $this->assertSame('invalid', $this->ca->validateChain('pas un certificat')['status']);
    }

    public function test_expired_certificate_is_reported(): void
    {
        $key = $this->issuedKey(days: 1);

        $this->travel(3)->days();

        $this->assertSame('expired', $this->ca->validateChain($key->certificate_data)['status']);
    }

    public function test_revoked_key_certificate_is_reported_and_published(): void
    {
        $key = $this->issuedKey();
        $serial = $this->ca->serialOf($key->certificate_data);

        $this->assertSame([], $this->ca->revocationList()['payload']['revoked']);

        $key->update(['is_revoked' => true, 'revoked_at' => now()]);

        $this->assertSame('revoked', $this->ca->validateChain($key->certificate_data)['status']);

        $list = $this->ca->revocationList();
        $this->assertSame($serial, $list['payload']['revoked'][0]['serial']);

        // La liste est signée par l'AC : un tiers peut la vérifier avec le certificat public.
        $this->assertSame(1, openssl_verify(
            json_encode($list['payload'], JSON_UNESCAPED_SLASHES),
            base64_decode($list['signature']),
            openssl_pkey_get_public($this->ca->certificate()),
            OPENSSL_ALGO_SHA384
        ));
    }

    public function test_revoked_legacy_self_signed_certificates_are_not_listed(): void
    {
        KeyPair::factory()->create(['is_revoked' => true, 'revoked_at' => now()]);

        $this->assertSame([], $this->ca->revocationList()['payload']['revoked']);
    }

    public function test_public_endpoints_expose_the_authority_certificate_and_revocation_list(): void
    {
        $this->get(route('pki.ca'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/x-x509-ca-cert');
        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $this->get(route('pki.ca'))->getContent());

        $this->getJson(route('pki.revocations'))
            ->assertOk()
            ->assertJsonStructure(['payload' => ['issuer', 'generated_at', 'revoked'], 'signature']);
    }

    public function test_revocation_list_never_exposes_the_revocation_reason(): void
    {
        $key = $this->issuedKey();
        $key->update(['is_revoked' => true, 'revoked_at' => now(), 'revocation_reason' => 'clé volée chez le client X']);

        $this->assertStringNotContainsString('volée', $this->getJson(route('pki.revocations'))->getContent());
    }

    public function test_keys_created_through_the_app_are_issued_by_the_authority(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('keys.store'), [
            'key_name' => 'Ma clé',
            'algorithm' => 'ecdsa',
        ])->assertRedirect(route('keys.index'));

        $key = KeyPair::firstOrFail();
        $parsed = openssl_x509_parse($key->certificate_data);

        $this->assertTrue($this->ca->isIssuedByCa($key->certificate_data));
        $this->assertSame('Ma clé', $parsed['subject']['CN']);
        $this->assertSame($user->email, $parsed['subject']['emailAddress']);
        $this->assertSame('CRYPTOR Internal CA', $key->certificate_issuer);
        // Plus de lieu ni de pays codés en dur dans le sujet.
        $this->assertArrayNotHasKey('L', $parsed['subject']);
        $this->assertArrayNotHasKey('ST', $parsed['subject']);
        // La validité de la clé suit celle du certificat (365 jours par défaut).
        $this->assertEqualsWithDelta(365, now()->diffInDays($key->valid_to), 1);
    }

    public function test_ca_init_command_is_idempotent(): void
    {
        $this->artisan('cryptor:ca-init')->expectsOutputToContain('créée')->assertSuccessful();
        $first = $this->ca->certificate();

        $this->artisan('cryptor:ca-init')->expectsOutputToContain('existe déjà')->assertSuccessful();
        $this->assertSame($first, $this->ca->certificate());
    }

    public function test_reissue_command_moves_legacy_certificates_under_the_authority_and_keeps_old_signatures_valid(): void
    {
        $user = User::factory()->create();
        $legacy = KeyPair::factory()->for($user)->create();
        $publicKeyBefore = $legacy->public_key;

        // Signature faite AVANT la ré-émission, avec l'ancien certificat auto-signé.
        $file = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($file, 'ancien document');
        $signature = Signature::factory()->create([
            'key_pair_id' => $legacy->id,
            'signature_value' => (new CmsService)->sign($file, $legacy->certificate_data, $legacy->private_key),
        ]);
        @unlink($file);

        $this->artisan('cryptor:reissue-certificates', ['--dry-run' => true])->assertSuccessful();
        $this->assertFalse($this->ca->isIssuedByCa($legacy->fresh()->certificate_data), 'Le dry-run ne doit rien modifier.');

        $this->artisan('cryptor:reissue-certificates')->expectsOutputToContain('1 certificat(s) ré-émis')->assertSuccessful();

        $legacy->refresh();
        $this->assertTrue($this->ca->isIssuedByCa($legacy->certificate_data));
        $this->assertSame($publicKeyBefore, $legacy->public_key);
        $this->assertSame('trusted', $this->ca->validateChain($legacy->certificate_data)['status']);
        $this->assertTrue((new SignatureVerifier)->verifySignature($signature->load('keyPair'), 'ancien document'));

        // Idempotent : un second passage ne ré-émet rien.
        $this->artisan('cryptor:reissue-certificates')->expectsOutputToContain('0 certificat(s) ré-émis')->assertSuccessful();
    }

    public function test_verification_results_report_certificate_trust(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('keys.store'), ['key_name' => 'k', 'algorithm' => 'ecdsa']);
        $this->post(route('signatures.store'), [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'texte'),
            'key_pair_id' => KeyPair::firstOrFail()->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ])->assertRedirect();
        auth()->logout();

        $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'texte'),
        ])->assertOk()->assertJsonPath('details.certificate_trust', 'trusted');

        // Une fois la clé révoquée, le certificat n'est plus de confiance.
        KeyPair::firstOrFail()->update(['is_revoked' => true, 'revoked_at' => now()]);

        $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'texte'),
        ])->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('details.certificate_trust', 'revoked');
    }

    public function test_token_verification_reflects_the_current_certificate_status(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('keys.store'), ['key_name' => 'k', 'algorithm' => 'ecdsa']);
        $this->post(route('signatures.store'), [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'texte'),
            'key_pair_id' => KeyPair::firstOrFail()->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ]);
        auth()->logout();

        $token = $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'texte'),
        ])->json('token');

        $this->postJson(route('api.public.verify'), ['token' => $token])
            ->assertJsonPath('details.certificate_trust', 'trusted');

        KeyPair::firstOrFail()->update(['is_revoked' => true, 'revoked_at' => now()]);

        $this->postJson(route('api.public.verify'), ['token' => $token])
            ->assertJsonPath('details.certificate_trust', 'revoked');
    }

    public function test_signed_document_is_fully_verifiable_with_the_openssl_cli_and_the_ca_certificate(): void
    {
        if (trim((string) shell_exec('command -v openssl')) === '') {
            $this->markTestSkipped('Binaire openssl indisponible.');
        }

        $user = User::factory()->create();
        $this->actingAs($user)->post(route('keys.store'), ['key_name' => 'k', 'algorithm' => 'rsa', 'key_size' => 2048]);
        $this->post(route('signatures.store'), [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'texte CLI'),
            'key_pair_id' => KeyPair::firstOrFail()->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ])->assertRedirect();

        $dir = sys_get_temp_dir().'/cryptor-'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/doc.txt", 'texte CLI');
        file_put_contents("$dir/ca.crt", $this->get(route('pki.ca'))->getContent());
        file_put_contents("$dir/doc.p7s", Storage::disk('local')->get(Signature::firstOrFail()->signature_file_path));

        $verify = fn (string $ca) => (function () use ($dir, $ca) {
            exec(sprintf('openssl cms -verify -inform DER -in %s -content %s %s -binary -out /dev/null 2>&1', "$dir/doc.p7s", "$dir/doc.txt", $ca), $out, $code);

            return [$code, implode("\n", $out)];
        })();

        // Validation COMPLÈTE de la chaîne (pas de -noverify) avec le certificat public de l'AC.
        [$code, $out] = $verify("-CAfile $dir/ca.crt");
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('successful', $out);

        // Sans l'AC, la chaîne n'est pas de confiance.
        [$code] = $verify('');
        $this->assertNotSame(0, $code);

        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
