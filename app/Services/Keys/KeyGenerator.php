<?php

namespace App\Services\Keys;

use App\Exceptions\UserFacingException;
use RuntimeException;

/**
 * Génération de paires de clés : RSA (2048/4096 bits) ou ECDSA (courbe prime256v1 / P-256).
 */
final class KeyGenerator
{
    public const RSA_SIZES = [2048, 4096];

    /**
     * @return array{public_key: string, private_key: string, key_size: int}
     */
    public function generate(string $algorithm, int $keySize = 2048): array
    {
        $config = match ($algorithm) {
            'rsa' => in_array($keySize, self::RSA_SIZES, true)
                ? ['digest_alg' => 'sha256', 'private_key_bits' => $keySize, 'private_key_type' => OPENSSL_KEYTYPE_RSA]
                : throw new UserFacingException('Unsupported RSA key size.'),
            'ecdsa' => ['digest_alg' => 'sha256', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'],
            default => throw new UserFacingException('Unsupported algorithm.'),
        };

        $resource = openssl_pkey_new($config);

        if (! $resource || ! openssl_pkey_export($resource, $privateKey)) {
            throw new RuntimeException('Key generation failed: '.openssl_error_string());
        }

        return [
            'public_key' => openssl_pkey_get_details($resource)['key'],
            'private_key' => $privateKey,
            'key_size' => $algorithm === 'rsa' ? $keySize : 256,
        ];
    }
}
