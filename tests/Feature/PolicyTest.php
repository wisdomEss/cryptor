<?php

namespace Tests\Feature;

use App\Models\FileSigned;
use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Les Policies répondent 404 (et non 403) aux utilisateurs étrangers :
 * l'existence d'une ressource d'autrui ne doit pas être révélée.
 */
class PolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $intruder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();
    }

    public function test_intruder_cannot_access_someone_elses_key(): void
    {
        $key = KeyPair::factory()->for($this->owner)->create();

        $this->actingAs($this->intruder);

        $this->get(route('keys.show', $key->id))->assertNotFound();
        $this->get(route('keys.edit', $key->id))->assertNotFound();
        $this->get(route('keys.download', $key->id))->assertNotFound();
        $this->get(route('keys.export.pem', $key->id))->assertNotFound();
        $this->post(route('keys.revoke', $key->id), ['revocation_reason' => 'x'])->assertNotFound();
        $this->post(route('keys.set-default', $key->id))->assertNotFound();
        $this->post(route('keys.deactivate', $key->id))->assertNotFound();
        $this->delete(route('keys.destroy', $key->id))->assertNotFound();

        $this->postJson(route('keys.export.backup', $key->id), [
            'password' => 'password',
            'passphrase' => 'une phrase secrete longue',
            'passphrase_confirmation' => 'une phrase secrete longue',
        ])->assertNotFound();

        $this->assertDatabaseHas('key_pairs', ['id' => $key->id, 'is_revoked' => false, 'is_active' => true]);
    }

    public function test_owner_can_access_their_key(): void
    {
        $key = KeyPair::factory()->for($this->owner)->create();

        $this->actingAs($this->owner)->get(route('keys.show', $key->id))->assertOk();
    }

    public function test_intruder_cannot_touch_someone_elses_signature(): void
    {
        $signature = Signature::factory()->for($this->owner, 'signer')->create();

        $this->actingAs($this->intruder);

        $this->get(route('signatures.show', $signature->id))->assertNotFound();
        $this->post(route('signatures.revoke', $signature->id), ['revocation_reason' => 'x'])->assertNotFound();
        $this->patch(route('signatures.validate', $signature->id))->assertNotFound();
        $this->delete(route('signatures.destroy', $signature->id))->assertNotFound();

        $this->assertDatabaseHas('signatures', ['id' => $signature->id]);
    }

    public function test_file_download_is_limited_to_uploader_and_signer(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('signatures/1/doc.txt', 'contenu');

        $signature = Signature::factory()->for($this->owner, 'signer')->create();
        $file = $signature->file;
        $file->update(['uploader_id' => $this->owner->id]);
        $file->forceFill(['file_path' => 'signatures/1/doc.txt'])->save();

        $this->actingAs($this->intruder)->get(route('files.download', $file->id))->assertNotFound();
        $this->actingAs($this->owner)->get(route('files.download', $file->id))->assertOk();
    }

    public function test_recent_signatures_on_verification_page_are_limited_to_the_user(): void
    {
        $mine = Signature::factory()->for($this->owner, 'signer')->create();
        $mine->file->update(['uploader_id' => $this->owner->id, 'original_file_name' => 'secret-owner.pdf']);

        $this->actingAs($this->intruder)
            ->get(route('verifications.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('recentSignatures', fn ($items) => count($items) === 0));

        $this->actingAs($this->owner)
            ->get(route('verifications.create'))
            ->assertInertia(fn ($page) => $page->where('recentSignatures.0.file_name', 'secret-owner.pdf'));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $key = KeyPair::factory()->for($this->owner)->create();

        $this->get(route('keys.show', $key->id))->assertRedirect(route('login'));
        $this->get(route('files.download', FileSigned::factory()->create()->id))->assertRedirect(route('login'));
    }
}
