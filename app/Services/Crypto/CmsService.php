<?php

namespace App\Services\Crypto;

use RuntimeException;

/**
 * Signatures CMS (RFC 5652) **détachées**, produites et vérifiées par OpenSSL
 * (`openssl_cms_sign` / `openssl_cms_verify`, natifs PHP, sans dépendance).
 *
 * Le format obtenu est un vrai conteneur CMS `SignedData` avec attributs signés
 * (contentType, signingTime, messageDigest) et le certificat du signataire :
 * il se vérifie avec des outils tiers, par exemple
 *   openssl cms -verify -inform DER -in doc.p7s -content doc -noverify -binary
 */
class CmsService
{
    private const PEM_BEGIN = '-----BEGIN CMS-----';

    /** OID signedData (1.2.840.113549.1.7.2) encodé en DER, présent au début d'un ContentInfo CMS. */
    private const SIGNED_DATA_OID = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x07\x02";

    /**
     * Signe un fichier et renvoie le CMS détaché encodé en PEM.
     *
     * @param  string|null  $extraCertificatesPath  fichier PEM de certificats à embarquer en plus
     *                                              du signataire (typiquement l'AC), pour qu'un vérificateur
     *                                              tiers dispose de toute la chaîne
     *
     * @throws RuntimeException
     */
    public function sign(string $contentPath, string $certificatePem, string $privateKeyPem, ?string $extraCertificatesPath = null): string
    {
        $out = $this->tempFile();

        try {
            // `@` : OpenSSL signale l'échec par un avertissement PHP (converti en exception par Laravel) ;
            // on le remplace par une RuntimeException explicite ci-dessous.
            $ok = @openssl_cms_sign(
                $contentPath,
                $out,
                $certificatePem,
                $privateKeyPem,
                [],
                OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY,
                OPENSSL_ENCODING_PEM,
                $extraCertificatesPath
            );

            if (! $ok) {
                throw new RuntimeException('CMS signing failed: '.$this->opensslError());
            }

            $cms = (string) file_get_contents($out);

            if (! str_starts_with($cms, self::PEM_BEGIN)) {
                throw new RuntimeException('CMS signing produced an unexpected output.');
            }

            return $cms;
        } finally {
            @unlink($out);
        }
    }

    /**
     * Vérifie un CMS détaché (PEM ou DER) contre le contenu d'un fichier.
     *
     * L'intégrité et la signature sont réellement contrôlées. La chaîne du
     * certificat signataire n'est PAS validée ici (`NOVERIFY`) : l'appelant doit
     * s'assurer que le certificat renvoyé est digne de confiance.
     *
     * @return array{valid: bool, signers: list<string>} `signers` : certificats embarqués (PEM), même si la signature est invalide
     */
    public function verify(string $contentPath, string $cms): array
    {
        $sigFile = $this->tempFile();
        $signersFile = $this->tempFile();

        try {
            file_put_contents($sigFile, $cms);

            $result = @openssl_cms_verify(
                $contentPath,
                OPENSSL_CMS_DETACHED | OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY,
                $signersFile,
                [],
                null,
                null,
                null,
                $sigFile,
                $this->isPem($cms) ? OPENSSL_ENCODING_PEM : OPENSSL_ENCODING_DER
            );

            $valid = $result === true;

            // En cas d'échec OpenSSL ne renvoie pas les certificats : on les relit sans
            // contrôler la signature (valid reste false), pour pouvoir identifier le signataire.
            if (! $valid) {
                @openssl_cms_verify(
                    $contentPath,
                    OPENSSL_CMS_DETACHED | OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_NOSIGS | OPENSSL_CMS_BINARY,
                    $signersFile,
                    [],
                    null,
                    null,
                    null,
                    $sigFile,
                    $this->isPem($cms) ? OPENSSL_ENCODING_PEM : OPENSSL_ENCODING_DER
                );
            }

            return [
                'valid' => $valid,
                'signers' => $this->splitCertificates((string) file_get_contents($signersFile)),
            ];
        } finally {
            @unlink($sigFile);
            @unlink($signersFile);
            $this->opensslError(); // vide la file d'erreurs OpenSSL
        }
    }

    /** Variante de verify() pour un contenu déjà chargé en mémoire. */
    public function verifyContent(string $content, string $cms): array
    {
        $contentFile = $this->tempFile();

        try {
            file_put_contents($contentFile, $content);

            return $this->verify($contentFile, $cms);
        } finally {
            @unlink($contentFile);
        }
    }

    /** Le contenu est-il un conteneur CMS/PKCS#7 `SignedData` (PEM ou DER) ? */
    public function looksLikeCms(string $data): bool
    {
        if ($this->isPem($data) || str_contains($data, '-----BEGIN PKCS7-----')) {
            return true;
        }

        // DER : SEQUENCE (0x30) puis, dans les premiers octets, l'OID signedData.
        return isset($data[0]) && $data[0] === "\x30" && str_contains(substr($data, 0, 16), self::SIGNED_DATA_OID);
    }

    /** Convertit un CMS PEM en DER (format habituel d'un fichier `.p7s`). */
    public function pemToDer(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) (CMS|PKCS7)-----|\s+/', '', $pem);
        $der = base64_decode((string) $body, true);

        if ($der === false || $der === '') {
            throw new RuntimeException('Invalid PEM-encoded CMS.');
        }

        return $der;
    }

    /** Clé publique PEM (SPKI) contenue dans un certificat X.509. */
    public function publicKeyFromCertificate(string $certificatePem): ?string
    {
        $cert = @openssl_x509_read($certificatePem);
        $key = $cert ? openssl_pkey_get_public($cert) : false;

        return $key ? (openssl_pkey_get_details($key)['key'] ?? null) : null;
    }

    private function isPem(string $data): bool
    {
        return str_contains($data, '-----BEGIN');
    }

    /** @return list<string> */
    private function splitCertificates(string $bundle): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $bundle, $matches);

        return $matches[0];
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cms');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }

        return $path;
    }

    private function opensslError(): string
    {
        $messages = [];
        while (($e = openssl_error_string()) !== false) {
            $messages[] = $e;
        }

        return implode(' | ', $messages);
    }
}
