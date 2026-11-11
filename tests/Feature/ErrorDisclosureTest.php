<?php

namespace Tests\Feature;

use App\Exceptions\UserFacingException;
use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\SignatureVerification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Une erreur inattendue (SQL, OpenSSL, disque...) ne doit jamais exposer ses détails au client.
 */
class ErrorDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_DETAIL = 'SQLSTATE[HY000] table key_pairs secret_column';

    /** Provoque une QueryException à la première écriture d'une vérification. */
    private function breakVerificationInserts(): void
    {
        SignatureVerification::creating(function () {
            throw new QueryException('mysql', 'insert into signatures_verifications ...', [], new \Exception(self::SECRET_DETAIL));
        });
    }

    private function signDocument(): User
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();

        $this->actingAs($user)->post(route('signatures.store'), [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'texte'),
            'key_pair_id' => $key->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ])->assertRedirect();

        return $user;
    }

    public function test_public_verification_hides_internal_errors_and_logs_them(): void
    {
        $this->signDocument();
        auth()->logout();
        Log::spy();
        $this->breakVerificationInserts();

        $response = $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'texte'),
        ]);

        $response->assertStatus(500)->assertJsonPath('success', false);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('secret_column', $response->getContent());
        $this->assertStringNotContainsString('signatures_verifications', $response->getContent());
        $this->assertStringContainsString('Une erreur inattendue', $response->json('message'));

        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => str_contains($message, 'Public verification by upload failed') && isset($context['exception']));
    }

    public function test_deliberate_public_errors_stay_readable(): void
    {
        $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('inconnu.txt', 'document jamais signé'),
        ])->assertStatus(500)
            ->assertJsonPath('message', 'Erreur lors de la vérification : Un fichier de signature est requis pour vérifier un document externe.');
    }

    public function test_authenticated_verification_hides_internal_errors(): void
    {
        $user = $this->signDocument();
        $this->breakVerificationInserts();

        $this->actingAs($user)->post(route('verifications.store'), [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'texte'),
        ])->assertOk()->assertInertia(fn ($page) => $page
            ->where('verificationResult.success', false)
            ->where('verificationResult.message', 'Verification failed: '.UserFacingException::GENERIC));
    }

    public function test_signing_hides_internal_errors_but_keeps_clear_user_errors(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $revoked = KeyPair::factory()->for($user)->create(['is_revoked' => true]);
        $key = KeyPair::factory()->for($user)->create();

        // Erreur voulue : message clair.
        $this->actingAs($user)->post(route('signatures.store'), [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'texte'),
            'key_pair_id' => $revoked->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ])->assertSessionHasErrors();

        // Erreur inattendue (base de données) : message générique.
        Signature::creating(function () {
            throw new QueryException('mysql', 'insert into signatures ...', [], new \Exception(self::SECRET_DETAIL));
        });

        $response = $this->post(route('signatures.store'), [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'texte'),
            'key_pair_id' => $key->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ]);

        $response->assertSessionHasErrors('error');
        $message = session('errors')->first('error');
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('secret_column', $message);
        $this->assertSame('Failed to sign document: '.UserFacingException::GENERIC, $message);
    }

    public function test_user_facing_exception_helper(): void
    {
        $this->assertSame('visible', UserFacingException::safeMessage(new UserFacingException('visible')));
        $this->assertSame(UserFacingException::GENERIC, UserFacingException::safeMessage(new \RuntimeException('secret')));
        $this->assertSame('générique', UserFacingException::safeMessage(new \Exception('secret'), 'générique'));
    }
}
