<?php

namespace Database\Factories;

use App\Models\KeyPair;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KeyPair>
 */
class KeyPairFactory extends Factory
{
    public function definition(): array
    {
        $resource = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($resource, $privateKey);
        $publicKey = openssl_pkey_get_details($resource)['key'];

        return [
            'user_id' => User::factory(),
            'public_key' => $publicKey,
            'private_key' => $privateKey,
            'key_name' => fake()->words(2, true),
            'algorithm_used' => 'rsa',
            'key_size' => 2048,
            'key_identifier' => Str::uuid()->toString(),
            'is_default' => false,
            'is_active' => true,
            'is_revoked' => false,
            'valid_from' => now(),
            'valid_to' => null,
        ];
    }
}
