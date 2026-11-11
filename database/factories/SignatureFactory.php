<?php

namespace Database\Factories;

use App\Models\FileSigned;
use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Signature>
 */
class SignatureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'file_id' => FileSigned::factory(),
            'key_pair_id' => KeyPair::factory(),
            'signer_id' => User::factory(),
            'signature_value' => json_encode(['signature' => base64_encode('fake-signature')]),
            'signature_format' => 'cms',
            'algorithm_used' => 'rsa',
            'is_valid' => true,
            'validation_status' => 'pending',
            'signed_at' => now(),
            'nonce_value' => bin2hex(random_bytes(16)),
        ];
    }
}
