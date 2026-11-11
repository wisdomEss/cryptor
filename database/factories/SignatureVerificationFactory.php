<?php

namespace Database\Factories;

use App\Models\Signature;
use App\Models\SignatureVerification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignatureVerification>
 */
class SignatureVerificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'signature_id' => Signature::factory(),
            'verifier_id' => null,
            'verified_hash' => fake()->sha256(),
            'expected_hash' => fake()->sha256(),
            'result' => true,
            'verification_type' => 'hash',
            'verifier_ip' => '127.0.0.1',
            'is_public_verification' => false,
            'verified_at' => now(),
        ];
    }
}
