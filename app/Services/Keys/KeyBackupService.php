<?php

namespace App\Services\Keys;

use App\Exceptions\UserFacingException;
use App\Models\KeyPair;
use App\Models\User;
use App\Services\Crypto\CertificateAuthority;
use App\Support\ClientContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Sauvegarde et restauration d'une clé au format PKCS#12 (.p12), protégé par une phrase secrète.
 * Standard : le fichier s'ouvre aussi dans un navigateur, un système ou `openssl pkcs12`.
 */
class KeyBackupService
{
    public function __construct(private readonly CertificateAuthority $authority) {}

    /** Contenu du .p12 : clé privée + certificat + certificat de l'AC (si c'est elle qui l'a émis). */
    public function export(KeyPair $key, string $passphrase): string
    {
        if (! $key->certificate_data) {
            throw new UserFacingException('This key has no certificate to export.');
        }

        $ok = @openssl_pkcs12_export($key->certificate_data, $p12, $key->private_key, $passphrase, [
            'extracerts' => $this->authority->isIssuedByCa($key->certificate_data) ? [$this->authority->certificate()] : [],
            'friendly_name' => $key->key_name,
        ]);

        if (! $ok) {
            throw new UserFacingException('The backup could not be generated.');
        }

        return $p12;
    }

    /**
     * Crée une clé à partir d'un .p12 (sauvegarde CRYPTOR ou clé externe).
     * Refuse : mauvaise phrase secrète, clé non assortie au certificat, clé trop faible ou
     * de type non géré, certificat expiré, clé déjà possédée.
     */
    public function import(User $user, string $p12, string $passphrase, string $keyName, ClientContext $client): KeyPair
    {
        $bundle = [];
        $read = @openssl_pkcs12_read($p12, $bundle, $passphrase);
        while (openssl_error_string() !== false); // vide la file d'erreurs OpenSSL

        if (! $read || empty($bundle['pkey']) || empty($bundle['cert'])) {
            throw new UserFacingException('Unable to read this PKCS#12 file: wrong passphrase or unsupported format.', 'passphrase');
        }

        $private = openssl_pkey_get_private($bundle['pkey']);
        $details = $private ? openssl_pkey_get_details($private) : null;

        if (! $details || ! openssl_x509_check_private_key($bundle['cert'], $bundle['pkey'])) {
            throw new UserFacingException('The private key does not match the certificate.', 'file');
        }

        if ($details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 2048) {
            $algorithm = 'rsa';
        } elseif ($details['type'] === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? null) === 'prime256v1') {
            $algorithm = 'ecdsa';
        } else {
            throw new UserFacingException('Unsupported key (RSA 2048+ or ECDSA P-256 only).', 'file');
        }

        $cert = openssl_x509_parse($bundle['cert']);

        if (now()->timestamp > $cert['validTo_time_t']) {
            throw new UserFacingException('This certificate has expired.', 'file');
        }

        $normalize = fn (string $pem) => preg_replace('/\s+/', '', $pem);
        $duplicate = KeyPair::where('user_id', $user->id)->pluck('public_key')
            ->contains(fn ($existing) => $normalize($existing) === $normalize($details['key']));

        if ($duplicate) {
            throw new UserFacingException('You already have this key.', 'file');
        }

        openssl_pkey_export($private, $privatePem);

        return KeyPair::create([
            'user_id' => $user->id,
            'key_name' => $keyName,
            'public_key' => $details['key'],
            'private_key' => $privatePem,
            'algorithm_used' => $algorithm,
            'key_size' => $details['bits'],
            'key_identifier' => Str::uuid()->toString(),
            'is_default' => false,
            'is_active' => true,
            'is_revoked' => false,
            'valid_from' => Carbon::createFromTimestamp($cert['validFrom_time_t']),
            'valid_to' => Carbon::createFromTimestamp($cert['validTo_time_t']),
            'certificate_data' => $bundle['cert'],
            'certificate_issuer' => $cert['issuer']['CN'] ?? null,
            'created_by_ip' => $client->ip,
            'created_by_user_agent' => $client->userAgent,
        ]);
    }
}
