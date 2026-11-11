<?php

namespace Tests\Feature;

use App\Models\Signature;
use App\Models\SignatureVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_view_another_users_verification()
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $signature = Signature::factory()->for($owner, 'signer')->create();
        $verification = SignatureVerification::factory()->for($signature)->create([
            'verifier_id' => $owner->id,
        ]);

        $this->actingAs($intruder)
            ->get(route('verifications.show', $verification->id))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('verifications.show', $verification->id))
            ->assertOk();
    }

    public function test_public_verify_endpoint_is_rate_limited()
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson(route('api.public.verify'), ['token' => 'nope']);
        }

        $response = $this->postJson(route('api.public.verify'), ['token' => 'nope']);

        $response->assertStatus(429);
    }
}
