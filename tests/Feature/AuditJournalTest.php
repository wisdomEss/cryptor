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
 * Journal d'audit de la vérification publique : déduplication des appels identiques
 * et purge des lignes anciennes (la table est alimentée sans authentification).
 */
class AuditJournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_public_verifications_reuse_the_same_entry(): void
    {
        $this->signDocument();

        $first = $this->verifyByUpload();
        $second = $this->verifyByUpload();

        $first->assertOk()->assertJsonPath('success', true);
        $second->assertOk()->assertJsonPath('success', true);

        $this->assertSame($first->json('token'), $second->json('token'));
        $this->assertSame(1, SignatureVerification::where('is_public_verification', true)->count());
    }

    public function test_each_public_verification_is_logged_when_deduplication_is_disabled(): void
    {
        config(['cryptor.public_verification.dedupe_minutes' => 0]);

        $this->signDocument();
        $this->verifyByUpload();
        $this->verifyByUpload();

        $this->assertSame(2, SignatureVerification::where('is_public_verification', true)->count());
    }

    public function test_two_different_verifiers_are_logged_separately(): void
    {
        $this->signDocument();

        $this->verifyByUpload();

        // La déduplication ne vaut que pour une demande identique venue de la même
        // adresse : le journal doit garder trace de chaque vérificateur distinct.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->postJson(
            route('api.public.verify'),
            ['file' => UploadedFile::fake()->createWithContent('contrat.txt', 'contenu')]
        )->assertOk();

        $this->assertSame(2, SignatureVerification::where('is_public_verification', true)->count());
    }

    public function test_an_unknown_document_is_never_logged(): void
    {
        $this->signDocument();

        $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'document inconnu'),
        ])->assertStatus(422);

        $this->assertSame(0, SignatureVerification::where('is_public_verification', true)->count());
    }

    public function test_prune_command_only_removes_old_public_verifications(): void
    {
        $signature = Signature::factory()->create();

        $old = SignatureVerification::factory()->for($signature)->create([
            'is_public_verification' => true,
            'verified_at' => now()->subDays(400),
        ]);
        $recent = SignatureVerification::factory()->for($signature)->create([
            'is_public_verification' => true,
            'verified_at' => now()->subDays(10),
        ]);
        $internal = SignatureVerification::factory()->for($signature)->create([
            'is_public_verification' => false,
            'verified_at' => now()->subDays(400),
        ]);

        $this->artisan('cryptor:prune-verifications')->assertExitCode(0);

        $this->assertDatabaseMissing('signatures_verifications', ['id' => $old->id]);
        $this->assertDatabaseHas('signatures_verifications', ['id' => $recent->id]);
        $this->assertDatabaseHas('signatures_verifications', ['id' => $internal->id]);
    }

    public function test_prune_command_keeps_everything_when_retention_is_unlimited(): void
    {
        $verification = SignatureVerification::factory()->create([
            'is_public_verification' => true,
            'verified_at' => now()->subYears(5),
        ]);

        $this->artisan('cryptor:prune-verifications', ['--days' => 0])->assertExitCode(0);

        $this->assertDatabaseHas('signatures_verifications', ['id' => $verification->id]);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $verification = SignatureVerification::factory()->create([
            'is_public_verification' => true,
            'verified_at' => now()->subDays(400),
        ]);

        $this->artisan('cryptor:prune-verifications', ['--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseHas('signatures_verifications', ['id' => $verification->id]);
    }

    private function verifyByUpload()
    {
        return $this->postJson(route('api.public.verify'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'contenu'),
        ]);
    }

    private function signDocument(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();

        $this->actingAs($user)->post(route('signatures.store'), [
            'file' => UploadedFile::fake()->createWithContent('contrat.txt', 'contenu'),
            'key_pair_id' => $key->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ])->assertRedirect();

        auth()->logout();
    }
}
