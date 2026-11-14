<?php

namespace Tests\Feature;

use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\SignatureVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Correctifs de l'audit du 2026-11-14 : état du compte à la connexion, révocation
 * irréversible d'une signature, vérification publique réellement recalculée, et
 * données partagées à chaque page.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------- état du compte

    public function test_active_account_can_log_in(): void
    {
        $user = User::factory()->create();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_disabled_account_cannot_log_in(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_locked_account_cannot_log_in(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['account_locked_until' => now()->addHour()])->save();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_wrong_password_still_fails_without_revealing_the_account_state(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'mauvais'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // -------------------------------------------- révocation d'une signature

    public function test_revoked_signature_cannot_be_validated_again(): void
    {
        [$user, $signature] = $this->signDocument();

        $this->actingAs($user)
            ->post(route('signatures.revoke', $signature->id), ['revocation_reason' => 'clé compromise'])
            ->assertRedirect();

        $this->actingAs($user)
            ->patch(route('signatures.validate', $signature->id))
            ->assertSessionHasErrors('error');

        $signature->refresh();
        $this->assertSame('revoked', $signature->validation_status);
        $this->assertFalse((bool) $signature->is_valid);
    }

    // --------------------------------------- vérification publique par token

    public function test_token_replay_reports_a_document_tampered_with_after_signing(): void
    {
        [, $signature] = $this->signDocument();

        $token = $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'contenu'),
        ])->json('token');

        // Le document conservé est remplacé après coup (disque compromis, altération).
        Storage::disk('local')->put($signature->file->getRawOriginal('file_path'), 'contenu falsifié');

        $this->postJson(route('api.public.verify'), ['token' => $token])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('details.document_integrity', false);
    }

    public function test_token_replay_reports_a_revoked_signature_as_invalid(): void
    {
        [$user, $signature] = $this->signDocument();

        $token = $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'contenu'),
        ])->json('token');

        $this->actingAs($user)
            ->post(route('signatures.revoke', $signature->id), ['revocation_reason' => 'erreur'])
            ->assertRedirect();

        auth()->logout();

        $this->postJson(route('api.public.verify'), ['token' => $token])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('details.signature_valid', false);
    }

    // ------------------------------------------------- données partagées

    public function test_shared_user_props_stay_minimal(): void
    {
        $user = User::factory()->create(['phone_number' => '+33600000000', 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $shared = $response->viewData('page')['props']['auth']['user'];

        $this->assertSame(
            ['id', 'name', 'email', 'email_verified_at', 'two_factor_enabled'],
            array_keys($shared)
        );
    }

    public function test_verification_detail_does_not_expose_account_internals(): void
    {
        $owner = User::factory()->create([
            'phone_number' => '+33600000000',
            'role' => 'admin',
            'failed_login_attempts' => 7,
        ]);

        $signature = Signature::factory()->for($owner, 'signer')->create();
        $verification = SignatureVerification::factory()->for($signature)->create([
            'verifier_id' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->get(route('verifications.show', $verification->id))
            ->assertOk();

        $payload = json_encode($response->viewData('page')['props']['verification']);

        foreach (['phone_number', 'failed_login_attempts', 'account_locked_until', 'preferences', 'file_path'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $payload, "La page expose « {$leak} ».");
        }

        $this->assertSame(
            ['id', 'name', 'email'],
            array_keys($response->viewData('page')['props']['verification']['signature']['signer'])
        );
    }

    /** @return array{0: User, 1: Signature} */
    private function signDocument(string $content = 'contenu'): array
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

        return [$user, Signature::firstOrFail()];
    }
}
