<?php

namespace App\Services\Keys;

use App\Exceptions\UserFacingException;

/**
 * Convertit une clé publique PEM au format `authorized_keys` d'OpenSSH (RSA et ECDSA P-256).
 */
final class SshKeyExporter
{
    public function export(string $publicKeyPem, string $comment): string
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        $details = $key ? openssl_pkey_get_details($key) : null;

        $line = match (true) {
            $details === null => throw new UserFacingException('Invalid public key.'),
            $details['type'] === OPENSSL_KEYTYPE_RSA => $this->rsa($details['rsa']),
            $details['type'] === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? null) === 'prime256v1' => $this->ecdsa($details['ec']),
            default => throw new UserFacingException('SSH export is only available for RSA and ECDSA P-256 keys.'),
        };

        // Le commentaire finit sur une ligne : aucun saut de ligne ni caractère de contrôle.
        $comment = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $comment));

        return $line.' '.($comment !== '' ? $comment : 'key')."\n";
    }

    private function rsa(array $rsa): string
    {
        $blob = $this->string('ssh-rsa').$this->mpint($rsa['e']).$this->mpint($rsa['n']);

        return 'ssh-rsa '.base64_encode($blob);
    }

    private function ecdsa(array $ec): string
    {
        // Point non compressé : 0x04 || X || Y, chaque coordonnée sur 32 octets.
        $point = "\x04".str_pad($ec['x'], 32, "\x00", STR_PAD_LEFT).str_pad($ec['y'], 32, "\x00", STR_PAD_LEFT);
        $blob = $this->string('ecdsa-sha2-nistp256').$this->string('nistp256').$this->string($point);

        return 'ecdsa-sha2-nistp256 '.base64_encode($blob);
    }

    private function string(string $value): string
    {
        return pack('N', strlen($value)).$value;
    }

    /** Entier multi-précision SSH : positif, donc préfixé de 0x00 si le bit de poids fort est à 1. */
    private function mpint(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        return $this->string((ord($bytes[0] ?? "\x00") & 0x80) ? "\x00".$bytes : $bytes);
    }
}
