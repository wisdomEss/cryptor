<?php

namespace Database\Factories;

use App\Models\FileSigned;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FileSigned>
 */
class FileSignedFactory extends Factory
{
    public function definition(): array
    {
        $hash = hash('sha256', fake()->unique()->sentence());

        return [
            'file_name' => fake()->uuid().'.pdf',
            'original_file_name' => fake()->word().'.pdf',
            'file_path' => 'signatures/factory/'.fake()->uuid().'.pdf',
            'file_hash' => $hash,
            'original_hash' => $hash,
            'hash_algorithm' => 'sha256',
            'uploader_id' => User::factory(),
            'file_size' => fake()->numberBetween(1000, 100000),
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'is_encrypted' => false,
            'status' => 'pending_signature',
            'total_signatures_needed' => 1,
            'signatures_count' => 0,
        ];
    }
}
