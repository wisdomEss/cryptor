<?php

namespace Tests\Feature;

use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\User;
use App\Services\Crypto\CmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function signDocument(string $content = 'contenu confidentiel'): array
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();

        $this->actingAs($user)->post(route('signatures.store'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', $content),
            'key_pair_id' => $key->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ])->assertRedirect();

        auth()->logout();

        return [$user, $key, Signature::firstOrFail()];
    }

    public function test_anyone_can_verify_a_signed_document_by_upload(): void
    {
        $this->signDocument('contenu confidentiel');

        $response = $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'contenu confidentiel'),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('details.signature_valid', true)
            ->assertJsonPath('details.document_integrity', true)
            ->assertJsonPath('details.file_name', 'contrat.txt');

        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('signatures_verifications', ['is_public_verification' => true, 'verifier_id' => null]);
    }

    public function test_public_result_can_be_replayed_with_its_token(): void
    {
        $this->signDocument();

        $token = $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'contenu confidentiel'),
        ])->json('token');

        $this->postJson(route('api.public.verify'), ['token' => $token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('details.document_integrity', true);
    }

    public function test_unknown_token_is_rejected(): void
    {
        $this->postJson(route('api.public.verify'), ['token' => 'inconnu'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Token de vérification invalide.');
    }

    public function test_modified_document_without_signature_file_cannot_be_verified(): void
    {
        $this->signDocument('contenu confidentiel');

        $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'contenu FALSIFIE'),
        ])->assertStatus(500)->assertJsonPath('success', false);
    }

    public function test_external_signature_with_public_key_is_verified(): void
    {
        $key = KeyPair::factory()->create();
        openssl_sign('document externe', $raw, $key->private_key, OPENSSL_ALGO_SHA256);

        $signatureFile = UploadedFile::fake()->createWithContent('doc.sig.json', json_encode([
            'signature' => base64_encode($raw),
            'public_key' => $key->public_key,
            'file_hash' => hash('sha256', 'document externe'),
        ]));

        $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('doc.txt', 'document externe'),
            'signature_file' => $signatureFile,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('details.document_integrity', true);
    }

    public function test_external_signature_fails_when_document_differs(): void
    {
        $key = KeyPair::factory()->create();
        openssl_sign('document externe', $raw, $key->private_key, OPENSSL_ALGO_SHA256);

        $signatureFile = UploadedFile::fake()->createWithContent('doc.sig.json', json_encode([
            'signature' => base64_encode($raw),
            'public_key' => $key->public_key,
        ]));

        $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('doc.txt', 'document MODIFIE'),
            'signature_file' => $signatureFile,
        ])->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('details.signature_valid', false);
    }

    public function test_authenticated_external_verification_uses_the_same_logic(): void
    {
        $user = User::factory()->create();
        $key = KeyPair::factory()->create();
        openssl_sign('document externe', $raw, $key->private_key, OPENSSL_ALGO_SHA256);

        $this->actingAs($user)->post(route('verifications.store'), [
            'file' => UploadedFile::fake()->createWithContent('doc.txt', 'document externe'),
            'signature_file' => UploadedFile::fake()->createWithContent('doc.sig.json', json_encode([
                'signature' => base64_encode($raw),
                'public_key' => $key->public_key,
            ])),
        ])->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('verificationResult.success', true)
                ->where('verificationResult.message', 'External signature is valid'));

        $this->assertDatabaseHas('signatures_verifications', [
            'verification_type' => 'external',
            'verifier_id' => $user->id,
            'is_public_verification' => false,
        ]);
    }

    private function externalCms(KeyPair $key, string $content, bool $der = true): string
    {
        $file = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($file, $content);
        $cms = new CmsService;
        $pem = $cms->sign($file, $key->certificate_data, $key->private_key);
        @unlink($file);

        return $der ? $cms->pemToDer($pem) : $pem;
    }

    public function test_external_cms_signature_is_verified_with_its_embedded_certificate(): void
    {
        $key = KeyPair::factory()->create();

        foreach ([true, false] as $der) {
            $this->postJson(route('api.public.verify'), [
                'file' => UploadedFile::fake()->createWithContent('doc.txt', 'document CMS'),
                'signature_file' => UploadedFile::fake()->createWithContent('doc.p7s', $this->externalCms($key, 'document CMS', $der)),
            ])->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('details.signer', 'Test Signer');
        }
    }

    public function test_external_cms_signature_fails_when_the_document_was_modified(): void
    {
        $key = KeyPair::factory()->create();

        $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('doc.txt', 'document MODIFIE'),
            'signature_file' => UploadedFile::fake()->createWithContent('doc.p7s', $this->externalCms($key, 'document CMS')),
        ])->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('details.signature_valid', false)
            ->assertJsonPath('details.signer', 'Test Signer');
    }

    /** Chemin de production complet : clé générée par l'application, signature, vérification publique. */
    #[DataProvider('generatedKeys')]
    public function test_full_flow_with_keys_generated_by_the_application(array $keyParams): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $this->actingAs($user)->post(route('keys.store'), ['key_name' => 'Clé test'] + $keyParams)
            ->assertRedirect(route('keys.index'));

        $key = KeyPair::firstOrFail();

        $this->post(route('signatures.store'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'accord signé'),
            'key_pair_id' => $key->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ])->assertRedirect();

        auth()->logout();

        $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'accord signé'),
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('details.signature_valid', true);
    }

    public static function generatedKeys(): array
    {
        return [
            'rsa 2048' => [['algorithm' => 'rsa', 'key_size' => 2048]],
            'ecdsa p-256' => [['algorithm' => 'ecdsa']],
        ];
    }
}
