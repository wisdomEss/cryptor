<?php

namespace App\Services\Signing;

use App\Exceptions\UserFacingException;
use App\Models\FileSigned;
use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\SignatureVerification;
use App\Models\User;
use App\Services\Crypto\CertificateAuthority;
use App\Services\Crypto\CmsService;
use App\Services\Crypto\TimestampAuthority;
use App\Support\ClientContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Signe un document : stockage du fichier, empreintes, signature CMS détachée (RFC 5652),
 * horodatage, puis enregistrement en base.
 *
 * Ordre voulu : tout ce qui est lent ou externe (signature, appel à l'autorité
 * d'horodatage) se fait AVANT d'ouvrir la transaction SQL, qui ne contient que des écritures.
 * Si une étape échoue, les fichiers déjà écrits sont supprimés.
 *
 * Les formats « cms » et « pdf » produisent le même conteneur : une vraie signature PAdES
 * (incorporée dans le PDF) demanderait une bibliothèque PDF dédiée.
 */
class DocumentSigner
{
    public function __construct(
        private readonly CmsService $cms,
        private readonly CertificateAuthority $authority,
        private readonly TimestampAuthority $timestamps,
    ) {}

    /**
     * @throws UserFacingException clé inutilisable, certificat absent, échec du stockage
     */
    public function sign(User $user, KeyPair $key, UploadedFile $file, SigningRequest $options, ClientContext $client): Signature
    {
        $privateKey = $this->usablePrivateKey($key);

        /** @var list<string> $written chemins à supprimer si quelque chose échoue */
        $written = [];

        try {
            $extension = preg_replace('/[^A-Za-z0-9]/', '', $file->getClientOriginalExtension());
            $storedName = time().'_'.uniqid().($extension !== '' ? '.'.$extension : '');
            $path = $file->storeAs('signatures/'.$user->id.'/documents/'.date('Y/m/d'), $storedName, 'local');

            if (! $path) {
                throw new UserFacingException('Failed to upload file.');
            }
            $written[] = $path;

            $hashes = $this->hashes($file->getRealPath());
            $cmsPem = $this->createCms($file->getRealPath(), $privateKey, $key);
            $timestampToken = $this->timestampToken($cmsPem);

            return DB::transaction(function () use ($user, $key, $file, $options, $client, $path, $storedName, $extension, $hashes, $cmsPem, $timestampToken, &$written) {
                $fileSigned = FileSigned::create([
                    'file_name' => $storedName,
                    'original_file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_hash' => $hashes['sha256'],
                    'original_hash' => $hashes['sha256'],
                    'hash_algorithm' => 'sha256',
                    'uploader_id' => $user->id,
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'file_extension' => $extension,
                    'is_encrypted' => false,
                    'status' => 'pending_signature',
                    'total_signatures_needed' => 1,
                    'signatures_count' => 0,
                    'expires_at' => $options->expiresInDays > 0 ? now()->addDays($options->expiresInDays) : null,
                    'retention_period' => $options->expiresInDays,
                    'upload_ip' => $client->ip,
                    'upload_user_agent' => $client->userAgent,
                    'metadata' => [
                        'sha512' => $hashes['sha512'],
                        'md5' => $hashes['md5'],
                        'uploaded_at' => now()->toIso8601String(),
                        'upload_ip' => $client->ip,
                        'user_agent' => $client->userAgent,
                    ],
                ]);

                $signature = Signature::create([
                    'file_id' => $fileSigned->id,
                    'key_pair_id' => $key->id,
                    'signer_id' => $user->id,
                    'signature_value' => $cmsPem,
                    'signature_format' => $options->format,
                    'algorithm_used' => $key->algorithm_used,
                    'signing_reason' => $options->reason,
                    'signing_location' => $options->location,
                    'contact_info' => $options->contact,
                    'visual_signature_data' => $options->includeVisual && $options->format === 'pdf'
                        ? $this->visualSignatureData($user, $key, $options)
                        : null,
                    'is_valid' => true,
                    'validation_status' => 'pending',
                    'signed_at' => now(),
                    'signing_ip' => $client->ip,
                    'signing_device_info' => $client->userAgent,
                    'nonce_value' => bin2hex(random_bytes(32)), // anti-rejeu
                    'timestamp_token' => $timestampToken,
                ]);

                // Fichier .p7s standard (CMS en DER). L'identifiant évite l'écrasement entre deux
                // documents de même nom.
                $p7s = 'signatures/'.$user->id.'/signatures/'.date('Y/m/d').'/'
                    .Str::slug(pathinfo($fileSigned->original_file_name, PATHINFO_FILENAME)).'-'.$signature->id.'.p7s';
                Storage::disk('local')->put($p7s, $this->cms->pemToDer($cmsPem));
                $written[] = $p7s;
                $signature->update(['signature_file_path' => $p7s]);

                $fileSigned->addSignatureCount();

                $key->update(['last_used' => now()]);
                $key->increment('use_count');

                $this->recordInitialVerification($signature, $client);

                return $signature;
            });
        } catch (\Throwable $e) {
            foreach ($written as $orphan) {
                Storage::disk('local')->delete($orphan);
            }

            throw $e;
        }
    }

    /** La clé peut-elle signer maintenant ? Renvoie sa clé privée déchiffrée. */
    private function usablePrivateKey(KeyPair $key): string
    {
        if (! $key->isValid()) {
            throw new UserFacingException('The selected key is not valid or has expired.');
        }

        if (empty($key->certificate_data)) {
            throw new UserFacingException('Signature generation failed: the selected key has no certificate.');
        }

        try {
            $privateKey = $key->private_key; // déchiffrée par le cast `encrypted`
        } catch (DecryptException) {
            throw new UserFacingException('Failed to decrypt private key.');
        }

        if (empty($privateKey)) {
            throw new UserFacingException('The private key is not available.');
        }

        return $privateKey;
    }

    /** Une seule lecture du fichier pour les trois empreintes. */
    private function hashes(string $path): array
    {
        $contexts = ['sha256' => hash_init('sha256'), 'sha512' => hash_init('sha512'), 'md5' => hash_init('md5')];
        $handle = fopen($path, 'rb');

        while (! feof($handle)) {
            $chunk = fread($handle, 1 << 20);
            foreach ($contexts as $context) {
                hash_update($context, $chunk);
            }
        }
        fclose($handle);

        return array_map(fn ($context) => hash_final($context), $contexts);
    }

    /** CMS détaché (PEM). Si le certificat vient de notre AC, on embarque celui de l'AC : un
     *  vérificateur tiers valide alors toute la chaîne (`openssl cms -verify -CAfile ca.crt`). */
    private function createCms(string $filePath, string $privateKey, KeyPair $key): string
    {
        try {
            $caPath = $this->authority->isIssuedByCa($key->certificate_data) ? $this->authority->certificatePath() : null;

            return $this->cms->sign($filePath, $key->certificate_data, $privateKey, $caPath);
        } catch (\Throwable $e) {
            // Le détail (erreurs OpenSSL) reste dans les logs, pas à l'écran.
            Log::error('CMS signature generation failed', ['exception' => $e]);

            throw new UserFacingException('Signature generation failed.');
        }
    }

    /**
     * Horodatage RFC 3161 auprès de l'autorité configurée, sinon (ou si elle est injoignable)
     * horodatage interne. Une autorité en panne ne doit jamais empêcher de signer.
     */
    private function timestampToken(string $cmsPem): string
    {
        if ($this->timestamps->enabled()) {
            try {
                // On horodate la signature elle-même (le .p7s en DER).
                return json_encode($this->timestamps->timestamp($this->cms->pemToDer($cmsPem)));
            } catch (\Throwable $e) {
                Log::warning('Timestamp authority unavailable, falling back to the internal timestamp: '.$e->getMessage());
            }
        }

        return json_encode([
            'type' => 'internal',
            'time' => now()->toIso8601String(),
            'nonce' => bin2hex(random_bytes(16)),
            'server' => gethostname(),
        ]);
    }

    /** Métadonnées de mise en page de la signature visuelle (le PDF lui-même n'est pas modifié). */
    private function visualSignatureData(User $user, KeyPair $key, SigningRequest $options): string
    {
        return json_encode([
            'page' => 1,
            'x' => 50,
            'y' => 50,
            'width' => 200,
            'height' => 80,
            'name' => $user->name,
            'date' => now()->format('d/m/Y H:i:s'),
            'reason' => $options->reason ?? 'Document approval',
            'location' => $options->location ?? 'Unknown',
            'contact' => $options->contact ?? $user->email,
            'certificate_issuer' => $key->certificate_issuer ?? 'Unknown',
            'certificate_validity' => 'Valid',
            'signature_image' => null,
            'text_style' => ['font' => 'Helvetica', 'size' => 10, 'color' => [0, 0, 0]],
            'border' => ['width' => 1, 'color' => [0, 0, 0]],
            'background' => [255, 255, 255],
        ]);
    }

    private function recordInitialVerification(Signature $signature, ClientContext $client): void
    {
        SignatureVerification::create([
            'signature_id' => $signature->id,
            'verifier_id' => $signature->signer_id,
            'verified_hash' => $signature->file->file_hash,
            'expected_hash' => $signature->file->original_hash,
            'result' => true,
            'verification_type' => 'signature',
            'verification_details' => [
                'algorithm' => $signature->algorithm_used,
                'format' => $signature->signature_format,
                'signature_valid' => true,
                'document_integrity' => true,
            ],
            'signature_timestamp' => now(),
            'document_timestamp' => now(),
            'verifier_ip' => $client->ip,
            'verifier_user_agent' => $client->userAgent,
            'verification_duration_ms' => 0,
            'is_public_verification' => false,
            'verified_at' => now(),
        ]);
    }
}
