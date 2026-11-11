<?php

namespace Tests\Feature;

use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_sign_a_document_and_verify_it()
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();

        $file = UploadedFile::fake()->create('contract.txt', 10, 'text/plain');

        $signResponse = $this->actingAs($user)->post(route('signatures.store'), [
            'file' => $file,
            'key_pair_id' => $key->id,
            'signature_format' => 'cms',
            'expires_in_days' => 0,
        ]);

        $signature = Signature::firstOrFail();
        $signResponse->assertRedirect(route('signatures.show', $signature->id));

        // Le fichier détaché doit être une vraie signature binaire, pas du JSON encodé en base64.
        $this->assertNotNull($signature->signature_file_path);
        Storage::disk('local')->assertExists($signature->signature_file_path);

        // Vérification via le flux "document interne"
        $verifyResponse = $this->actingAs($user)->post(route('verifications.store'), [
            'file' => UploadedFile::fake()->createWithContent('contract.txt', file_get_contents($file->getRealPath())),
        ]);

        $verifyResponse->assertOk();
    }

    public function test_pending_signatures_route_is_not_captured_by_show_route()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('signatures.pending'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('signatures/Index'));
    }

    public function test_validate_route_reuses_update_logic()
    {
        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create();
        $signature = Signature::factory()->for($user, 'signer')->create([
            'key_pair_id' => $key->id,
            'validation_status' => 'pending',
        ]);

        $response = $this->actingAs($user)->patch(route('signatures.validate', $signature->id), [
            'validation_notes' => 'Looks good',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('signatures', [
            'id' => $signature->id,
            'validation_status' => 'valid',
            'is_valid' => true,
        ]);
    }

    public function test_user_can_delete_a_signature()
    {
        $user = User::factory()->create();
        $signature = Signature::factory()->for($user, 'signer')->create();

        $response = $this->actingAs($user)->delete(route('signatures.destroy', $signature->id));

        $response->assertRedirect(route('signatures.index'));
        $this->assertDatabaseMissing('signatures', ['id' => $signature->id]);
    }
}
