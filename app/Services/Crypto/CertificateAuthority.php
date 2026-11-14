<?php

namespace App\Services\Crypto;

use App\Models\KeyPair;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Autorité de certification interne de CRYPTOR (un seul niveau).
 *
 * - créée automatiquement au premier usage (ECDSA P-384), sans configuration ;
 * - signe les certificats des utilisateurs (extensions `v3_user` de resources/openssl/pki.cnf) ;
 * - valide la chaîne d'un certificat et publie la liste des certificats révoqués.
 *
 * Le certificat de l'AC est public ; sa clé privée est chiffrée avec APP_KEY
 * (la perdre rend l'AC inutilisable, comme pour les clés des utilisateurs).
 */
class CertificateAuthority
{
    public const TRUSTED = 'trusted';

    public const SELF_SIGNED = 'self_signed';

    public const UNTRUSTED = 'untrusted';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const INVALID = 'invalid';

    /** Clé de cache de la liste des séries révoquées (voir revokedSerials()). */
    private const REVOKED_CACHE_KEY = 'cryptor:ca:revoked-serials';

    /**
     * Mémorisation pour la durée de la requête : le certificat de l'AC et sa clé publique sont
     * relus à chaque validation de chaîne, et une page de vérification en fait plusieurs.
     */
    private ?string $certificateCache = null;

    /** @var \OpenSSLAsymmetricKey|false|null */
    private $caPublicKeyCache = null;

    private bool $existsCache = false;

    // ---------------------------------------------------------------- création

    public function exists(): bool
    {
        if ($this->existsCache) {
            return true;
        }

        return $this->existsCache = $this->disk()->exists($this->certPath()) && $this->disk()->exists($this->keyPath());
    }

    /** Crée l'AC si elle n'existe pas encore (verrou : deux requêtes simultanées n'en créent qu'une). */
    public function ensure(): void
    {
        if ($this->exists()) {
            return;
        }

        Cache::lock('cryptor-ca-init', 30)->block(15, function () {
            if (! $this->exists()) {
                $this->generate();
            }
        });
    }

    /** Génère une nouvelle AC. Les certificats émis par l'ancienne ne seront plus de confiance. */
    public function generate(): void
    {
        $options = $this->opensslOptions();

        $key = openssl_pkey_new($options + ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
        $csr = $key ? openssl_csr_new($this->caSubject(), $key, $options + ['digest_alg' => 'sha384']) : false;
        $cert = $csr ? openssl_csr_sign($csr, null, $key, (int) config('cryptor.ca.validity_days'), $options + [
            'digest_alg' => 'sha384',
            'x509_extensions' => 'v3_ca',
        ], 1) : false;

        if (! $cert) {
            throw new RuntimeException('Unable to create the certificate authority: '.$this->opensslErrors());
        }

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, null, $options);

        $this->disk()->put($this->keyPath(), Crypt::encryptString($keyPem));
        $this->disk()->put($this->certPath(), $certPem);

        $this->certificateCache = null;
        $this->caPublicKeyCache = null;
        $this->existsCache = false;
        self::forgetRevocationCache();
    }

    // ------------------------------------------------------------------ accès

    /** Certificat de l'AC (PEM). */
    public function certificate(): string
    {
        if ($this->certificateCache !== null) {
            return $this->certificateCache;
        }

        $this->ensure();

        return $this->certificateCache = (string) $this->disk()->get($this->certPath());
    }

    /** Chemin absolu du certificat de l'AC (utile pour OpenSSL). */
    public function certificatePath(): string
    {
        $this->ensure();

        return $this->disk()->path($this->certPath());
    }

    /** Empreinte SHA-256 du certificat de l'AC, `AB:CD:...`. */
    public function fingerprint(): string
    {
        return implode(':', str_split(strtoupper(openssl_x509_fingerprint($this->certificate(), 'sha256') ?: ''), 2));
    }

    // ---------------------------------------------------------------- émission

    /**
     * Émet un certificat pour une clé privée existante.
     *
     * @param  array<string, string>  $subject  DN complet (voir subjectFor())
     * @return array{certificate: string, serial: string, issuer: string, valid_from: Carbon, valid_to: Carbon}
     */
    public function issue(string $privateKeyPem, array $subject, int $days): array
    {
        $this->ensure();

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        $caKey = openssl_pkey_get_private($this->caPrivateKey());

        if (! $privateKey || ! $caKey) {
            throw new RuntimeException('Invalid private key provided.');
        }

        // Un certificat ne peut pas durer plus longtemps que l'AC qui le signe.
        $days = max(1, min($days, (int) config('cryptor.certificate.max_days'), $this->remainingDays()));
        $options = $this->opensslOptions();

        $csr = openssl_csr_new($subject, $privateKey, $options + ['digest_alg' => 'sha256']);
        $x509 = $csr ? openssl_csr_sign($csr, $this->certificate(), $caKey, $days, $options + [
            'digest_alg' => 'sha256',
            'x509_extensions' => 'v3_user',
        ], random_int(1, PHP_INT_MAX >> 1)) : false;

        if (! $x509) {
            throw new RuntimeException('Failed to issue certificate: '.$this->opensslErrors());
        }

        openssl_x509_export($x509, $pem);

        return [
            'certificate' => $pem,
            'serial' => $this->serialOf($pem),
            'issuer' => config('cryptor.ca.name'),
            'valid_from' => now(),
            'valid_to' => now()->addDays($days),
        ];
    }

    /** DN par défaut d'un certificat utilisateur. */
    public function subjectFor(string $commonName, ?string $email = null): array
    {
        return array_filter([
            'countryName' => config('cryptor.ca.country'),
            'organizationName' => config('cryptor.ca.organization'),
            'organizationalUnitName' => 'PKI',
            'commonName' => $commonName,
            'emailAddress' => $email,
        ]);
    }

    // -------------------------------------------------------------- validation

    /**
     * Valide la chaîne d'un certificat contre l'AC interne.
     *
     * @return array{status: string, issuer: ?string, serial: ?string}
     *
     * `status` : trusted | self_signed | untrusted | expired | revoked | invalid
     */
    public function validateChain(string $certificatePem): array
    {
        $parsed = @openssl_x509_parse($certificatePem);

        if (! $parsed) {
            return ['status' => self::INVALID, 'issuer' => null, 'serial' => null];
        }

        $serial = $parsed['serialNumberHex'] ?? null;
        $issuer = $parsed['issuer']['CN'] ?? null;
        $result = fn (string $status) => ['status' => $status, 'issuer' => $issuer, 'serial' => $serial];

        if (($parsed['issuer'] ?? []) === ($parsed['subject'] ?? [])) {
            return $result(self::SELF_SIGNED);
        }

        if (! $this->isIssuedByCa($certificatePem)) {
            return $result(self::UNTRUSTED);
        }

        $now = now()->timestamp;
        if ($now < ($parsed['validFrom_time_t'] ?? 0) || $now > ($parsed['validTo_time_t'] ?? 0)) {
            return $result(self::EXPIRED);
        }

        if ($serial !== null && array_key_exists($serial, $this->revokedSerials())) {
            return $result(self::REVOKED);
        }

        return $result(self::TRUSTED);
    }

    /** Ce certificat porte-t-il bien la signature de notre AC ? */
    public function isIssuedByCa(string $certificatePem): bool
    {
        if (! $this->exists()) {
            return false;
        }

        // La clé publique de l'AC est la même pour tout le processus : on ne relit pas le
        // fichier et on ne la reparse pas à chaque certificat (revokedSerials en teste N).
        if ($this->caPublicKeyCache === null) {
            $this->caPublicKeyCache = openssl_pkey_get_public($this->certificate());
        }

        return $this->caPublicKeyCache && @openssl_x509_verify($certificatePem, $this->caPublicKeyCache) === 1;
    }

    // -------------------------------------------------------------- révocation

    /**
     * Numéros de série (hex) des certificats de l'AC dont la clé est révoquée.
     *
     * @return array<string, Carbon|null> serial => date de révocation
     */
    public function revokedSerials(): array
    {
        // Sans cache, chaque vérification (y compris la route publique /pki/revocations.json)
        // relisait toutes les clés révoquées de la plateforme et faisait une vérification
        // OpenSSL par clé : coût linéaire sur un point d'entrée non authentifié.
        $serials = Cache::remember(self::REVOKED_CACHE_KEY, now()->addMinutes(10), function () {
            $serials = [];

            KeyPair::query()
                ->where('is_revoked', true)
                ->whereNotNull('certificate_data')
                ->select(['id', 'certificate_data', 'revoked_at'])
                ->cursor()
                ->each(function (KeyPair $key) use (&$serials) {
                    if ($this->isIssuedByCa($key->certificate_data) && ($serial = $this->serialOf($key->certificate_data))) {
                        $serials[$serial] = $key->revoked_at?->toIso8601String();
                    }
                });

            return $serials;
        });

        return array_map(fn (?string $date) => $date ? Carbon::parse($date) : null, $serials);
    }

    /** À appeler dès qu'une clé est révoquée (ou dé-révoquée) : la liste est en cache. */
    public static function forgetRevocationCache(): void
    {
        Cache::forget(self::REVOKED_CACHE_KEY);
    }

    /**
     * Liste de révocation publique, signée par l'AC (ECDSA-SHA384 sur le JSON de `payload`).
     *
     * @return array{payload: array, signature: string}
     */
    public function revocationList(): array
    {
        $this->ensure();

        $revoked = [];
        foreach ($this->revokedSerials() as $serial => $revokedAt) {
            $revoked[] = ['serial' => $serial, 'revoked_at' => $revokedAt?->toIso8601String()];
        }

        $payload = [
            'issuer' => config('cryptor.ca.name'),
            'generated_at' => now()->toIso8601String(),
            'revoked' => $revoked,
        ];

        openssl_sign(json_encode($payload, JSON_UNESCAPED_SLASHES), $signature, $this->caPrivateKey(), OPENSSL_ALGO_SHA384);

        return ['payload' => $payload, 'signature' => base64_encode($signature)];
    }

    // ------------------------------------------------------------------ utilitaires

    public function serialOf(string $certificatePem): ?string
    {
        $parsed = @openssl_x509_parse($certificatePem);

        return $parsed['serialNumberHex'] ?? null;
    }

    /** Options OpenSSL communes : fichier de configuration fourni par le projet. */
    public function opensslOptions(): array
    {
        return ['config' => resource_path('openssl/pki.cnf')];
    }

    private function remainingDays(): int
    {
        $parsed = openssl_x509_parse($this->certificate());

        return max(1, (int) floor((($parsed['validTo_time_t'] ?? now()->timestamp) - now()->timestamp) / 86400) - 1);
    }

    private function caSubject(): array
    {
        return array_filter([
            'countryName' => config('cryptor.ca.country'),
            'organizationName' => config('cryptor.ca.organization'),
            'commonName' => config('cryptor.ca.name'),
        ]);
    }

    private function caPrivateKey(): string
    {
        $this->ensure();

        return Crypt::decryptString((string) $this->disk()->get($this->keyPath()));
    }

    private function opensslErrors(): string
    {
        $messages = [];
        while (($e = openssl_error_string()) !== false) {
            $messages[] = $e;
        }

        return implode(' | ', $messages);
    }

    private function disk()
    {
        return Storage::disk('local');
    }

    private function certPath(): string
    {
        return trim(config('cryptor.ca.directory'), '/').'/ca.crt';
    }

    private function keyPath(): string
    {
        return trim(config('cryptor.ca.directory'), '/').'/ca.key';
    }
}
