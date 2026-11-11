<?php

namespace App\Http\Controllers\PublicSignatures;

use App\Http\Controllers\Controller;
use App\Models\FileSigned;
use App\Models\Signature;
use App\Models\SignatureVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PublicSignaturesController extends Controller
{
    /**
     * Affiche la page de vérification publique
     */
    public function index()
    {
        return Inertia::render('publicVerifications/Index');
    }

    /**
     * Point d'entrée unique pour la vérification publique
     * - Si token fourni → vérification par token
     * - Si fichier uploadé → vérification par upload
     */
    public function verify(Request $request)
    {

        // Si un token est fourni, vérifier par token
        if ($request->filled('token')) {
            return $this->verifyByToken($request);
        }

        // Sinon, vérifier par upload de fichier
        return $this->verifyByUpload($request);
    }

    /**
     * Vérifie un document via token (optionnel)
     */
    private function verifyByToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $startTime = microtime(true);

        try {
            // Rechercher la vérification par token
            $verification = SignatureVerification::where('verification_token', $request->token)
                ->with(['signature.file', 'signature.signer', 'signature.keyPair'])
                ->first();

            if (! $verification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token de vérification invalide.',
                    'details' => [
                        'signature_valid' => false,
                        'document_integrity' => false,
                        'signer' => 'Inconnu',
                        'signed_at' => null,
                        'algorithm' => 'Inconnu',
                        'fingerprint' => '',
                        'warning' => 'Le token fourni n\'existe pas dans notre système.',
                    ],
                ]);
            }

            $signature = $verification->signature;

            if (! $signature) {
                return response()->json([
                    'success' => false,
                    'message' => 'Signature introuvable pour ce token.',
                    'details' => [
                        'signature_valid' => false,
                        'document_integrity' => false,
                        'signer' => 'Inconnu',
                        'signed_at' => null,
                        'algorithm' => 'Inconnu',
                        'fingerprint' => '',
                    ],
                ]);
            }

            $details = [
                'signature_valid' => $signature->is_valid,
                'document_integrity' => true,
                'signer' => $signature->signer->name ?? 'Inconnu',
                'signed_at' => $signature->signed_at?->toIso8601String(),
                'algorithm' => $signature->algorithm_used,
                'fingerprint' => $this->generateFingerprint($signature->keyPair->public_key ?? ''),
                'file_name' => $signature->file->original_file_name ?? 'Document inconnu',
                'verification_date' => $verification->verified_at?->toIso8601String(),
            ];

            if ($signature->keyPair && $signature->keyPair->certificate_data) {
                $details['certificate_info'] = [
                    'issuer' => $signature->keyPair->certificate_issuer ?? 'Inconnu',
                    'valid_from' => $signature->keyPair->valid_from?->toIso8601String(),
                    'valid_to' => $signature->keyPair->valid_to?->toIso8601String(),
                ];
            }

            $duration = round((microtime(true) - $startTime) * 1000);

            $verification->update([
                'verification_duration_ms' => $duration,
                'verifier_ip' => $request->ip(),
                'verifier_user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => $signature->is_valid && $verification->result,
                'message' => $this->getVerificationMessage($signature->is_valid, true, true),
                'details' => $details,
                'verification_id' => $verification->id,
                'token' => $request->token,
            ]);

        } catch (\Exception $e) {
            \Log::error('Public verification by token failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification: '.$e->getMessage(),
                'details' => [
                    'signature_valid' => false,
                    'document_integrity' => false,
                    'signer' => 'Inconnu',
                    'signed_at' => null,
                    'algorithm' => 'Inconnu',
                    'fingerprint' => '',
                ],
            ], 500);
        }
    }

    /**
     * Vérifie un document uploadé (sans token)
     */
    private function verifyByUpload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240',
            'signature_file' => 'nullable|file|max:5120',
        ]);

        $startTime = microtime(true);

        try {
            DB::beginTransaction();

            $file = $request->file('file');
            $signatureFile = $request->file('signature_file');

            $fileHash = hash_file('sha256', $file->getRealPath());

            // Chercher si le fichier existe dans notre système
            $fileSigned = FileSigned::where('file_hash', $fileHash)->first();

            $isInternalDocument = ! is_null($fileSigned);

            $result = $isInternalDocument
                ? $this->verifyInternalDocument($fileSigned, $file, $signatureFile, $request, $startTime)
                : $this->verifyExternalDocument($file, $signatureFile, $request, $startTime);

            DB::commit();

            return response()->json($result);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Public verification by upload failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification: '.$e->getMessage(),
                'details' => [
                    'signature_valid' => false,
                    'document_integrity' => false,
                    'signer' => 'Inconnu',
                    'signed_at' => null,
                    'algorithm' => 'Inconnu',
                    'fingerprint' => '',
                    'warning' => 'Le document n\'a pas pu être vérifié.',
                ],
            ], 500);
        }
    }

    /**
     * Vérifie un document interne (dans notre base)
     */
    private function verifyInternalDocument($fileSigned, $file, $signatureFile, $request, $startTime)
    {
        $signature = $fileSigned->signatures()
            ->with(['keyPair', 'signer'])
            ->latest()
            ->first();

        if (! $signature) {
            throw new \Exception('Aucune signature trouvée pour ce document.');
        }

        $currentHash = hash_file('sha256', $file->getRealPath());
        $documentIntegrity = ($currentHash === $fileSigned->file_hash);

        $signatureValid = $this->verifySignatureFormat(
            $signature,
            $file,
            $signatureFile
        );

        $keyValid = $signature->keyPair && $signature->keyPair->isValid();

        $verificationSuccess = $documentIntegrity && $signatureValid && $keyValid;

        $verification = SignatureVerification::create([
            'signature_id' => $signature->id,
            'verifier_id' => null, // Public
            'verified_hash' => $currentHash,
            'expected_hash' => $fileSigned->file_hash,
            'result' => $verificationSuccess,
            'verification_type' => $signatureFile ? 'signature' : 'hash',
            'verification_details' => [
                'document_integrity' => $documentIntegrity,
                'signature_valid' => $signatureValid,
                'key_valid' => $keyValid,
                'format' => $signature->signature_format,
                'algorithm' => $signature->algorithm_used,
            ],
            'signature_timestamp' => $signature->signed_at,
            'document_timestamp' => $fileSigned->created_at,
            'verifier_ip' => $request->ip(),
            'verifier_user_agent' => $request->userAgent(),
            'verification_duration_ms' => round((microtime(true) - $startTime) * 1000),
            'is_public_verification' => true,
        ]);

        // Générer un token pour partage (optionnel)
        $verification->generateVerificationToken();

        return [
            'success' => $verificationSuccess,
            'message' => $this->getVerificationMessage(
                $verificationSuccess,
                $documentIntegrity,
                $signatureValid
            ),
            'details' => [
                'signature_valid' => $signatureValid,
                'document_integrity' => $documentIntegrity,
                'signer' => $signature->signer->name ?? 'Inconnu',
                'signed_at' => $signature->signed_at?->toIso8601String(),
                'algorithm' => $signature->algorithm_used,
                'fingerprint' => $this->generateFingerprint(
                    $signature->keyPair->public_key ?? ''
                ),
                'file_name' => $fileSigned->original_file_name,
            ],
            'verification_id' => $verification->id,
            'token' => $verification->verification_token, // Optionnel
        ];
    }

    /**
     * Vérifie un document externe (hors base)
     */
    private function verifyExternalDocument($file, $signatureFile, $request, $startTime)
    {
        // Si pas de fichier signature, on ne peut pas vérifier
        if (! $signatureFile) {
            throw new \Exception('Un fichier de signature est requis pour vérifier un document externe.');
        }

        $signatureContent = file_get_contents($signatureFile->getRealPath());
        $signatureData = $this->parseExternalSignature($signatureContent);

        if (! $signatureData || ! isset($signatureData['signature'])) {
            throw new \Exception('Format de signature invalide.');
        }

        $fileContent = file_get_contents($file->getRealPath());
        $publicKey = null;

        if (isset($signatureData['public_key'])) {
            $publicKey = $signatureData['public_key'];
        } elseif (isset($signatureData['certificates']) && ! empty($signatureData['certificates'])) {
            $certResource = openssl_x509_read($signatureData['certificates'][0]);
            $publicKey = openssl_pkey_get_details($certResource)['key'];
        }

        if (! $publicKey) {
            throw new \Exception('Aucune clé publique trouvée dans la signature.');
        }

        $signatureValid = openssl_verify(
            $fileContent,
            base64_decode($signatureData['signature']),
            $publicKey,
            OPENSSL_ALGO_SHA256
        ) === 1;

        $fileHash = hash_file('sha256', $file->getRealPath());

        $documentIntegrity = true;
        if (isset($signatureData['file_hash'])) {
            $documentIntegrity = ($fileHash === $signatureData['file_hash']);
        }

        $verificationSuccess = $signatureValid && $documentIntegrity;

        $verification = SignatureVerification::create([
            'signature_id' => null,
            'verifier_id' => null,
            'verified_hash' => $fileHash,
            'expected_hash' => $signatureData['file_hash'] ?? null,
            'result' => $verificationSuccess,
            'verification_type' => 'external',
            'verification_details' => [
                'signature_valid' => $signatureValid,
                'document_integrity' => $documentIntegrity,
                'format' => $signatureData['format'] ?? 'unknown',
                'external' => true,
            ],
            'signature_timestamp' => $signatureData['timestamp'] ?? null,
            'verifier_ip' => $request->ip(),
            'verifier_user_agent' => $request->userAgent(),
            'verification_duration_ms' => round((microtime(true) - $startTime) * 1000),
            'is_public_verification' => true,
        ]);

        $verification->generateVerificationToken(); // Optionnel

        return [
            'success' => $verificationSuccess,
            'message' => $verificationSuccess
                ? 'La signature externe est valide'
                : 'La signature externe est invalide',
            'details' => [
                'signature_valid' => $signatureValid,
                'document_integrity' => $documentIntegrity,
                'signer' => $signatureData['signer'] ?? 'Signataire externe',
                'signed_at' => $signatureData['timestamp'] ?? null,
                'algorithm' => $signatureData['algorithm'] ?? 'Inconnu',
                'fingerprint' => $this->generateFingerprint($publicKey),
                'warning' => 'Document externe - non enregistré dans notre système',
            ],
            'verification_id' => $verification->id,
            'token' => $verification->verification_token, // Optionnel
        ];
    }

    // Les méthodes suivantes restent identiques :
    // - verifySignatureFormat()
    // - verifyPadesSignature()
    // - verifyCmsSignature()
    // - verifyBasicSignature()
    // - parseExternalSignature()
    // - isBinaryContent()
    // - generateFingerprint()
    // - getVerificationMessage()

    /**
     * Vérifie la signature selon son format
     */
    private function verifySignatureFormat($signature, $file, $signatureFile = null)
    {
        try {
            switch ($signature->signature_format) {
                case 'pdf':
                case 'PAdES':
                    return $this->verifyPadesSignature($signature, $file);

                case 'cms':
                case 'CAdES':
                    return $this->verifyCmsSignature($signature, $file);

                default:
                    return $this->verifyBasicSignature($signature, $file);
            }
        } catch (\Exception $e) {
            \Log::error('Signature verification error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Vérification signature PAdES (PDF)
     */
    private function verifyPadesSignature($signature, $file)
    {
        $signatureData = json_decode($signature->signature_value, true);

        if (! $signatureData || ! isset($signatureData['signature'])) {
            return false;
        }

        $pdfContent = file_get_contents($file->getRealPath());

        $keyPair = $signature->keyPair;
        if (! $keyPair || ! $keyPair->public_key) {
            return false;
        }

        return openssl_verify(
            $pdfContent,
            base64_decode($signatureData['signature']),
            $keyPair->public_key,
            OPENSSL_ALGO_SHA256
        ) === 1;
    }

    /**
     * Vérification signature CMS
     */
    private function verifyCmsSignature($signature, $file)
    {
        $signatureData = json_decode($signature->signature_value, true);

        if (! $signatureData || ! isset($signatureData['signature'])) {
            return false;
        }

        $fileContent = file_get_contents($file->getRealPath());

        if (isset($signatureData['signedAttributes']['messageDigest'])) {
            $storedHash = base64_decode($signatureData['signedAttributes']['messageDigest']);
            $computedHash = hash('sha256', $fileContent, true);

            if ($storedHash !== $computedHash) {
                return false;
            }
        }

        $keyPair = $signature->keyPair;
        if (! $keyPair || ! $keyPair->public_key) {
            return false;
        }

        return openssl_verify(
            $fileContent,
            base64_decode($signatureData['signature']),
            $keyPair->public_key,
            OPENSSL_ALGO_SHA256
        ) === 1;
    }

    /**
     * Vérification signature basique
     */
    private function verifyBasicSignature($signature, $file)
    {
        $fileContent = file_get_contents($file->getRealPath());

        $keyPair = $signature->keyPair;
        if (! $keyPair || ! $keyPair->public_key) {
            return false;
        }

        return openssl_verify(
            $fileContent,
            base64_decode($signature->signature_value),
            $keyPair->public_key,
            OPENSSL_ALGO_SHA256
        ) === 1;
    }

    /**
     * Parse une signature externe
     */
    private function parseExternalSignature($content)
    {
        $data = json_decode($content, true);
        if ($data && isset($data['signature'])) {
            return $data;
        }

        $pkcs7Data = openssl_pkcs7_read($content, $certificates);
        if ($pkcs7Data) {
            return [
                'format' => 'PKCS7',
                'signature' => base64_encode($content),
                'certificates' => $certificates,
            ];
        }

        if ($this->isBinaryContent($content)) {
            return [
                'format' => 'raw',
                'signature' => base64_encode($content),
            ];
        }

        return null;
    }

    /**
     * Vérifie si le contenu est binaire
     */
    private function isBinaryContent($content)
    {
        return ! mb_check_encoding($content, 'UTF-8') || preg_match('/[^\x20-\x7E\r\n\t]/', $content);
    }

    /**
     * Génère une empreinte de clé
     */
    private function generateFingerprint($publicKey)
    {
        $key = preg_replace('/-----(BEGIN|END) (PUBLIC|PRIVATE) KEY-----/', '', $publicKey);
        $key = preg_replace('/\s+/', '', $key);

        return implode(':', str_split(substr(strtoupper(hash('sha256', $key)), 0, 16), 2));
    }

    /**
     * Message de vérification
     */
    private function getVerificationMessage($success, $documentIntegrity, $signatureValid)
    {
        if ($success) {
            return 'Le document est authentique et la signature est valide.';
        }

        if (! $documentIntegrity && ! $signatureValid) {
            return 'Le document a été modifié et la signature est invalide.';
        }

        if (! $documentIntegrity) {
            return 'Le document a été modifié depuis sa signature.';
        }

        if (! $signatureValid) {
            return 'La signature est invalide ou a été altérée.';
        }

        return 'La vérification a échoué.';
    }
}
