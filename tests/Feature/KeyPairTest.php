<?php

namespace Tests\Feature;

use App\Models\KeyPair;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeyPairTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_generate_an_rsa_key_pair()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('keys.store'), [
            'key_name' => 'My RSA key',
            'algorithm' => 'rsa',
            'key_size' => 2048,
            'set_as_default' => true,
        ]);

        $response->assertRedirect(route('keys.index'));

        $this->assertDatabaseHas('key_pairs', [
            'user_id' => $user->id,
            'key_name' => 'My RSA key',
            'algorithm_used' => 'rsa',
            'is_default' => true,
            'is_active' => true,
            'is_revoked' => false,
        ]);
    }

    public function test_private_key_is_stored_encrypted_at_rest()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('keys.store'), [
            'key_name' => 'Encrypted key',
            'algorithm' => 'rsa',
            'key_size' => 2048,
        ]);

        $key = KeyPair::where('user_id', $user->id)->firstOrFail();

        // La valeur brute en base ne doit jamais contenir le PEM en clair.
        $raw = $key->getRawOriginal('private_key');
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $raw);

        // Mais via le modèle (cast 'encrypted'), on récupère la clé en clair.
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $key->private_key);
    }

    public function test_revoked_route_is_not_captured_by_show_route()
    {
        $user = User::factory()->create();
        KeyPair::factory()->for($user)->create(['is_revoked' => true, 'revoked_at' => now()]);

        $response = $this->actingAs($user)->get(route('keys.revoked'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('keys/Revoke'));
    }

    public function test_revoking_a_non_default_key_persists_all_fields()
    {
        $user = User::factory()->create();
        $default = KeyPair::factory()->for($user)->create(['is_default' => true]);
        $other = KeyPair::factory()->for($user)->create(['is_default' => false]);

        $response = $this->actingAs($user)->post(route('keys.revoke', $other->id), [
            'reason' => 'Compromised',
        ]);

        $response->assertRedirect(route('keys.show', $other->id));

        $this->assertDatabaseHas('key_pairs', [
            'id' => $other->id,
            'is_revoked' => true,
            'is_active' => false,
            'revocation_reason' => 'Compromised',
        ]);

        $other->refresh();
        $this->assertNotNull($other->revoked_at);
    }

    public function test_user_can_edit_a_key()
    {
        $user = User::factory()->create();
        $key = KeyPair::factory()->for($user)->create(['key_name' => 'Old name']);

        $this->actingAs($user)->get(route('keys.edit', $key->id))->assertOk();

        $response = $this->actingAs($user)->put(route('keys.update', $key->id), [
            'key_name' => 'New name',
        ]);

        $response->assertRedirect(route('keys.show', $key->id));
        $this->assertDatabaseHas('key_pairs', ['id' => $key->id, 'key_name' => 'New name']);
    }
}
