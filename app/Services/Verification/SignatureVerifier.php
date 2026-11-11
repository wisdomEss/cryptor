<?php

namespace App\Services\Verification;

use App\Exceptions\UserFacingException;
use App\Models\FileSigned;
use App\Models\Signature;
use App\Models\SignatureVerification;
use App\Services\Crypto\CertificateAuthority;
use App\Services\Crypto\CmsService;
use App\Services\Crypto\TimestampAuthority;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Logique de vérification des signatures, partagée par la vérification
 * authentifiée (VerificationController) et publique (PublicSignaturesController).
 *
 * Le service ne produit pas de réponse HTTP : il enregistre la vérification et
 * renvoie des données brutes ; chaque contrôleur les met en forme.
 */
class SignatureVerifier
{
    public function __construct(
        private readonly CmsService $cms = new CmsService,
        private readonly CertificateAuthority $authority = new CertificateAuthority,
        private readonly TimestampAuthority $timestamps = new TimestampAuthority,
    ) {}

    /** Messages utilisateur : l'UI connectée est en anglais, la page publique en français. */
    private const MESSAGES = [
        'en' => [
            'authentic' => 'The document is authentic and the signature is valid.',
            'modified_and_invalid' => 'The document has been modified and the signature is invalid.',
            'modified' => 'The document has been modified since it was signed.',
            'invalid' => 'The signature is invalid or has been tampered with.',
            'failed' => 'Verification failed.',
            'no_signature' => 'No signature found for this document.',
            'unknown_document' => 'This document is not recognised: it was not signed on this platform, or it has been modified since it was signed. To verify an external signature, attach its signature file (.p7s).',
            'invalid_format' => 'Invalid signature format.',
            'no_public_key' => 'No public key found in the signature.',
            'external_valid' => 'External signature is valid',
            'external_invalid' => 'External signature is invalid',
        ],
        'fr' => [
            'authentic' => 'Le document est authentique et la signature est valide.',
            'modified_and_invalid' => 'Le document a été modifié et la signature est invalide.',
            'modified' => 'Le document a été modifié depuis sa signature.',
            'invalid' => 'La signature est invalide ou a été altérée.',
            'failed' => 'La vérification a échoué.',
            'no_signature' => 'Aucune signature trouvée pour ce document.',
            'unknown_document' => 'Ce document n\'est pas reconnu : il n\'a pas été signé sur cette plateforme, ou il a été modifié depuis sa signature. Pour vérifier une signature externe, joignez son fichier (.p7s).',
            'invalid_format' => 'Format de signature invalide.',
            'no_public_key' => 'Aucune clé publique trouvée dans la signature.',
            'external_valid' => 'La signature externe est valide',
            'external_invalid' => 'La signature externe est invalide',
        ],
    ];

    public function text(string $key, string $locale = 'en'): string
    {
        return self::MESSAGES[$locale][$key] ?? self::MESSAGES['en'][$key] ?? $key;
    }

    /** Message de synthèse d'une vérification de document interne. */
    public function message(bool $success, bool $documentIntegrity, bool $signatureValid, string $locale = 'en'): string
    {
        return $this->text(match (true) {
            $success => 'authentic',
            ! $documentIntegrity && ! $signatureValid => 'modified_and_invalid',
            ! $documentIntegrity => 'modified',
            ! $signatureValid => 'invalid',
            default => 'failed',
        }, $locale);
    }

    /** Retrouve un document déjà signé dans le système à partir de son empreinte SHA-256. */
    public function findSignedFile(string $sha256): ?FileSigned
    {
        return FileSigned::where('file_hash', $sha256)->first();
    }

    /**
     * Vérifie un document connu du système et journalise la vérification.
     *
     * @return array{verification: SignatureVerification, signature: Signature, success: bool, document_integrity: bool, signature_valid: bool, key_valid: bool, certificate_trust: string, timestamp: array}
     */
    public function verifyInternal(
        FileSigned $fileSigned,
        UploadedFile $file,
        ?UploadedFile $signatureFile,
        Request $request,
        float $startTime,
        ?int $verifierId,
        bool $isPublic,
        bool $withToken,
        string $locale = 'en',
    ): array {
        $signature = $fileSigned->signatures()
            ->with(['keyPair', 'signer'])
            ->latest()
            ->first();

        if (! $signature) {
            throw new UserFacingException($this->text('no_signature', $locale));
        }

        $currentHash = hash_file('sha256', $file->getRealPath());
        $documentIntegrity = hash_equals($fileSigned->file_hash, $currentHash);
        $signatureValid = $this->verifySignature($signature, file_get_contents($file->getRealPath()));
        $keyValid = (bool) ($signature->keyPair && $signature->keyPair->isValid());
        $success = $documentIntegrity && $signatureValid && $keyValid;

        // Information de confiance du certificat (n'influence pas `success` : les clés
        // créées avant l'AC interne ont un certificat auto-signé mais restent utilisables).
        $certificateTrust = $this->certificateTrust($signature->keyPair?->certificate_data);
        $timestamp = $this->timestampStatus($signature);

        $verification = SignatureVerification::create([
            'signature_id' => $signature->id,
            'verifier_id' => $verifierId,
            'verified_hash' => $currentHash,
            'expected_hash' => $fileSigned->file_hash,
            'result' => $success,
            'verification_type' => $signatureFile ? 'signature' : 'hash',
            'verification_details' => [
                'document_integrity' => $documentIntegrity,
                'signature_valid' => $signatureValid,
                'key_valid' => $keyValid,
                'certificate_trust' => $certificateTrust,
                'timestamp' => $timestamp,
                'format' => $signature->signature_format,
                'algorithm' => $signature->algorithm_used,
            ],
            'signature_timestamp' => $signature->signed_at,
            'document_timestamp' => $fileSigned->created_at,
            'verifier_ip' => $request->ip(),
            'verifier_user_agent' => $request->userAgent(),
            'verification_duration_ms' => $this->elapsedMs($startTime),
            'is_public_verification' => $isPublic,
        ]);

        if ($withToken) {
            $verification->generateVerificationToken();
        }

        return [
            'verification' => $verification,
            'signature' => $signature,
            'success' => $success,
            'document_integrity' => $documentIntegrity,
            'signature_valid' => $signatureValid,
            'key_valid' => $keyValid,
            'certificate_trust' => $certificateTrust,
            'timestamp' => $timestamp,
        ];
    }

    /**
     * Vérifie un document « externe » (inconnu du système) à partir d'un fichier de signature.
     *
     * @return array{verification: SignatureVerification, success: bool, document_integrity: bool, signature_valid: bool, data: array, public_key: string, certificate_trust: string}
     */
    public function verifyExternal(
        UploadedFile $file,
        ?UploadedFile $signatureFile,
        Request $request,
        float $startTime,
        ?int $verifierId,
        bool $isPublic,
        bool $withToken,
        string $locale = 'en',
    ): array {
        if (! $signatureFile) {
            throw new UserFacingException($this->text('unknown_document', $locale));
        }

        $data = $this->parseExternalSignature((string) file_get_contents($signatureFile->getRealPath()));

        if (! $data || ! isset($data['signature'])) {
            throw new UserFacingException($this->text('invalid_format', $locale));
        }

        if (isset($data['cms'])) {
            // CMS : la signature est vérifiée avec le certificat qu'elle embarque.
            $cmsResult = $this->cms->verify($file->getRealPath(), $data['cms']);
            $publicKey = isset($cmsResult['signers'][0]) ? $this->cms->publicKeyFromCertificate($cmsResult['signers'][0]) : null;
            $signatureValid = $cmsResult['valid'];
            $data['signer'] ??= $this->certificateCommonName($cmsResult['signers'][0] ?? null);
        } else {
            $cmsResult = null;
            $publicKey = $this->externalPublicKey($data);

            if ($publicKey) {
                $signatureValid = $this->opensslVerify(
                    file_get_contents($file->getRealPath()),
                    base64_decode($data['signature'], true) ?: '',
                    $publicKey
                );
            }
        }

        if (! $publicKey) {
            throw new UserFacingException($this->text(isset($data['cms']) ? 'invalid_format' : 'no_public_key', $locale));
        }

        $certificateTrust = $this->certificateTrust($cmsResult['signers'][0] ?? null);

        $fileHash = hash_file('sha256', $file->getRealPath());
        $documentIntegrity = isset($data['file_hash']) ? hash_equals((string) $data['file_hash'], $fileHash) : true;
        $success = $signatureValid && $documentIntegrity;

        $verification = SignatureVerification::create([
            'signature_id' => null,
            'verifier_id' => $verifierId,
            'verified_hash' => $fileHash,
            'expected_hash' => $data['file_hash'] ?? null,
            'result' => $success,
            'verification_type' => 'external',
            'verification_details' => [
                'signature_valid' => $signatureValid,
                'document_integrity' => $documentIntegrity,
                'format' => $data['format'] ?? 'unknown',
                'certificate_trust' => $certificateTrust,
                'external' => true,
            ],
            'signature_timestamp' => $data['timestamp'] ?? null,
            'verifier_ip' => $request->ip(),
            'verifier_user_agent' => $request->userAgent(),
            'verification_duration_ms' => $this->elapsedMs($startTime),
            'is_public_verification' => $isPublic,
        ]);

        if ($withToken) {
            $verification->generateVerificationToken();
        }

        return [
            'verification' => $verification,
            'success' => $success,
            'document_integrity' => $documentIntegrity,
            'signature_valid' => $signatureValid,
            'data' => $data,
            'public_key' => $publicKey,
            'certificate_trust' => $certificateTrust,
        ];
    }

    /**
     * Vérifie la valeur d'une signature enregistrée contre le contenu brut du document.
     *
     * Formats pris en charge : CMS détaché standard (PEM) ; en lecture seule, les
     * anciennes structures JSON (`pdf`/`PAdES`/`cms`/`CAdES`) et les signatures base64 brutes.
     * Toute erreur renvoie `false` (jamais d'exception).
     */
    public function verifySignature(Signature $signature, string $content): bool
    {
        try {
            $publicKey = $signature->keyPair?->public_key;

            if (! $publicKey) {
                return false;
            }

            $value = (string) $signature->signature_value;

            // Signature CMS standard (RFC 5652) : le certificat embarqué doit être celui de la clé enregistrée.
            if ($this->cms->looksLikeCms($value)) {
                return $this->verifyCmsAgainstKey($value, $content, $publicKey);
            }

            // Anciennes signatures : structure JSON maison ou signature base64 brute.
            $json = json_decode($value, true);

            if (is_array($json) && isset($json['signature'])) {
                $digest = $json['signedAttributes']['messageDigest'] ?? null;

                if ($digest !== null && ! hash_equals(base64_decode($digest, true) ?: '', hash('sha256', $content, true))) {
                    return false;
                }

                return $this->opensslVerify($content, base64_decode($json['signature'], true) ?: '', $publicKey);
            }

            return $this->opensslVerify($content, base64_decode((string) $signature->signature_value, true) ?: '', $publicKey);
        } catch (\Throwable $e) {
            Log::error('Signature verification error: '.$e->getMessage());

            return false;
        }
    }

    /** Lit un fichier de signature externe : CMS/PKCS#7 (PEM ou DER), JSON ou signature binaire brute. */
    public function parseExternalSignature(string $content): ?array
    {
        $data = json_decode($content, true);

        if (is_array($data) && isset($data['signature'])) {
            return $data;
        }

        if ($this->cms->looksLikeCms($content)) {
            return [
                'format' => 'CMS',
                'signature' => base64_encode($content),
                'cms' => $content,
            ];
        }

        if ($this->isBinary($content)) {
            return [
                'format' => 'raw',
                'signature' => base64_encode($content),
            ];
        }

        return null;
    }

    /**
     * État de l'horodatage d'une signature.
     *
     * status : trusted (RFC 3161, TSA reconnue) | untrusted_tsa (RFC 3161 valide, TSA non reconnue)
     *          | invalid (jeton RFC 3161 qui ne correspond plus) | internal (heure du serveur) | none
     *
     * @return array{status: string, time: ?string}
     */
    public function timestampStatus(Signature $signature): array
    {
        $token = json_decode((string) $signature->timestamp_token, true);

        if (! is_array($token)) {
            return ['status' => 'none', 'time' => null];
        }

        if (($token['type'] ?? 'internal') !== 'rfc3161') {
            return ['status' => 'internal', 'time' => $token['time'] ?? null];
        }

        try {
            $der = $this->cms->pemToDer((string) $signature->signature_value);
            $result = $this->timestamps->verifyToken(base64_decode((string) ($token['token'] ?? ''), true) ?: '', hash('sha256', $der, true));
        } catch (\Throwable) {
            return ['status' => 'invalid', 'time' => null];
        }

        if (! $result['valid']) {
            return ['status' => 'invalid', 'time' => null];
        }

        return ['status' => $result['trusted'] ? 'trusted' : 'untrusted_tsa', 'time' => $result['gen_time']];
    }

    /**
     * Statut de confiance d'un certificat vis-à-vis de l'AC interne :
     * trusted | self_signed | untrusted | expired | revoked | invalid | unknown (pas de certificat).
     */
    public function certificateTrust(?string $certificatePem): string
    {
        return $certificatePem ? $this->authority->validateChain($certificatePem)['status'] : 'unknown';
    }

    /** CMS valide ET signé par la clé enregistrée (le certificat embarqué ne suffit pas). */
    private function verifyCmsAgainstKey(string $cms, string $content, string $publicKey): bool
    {
        $result = $this->cms->verifyContent($content, $cms);

        if (! $result['valid']) {
            return false;
        }

        $expected = preg_replace('/\s+/', '', $publicKey);

        foreach ($result['signers'] as $certificate) {
            $signerKey = $this->cms->publicKeyFromCertificate($certificate);

            if ($signerKey !== null && hash_equals($expected, preg_replace('/\s+/', '', $signerKey))) {
                return true;
            }
        }

        return false;
    }

    private function certificateCommonName(?string $certificatePem): ?string
    {
        $parsed = $certificatePem ? @openssl_x509_parse($certificatePem) : false;

        return $parsed['subject']['CN'] ?? null;
    }

    private function externalPublicKey(array $data): ?string
    {
        if (! empty($data['public_key'])) {
            return $data['public_key'];
        }

        if (! empty($data['certificates'][0])) {
            $certificate = @openssl_x509_read($data['certificates'][0]);

            return $certificate ? (openssl_pkey_get_details(openssl_pkey_get_public($certificate))['key'] ?? null) : null;
        }

        return null;
    }

    private function opensslVerify(string $content, string $signature, string $publicKey): bool
    {
        if ($signature === '') {
            return false;
        }

        return openssl_verify($content, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    private function isBinary(string $content): bool
    {
        return ! mb_check_encoding($content, 'UTF-8') || preg_match('/[^\x20-\x7E\r\n\t]/', $content) === 1;
    }

    private function elapsedMs(float $startTime): int
    {
        return (int) round((microtime(true) - $startTime) * 1000);
    }
}
