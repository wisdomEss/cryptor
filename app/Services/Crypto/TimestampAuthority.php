<?php

namespace App\Services\Crypto;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client d'horodatage RFC 3161 (Time-Stamp Protocol), sans dépendance : la requête
 * est encodée en DER à la main, la réponse est lue par un décodeur DER strict
 * (elle vient d'un serveur distant : toutes les bornes sont contrôlées), et le jeton
 * (un CMS SignedData) est vérifié par OpenSSL.
 *
 * Ce qui est vérifié pour un jeton :
 *  - sa signature CMS ;
 *  - que l'empreinte horodatée est bien celle attendue (SHA-256) et, à la création, le nonce ;
 *  - que le certificat de la TSA a l'usage « horodatage » et, si `ca_file` est configuré,
 *    qu'il se rattache à cette autorité.
 */
class TimestampAuthority
{
    private const OID_SHA256 = "\x60\x86\x48\x01\x65\x03\x04\x02\x01";

    /** Taille maximale d'une réponse de TSA acceptée. */
    private const MAX_RESPONSE_BYTES = 262144;

    public function __construct(private readonly CmsService $cms = new CmsService) {}

    public function enabled(): bool
    {
        return filled(config('cryptor.tsa.url'));
    }

    /**
     * Horodate des données (empreinte SHA-256) auprès de la TSA configurée.
     *
     * @return array{type: string, tsa: string, gen_time: string, hash: string, serial: string, trusted: bool, token: string}
     *
     * @throws RuntimeException si la TSA est injoignable, refuse, ou renvoie un jeton invalide
     */
    public function timestamp(string $data): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('No timestamp authority configured.');
        }

        $hash = hash('sha256', $data, true);
        $nonce = "\x01".random_bytes(8); // entier positif de 9 octets

        $response = Http::withBody($this->buildRequest($hash, $nonce), 'application/timestamp-query')
            ->accept('application/timestamp-reply')
            ->timeout((int) config('cryptor.tsa.timeout'))
            ->post((string) config('cryptor.tsa.url'));

        if (! $response->successful()) {
            throw new RuntimeException('Timestamp authority error (HTTP '.$response->status().').');
        }

        $token = $this->extractToken($response->body());
        $result = $this->verifyToken($token, $hash, $nonce);

        if (! $result['valid']) {
            throw new RuntimeException('Invalid timestamp token: '.$result['reason']);
        }

        return [
            'type' => 'rfc3161',
            'tsa' => (string) config('cryptor.tsa.url'),
            'gen_time' => $result['gen_time'],
            'hash' => bin2hex($hash),
            'serial' => $result['serial'],
            'trusted' => $result['trusted'],
            'token' => base64_encode($token),
        ];
    }

    /** TimeStampReq (RFC 3161 §2.4.1) encodé en DER. */
    public function buildRequest(string $sha256, string $nonce): string
    {
        $algorithm = $this->der(0x30, $this->der(0x06, self::OID_SHA256).$this->der(0x05, ''));
        $imprint = $this->der(0x30, $algorithm.$this->der(0x04, $sha256));

        return $this->der(0x30,
            $this->der(0x02, "\x01")        // version 1
            .$imprint                       // messageImprint
            .$this->der(0x02, $nonce)       // nonce
            .$this->der(0x01, "\xff")       // certReq : la TSA joint son certificat
        );
    }

    /**
     * Extrait le jeton (ContentInfo) d'une TimeStampResp, après contrôle du statut.
     *
     * @throws RuntimeException
     */
    public function extractToken(string $response): string
    {
        if ($response === '' || strlen($response) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('Unexpected timestamp response size.');
        }

        $top = $this->children($this->content($response));

        if (count($top) < 1 || $top[0]['tag'] !== 0x30) {
            throw new RuntimeException('Malformed timestamp response.');
        }

        $statusInfo = $this->children($top[0]['value']);
        $status = isset($statusInfo[0]) && $statusInfo[0]['tag'] === 0x02 ? ord($statusInfo[0]['value'][0] ?? "\xff") : 0xFF;

        // 0 = granted, 1 = grantedWithMods
        if ($status > 1) {
            throw new RuntimeException("Timestamp request rejected by the authority (status {$status}).");
        }

        if (! isset($top[1]) || $top[1]['tag'] !== 0x30) {
            throw new RuntimeException('The timestamp response contains no token.');
        }

        return $top[1]['raw'];
    }

    /**
     * Vérifie un jeton d'horodatage (DER).
     *
     * @param  string  $sha256  empreinte attendue (binaire)
     * @param  string|null  $nonce  nonce de la requête, si on vérifie une réponse fraîche
     * @return array{valid: bool, trusted: bool, gen_time: ?string, serial: ?string, reason: ?string}
     */
    public function verifyToken(string $tokenDer, string $sha256, ?string $nonce = null): array
    {
        $fail = fn (string $reason) => ['valid' => false, 'trusted' => false, 'gen_time' => null, 'serial' => null, 'reason' => $reason];

        $tokenFile = $this->tempFile();
        $tstFile = $this->tempFile();
        $signersFile = $this->tempFile();

        try {
            file_put_contents($tokenFile, $tokenDer);

            // Signature du jeton + extraction du TSTInfo (contenu encapsulé).
            $ok = @openssl_cms_verify($tokenFile, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY, $signersFile, [], null, $tstFile, null, null, OPENSSL_ENCODING_DER);

            if ($ok !== true) {
                return $fail('invalid token signature');
            }

            try {
                $tst = $this->parseTstInfo((string) file_get_contents($tstFile));
            } catch (RuntimeException $e) {
                return $fail($e->getMessage());
            }

            if ($tst['algorithm'] !== self::OID_SHA256) {
                return $fail('unexpected hash algorithm');
            }

            if (! hash_equals($sha256, $tst['hash'])) {
                return $fail('the timestamp does not match the signed data');
            }

            if ($nonce !== null && ! hash_equals(ltrim($nonce, "\x00"), ltrim((string) $tst['nonce'], "\x00"))) {
                return $fail('nonce mismatch');
            }

            $signer = $this->firstCertificate((string) file_get_contents($signersFile));

            if ($signer === null || ! $this->hasTimestampingUsage($signer)) {
                return $fail('the signer certificate is not a timestamping certificate');
            }

            return [
                'valid' => true,
                'trusted' => $this->isTrusted($signer),
                'gen_time' => $tst['gen_time']->toIso8601String(),
                'serial' => bin2hex($tst['serial']),
                'reason' => null,
            ];
        } finally {
            @unlink($tokenFile);
            @unlink($tstFile);
            @unlink($signersFile);
            while (openssl_error_string() !== false); // vide la file d'erreurs OpenSSL
        }
    }

    // ------------------------------------------------------------ TSTInfo

    /**
     * TSTInfo ::= SEQUENCE { version, policy, messageImprint, serialNumber, genTime,
     *                        accuracy?, ordering?, nonce?, tsa?, extensions? }
     *
     * @return array{algorithm: string, hash: string, serial: string, gen_time: Carbon, nonce: ?string}
     */
    private function parseTstInfo(string $der): array
    {
        $fields = $this->children($this->content($der));

        if (count($fields) < 5 || $fields[2]['tag'] !== 0x30 || $fields[3]['tag'] !== 0x02 || $fields[4]['tag'] !== 0x18) {
            throw new RuntimeException('malformed TSTInfo');
        }

        $imprint = $this->children($fields[2]['value']);

        if (count($imprint) < 2 || $imprint[0]['tag'] !== 0x30 || $imprint[1]['tag'] !== 0x04) {
            throw new RuntimeException('malformed message imprint');
        }

        $algorithm = $this->children($imprint[0]['value'])[0] ?? null;

        // genTime : GeneralizedTime « AAAAMMJJHHMMSS[.fff]Z »
        if (! preg_match('/^(\d{14})(?:\.\d+)?Z$/', $fields[4]['value'], $m)) {
            throw new RuntimeException('malformed genTime');
        }

        // Les champs facultatifs suivent genTime ; le nonce est le seul INTEGER (0x02) à ce niveau.
        $nonce = null;
        foreach (array_slice($fields, 5) as $field) {
            if ($field['tag'] === 0x02) {
                $nonce = $field['value'];
            }
        }

        return [
            'algorithm' => $algorithm && $algorithm['tag'] === 0x06 ? $algorithm['value'] : '',
            'hash' => $imprint[1]['value'],
            'serial' => $fields[3]['value'],
            'gen_time' => Carbon::createFromFormat('YmdHis', $m[1], 'UTC'),
            'nonce' => $nonce,
        ];
    }

    // ------------------------------------------------------ confiance TSA

    private function hasTimestampingUsage(string $certificatePem): bool
    {
        $parsed = @openssl_x509_parse($certificatePem);

        return $parsed && str_contains((string) ($parsed['extensions']['extendedKeyUsage'] ?? ''), 'Time Stamping');
    }

    /** Le certificat de la TSA se rattache-t-il au fichier `ca_file` configuré ? */
    private function isTrusted(string $signerPem): bool
    {
        $caFile = config('cryptor.tsa.ca_file');

        if (! $caFile || ! is_file($caFile)) {
            return false;
        }

        return @openssl_x509_checkpurpose($signerPem, X509_PURPOSE_ANY, [$caFile]) === true;
    }

    private function firstCertificate(string $bundle): ?string
    {
        return preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $bundle, $m) ? $m[0] : null;
    }

    // ------------------------------------------------------------- DER

    private function der(int $tag, string $content): string
    {
        $length = strlen($content);

        $header = match (true) {
            $length < 128 => chr($length),
            $length < 256 => "\x81".chr($length),
            default => "\x82".pack('n', $length),
        };

        return chr($tag).$header.$content;
    }

    /**
     * Lit un élément TLV à la position $offset, avec contrôle strict des bornes.
     *
     * @return array{tag: int, value: string, raw: string, end: int}
     */
    private function readTlv(string $data, int $offset): array
    {
        $size = strlen($data);

        if ($offset + 2 > $size) {
            throw new RuntimeException('truncated DER');
        }

        $tag = ord($data[$offset]);
        $length = ord($data[$offset + 1]);
        $headerSize = 2;

        if ($length & 0x80) {
            $octets = $length & 0x7F;

            // Longueur indéfinie (0) ou excessive (> 4 octets) : refusée.
            if ($octets === 0 || $octets > 4 || $offset + 2 + $octets > $size) {
                throw new RuntimeException('unsupported DER length');
            }

            $length = 0;
            for ($i = 0; $i < $octets; $i++) {
                $length = ($length << 8) | ord($data[$offset + 2 + $i]);
            }

            $headerSize = 2 + $octets;
        }

        $end = $offset + $headerSize + $length;

        if ($length < 0 || $end > $size) {
            throw new RuntimeException('DER length exceeds the data');
        }

        return [
            'tag' => $tag,
            'value' => substr($data, $offset + $headerSize, $length),
            'raw' => substr($data, $offset, $end - $offset),
            'end' => $end,
        ];
    }

    /** Contenu de l'élément racine, qui doit occuper tout le message. */
    private function content(string $data): string
    {
        $root = $this->readTlv($data, 0);

        if ($root['tag'] !== 0x30 || $root['end'] !== strlen($data)) {
            throw new RuntimeException('malformed DER structure');
        }

        return $root['value'];
    }

    /** @return list<array{tag: int, value: string, raw: string, end: int}> */
    private function children(string $content): array
    {
        $items = [];
        $offset = 0;

        while ($offset < strlen($content)) {
            if (count($items) >= 64) {
                throw new RuntimeException('too many DER elements');
            }

            $item = $this->readTlv($content, $offset);
            $items[] = $item;
            $offset = $item['end'];
        }

        return $items;
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tsa');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }

        return $path;
    }
}
