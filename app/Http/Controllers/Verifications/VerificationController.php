<?php

namespace App\Http\Controllers\Verifications;

use App\Http\Controllers\Controller;
use App\Models\FileSigned;
use App\Models\Signature;
use App\Models\SignatureVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class VerificationController extends Controller
{
    /**
     * Display a listing of verifications.
     */
    public function index(Request $request)
    {
        $query = SignatureVerification::with([
            'signature' => function ($q) {
                $q->with(['file', 'signer']);
            },
            'verifier',
        ])
            ->whereHas('signature', function ($q) {
                $q->where('signer_id', Auth::id()) // Verifications of user's signatures
                    ->orWhereHas('file', function ($subQ) {
                        $subQ->where('uploader_id', Auth::id()); // Or files uploaded by user
                    });
            });

        // Filtre par recherche (nom du fichier)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('signature.file', function ($q) use ($search) {
                $q->where('original_file_name', 'like', "%{$search}%");
            });
        }

        // Filtre par résultat
        if ($request->filled('result')) {
            if ($request->result === 'valid') {
                $query->where('result', true);
            } elseif ($request->result === 'invalid') {
                $query->where('result', false);
            }
        }

        // Filtre par type
        if ($request->filled('type')) {
            if ($request->type === 'public') {
                $query->where('is_public_verification', true);
            } elseif ($request->type !== 'all') {
                $query->where('verification_type', $request->type)
                    ->where('is_public_verification', false);
            }
        }

        // Filtre par date
        if ($request->filled('date_from')) {
            $query->whereDate('verified_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('verified_at', '<=', $request->date_to);
        }

        // Pagination
        $perPage = $request->input('per_page', 15);
        $verifications = $query->latest('verified_at')
            ->paginate($perPage)
            ->withQueryString();

        // Formater les données
        $formattedVerifications = collect($verifications->items())->map(function ($verif) {
            return [
                'id' => $verif->id,
                'result' => $verif->result,
                'verification_type' => $verif->verification_type,
                'is_public_verification' => $verif->is_public_verification,
                'verified_at' => $verif->verified_at?->toIso8601String(),
                'verification_duration_ms' => $verif->verification_duration_ms,
                'token' => $verif->verification_token,
                'signature' => [
                    'id' => $verif->signature->id,
                    'file_name' => $verif->signature->file->original_file_name ?? 'Unknown',
                    'signer_name' => $verif->signature->signer->name ?? 'Unknown',
                ],
                'verifier' => $verif->verifier ? [
                    'id' => $verif->verifier->id,
                    'name' => $verif->verifier->name,
                    'email' => $verif->verifier->email,
                ] : null,
            ];
        });

        return Inertia::render('verifications/Index', [
            'verifications' => $formattedVerifications,
            'filters' => $request->only(['search', 'result', 'type', 'date_from', 'date_to']),
            'pagination' => [
                'current_page' => $verifications->currentPage(),
                'last_page' => $verifications->lastPage(),
                'per_page' => $verifications->perPage(),
                'total' => $verifications->total(),
            ],
        ]);
    }

    public function create()
    {
        // Récupérer les signatures récentes pour l'affichage
        $recentSignatures = Signature::with(['file', 'signer'])
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($signature) {
                return [
                    'id' => $signature->id,
                    'file_name' => $signature->file->original_file_name,
                    'signer_name' => $signature->signer->name,
                    'signed_at' => $signature->signed_at->diffForHumans(),
                ];
            });

        return inertia('verifications/Create', [
            'recentSignatures' => $recentSignatures,
        ]);
    }

    /**
     * Vérification d'un document signé dans notre système
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240',
            'signature_file' => 'nullable|file|max:5120',
            'include_details' => 'boolean',
        ]);

        $startTime = microtime(true);

        try {
            DB::beginTransaction();

            $file = $request->file('file');
            $signatureFile = $request->file('signature_file');

            $fileHash = hash_file('sha256', $file->getRealPath());

            $fileSigned = FileSigned::where('file_hash', $fileHash)
                ->orWhereHas('signatures', function ($query) use ($fileHash) {
                    $query->whereHas('verifications', function ($q) use ($fileHash) {
                        $q->where('verified_hash', $fileHash);
                    });
                })
                ->first();

            $isExternalVerification = ! $fileSigned;

            $result = $isExternalVerification
                ? $this->verifyExternalDocument($file, $signatureFile, $request, $startTime)
                : $this->verifyInternalDocument($fileSigned, $file, $signatureFile, $request, $startTime);

            DB::commit();

            return Inertia::render('verifications/Create', [
                'recentSignatures' => $this->getRecentSignatures(),
                'verificationResult' => $result,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Verification failed: '.$e->getMessage());

            return Inertia::render('verifications/Create', [
                'recentSignatures' => $this->getRecentSignatures(),
                'verificationResult' => [
                    'success' => false,
                    'message' => 'Verification failed: '.$e->getMessage(),
                    'details' => [],
                ],
            ]);
        }
    }

    private function verifyInternalDocument($fileSigned, $file, $signatureFile, $request, $startTime)
    {
        $signature = $fileSigned->signatures()
            ->with(['keyPair', 'signer'])
            ->latest()
            ->first();

        if (! $signature) {
            throw new \Exception('No signature found for this document.');
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
            'verifier_id' => auth()->id(),
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
            'is_public_verification' => ! auth()->check(),
        ]);

        if ($request->include_details) {
            $verification->generateVerificationToken();
        }

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
                'signer' => $signature->signer->name ?? 'Unknown',
                'signed_at' => $signature->signed_at->toIso8601String(),
                'algorithm' => $signature->algorithm_used,
                'fingerprint' => $this->generateFingerprint(
                    $signature->keyPair->public_key ?? ''
                ),
            ],
            'id' => $verification->id,
            'token' => $verification->verification_token,
        ];
    }

    private function getRecentSignatures()
    {
        return Signature::with(['file', 'signer'])
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($signature) {
                return [
                    'id' => $signature->id,
                    'file_name' => $signature->file->original_file_name,
                    'signer_name' => $signature->signer->name,
                    'signed_at' => $signature->signed_at->diffForHumans(),
                ];
            });
    }

    private function verifyExternalDocument($file, $signatureFile, $request, $startTime)
    {
        if (! $signatureFile) {
            throw new \Exception('Signature file is required for external document verification.');
        }

        $signatureContent = file_get_contents($signatureFile->getRealPath());
        $signatureData = $this->parseExternalSignature($signatureContent);

        if (! $signatureData || ! isset($signatureData['signature']) || ! isset($signatureData['public_key'])) {
            throw new \Exception('Invalid signature format.');
        }

        $fileContent = file_get_contents($file->getRealPath());

        $signatureValid = openssl_verify(
            $fileContent, // ⚠ contenu brut
            base64_decode($signatureData['signature']),
            $signatureData['public_key'],
            OPENSSL_ALGO_SHA256
        ) === 1;

        $verification = SignatureVerification::create([
            'signature_id' => null,
            'verifier_id' => auth()->id(),
            'verified_hash' => hash_file('sha256', $file->getRealPath()),
            'expected_hash' => $signatureData['file_hash'] ?? null,
            'result' => $signatureValid,
            'verification_type' => 'external',
            'verification_details' => [
                'external' => true,
            ],
            'signature_timestamp' => $signatureData['timestamp'] ?? null,
            'verifier_ip' => $request->ip(),
            'verifier_user_agent' => $request->userAgent(),
            'verification_duration_ms' => round((microtime(true) - $startTime) * 1000),
            'is_public_verification' => ! auth()->check(),
        ]);

        return [
            'success' => $signatureValid,
            'message' => $signatureValid
                ? 'External signature is valid'
                : 'External signature is invalid',
            'details' => [
                'signature_valid' => $signatureValid,
                'document_integrity' => true,
                'external' => true,
            ],
            'id' => $verification->id,
        ];
    }

    /**
     * Vérifie la signature selon son format
     */
    private function verifySignatureFormat($signature, $file, $signatureFile = null)
    {
        try {
            switch ($signature->signature_format) {
                // case 'PKCS7':
                // case 'pkcs7':
                //     return $this->verifyPkcs7Signature($signature, $file, $signatureFile);

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
        // dd($keyPair);
        if (! $keyPair || ! $keyPair->public_key) {
            return false;
        }

        return openssl_verify(
            $pdfContent, // ⚠ contenu brut
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

        // Vérifier que le hash stocké correspond
        if (isset($signatureData['signedAttributes']['messageDigest'])) {
            $storedHash = base64_decode($signatureData['signedAttributes']['messageDigest']);
            $computedHash = hash('sha256', $fileContent, true);

            if ($storedHash !== $computedHash) {
                return false;
            }
        }

        $keyPair = $signature->keyPair;
        // dd($keyPair);
        if (! $keyPair || ! $keyPair->public_key) {
            return false;
        }

        return openssl_verify(
            $fileContent, // ⚠ contenu brut
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
            $fileContent, // ⚠ contenu brut, pas hash
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
        // Essayer de parser comme JSON
        $data = json_decode($content, true);
        if ($data && isset($data['signature'])) {
            return $data;
        }

        // Essayer de parser comme PKCS#7
        $pkcs7Data = openssl_pkcs7_read($content, $certificates);
        if ($pkcs7Data) {
            return [
                'format' => 'PKCS7',
                'signature' => base64_encode($content),
                'certificates' => $certificates,
            ];
        }

        return null;
    }

    /**
     * Génère une empreinte de clé
     */
    private function generateFingerprint($publicKey)
    {
        $key = preg_replace('/-----(BEGIN|END) (PUBLIC|PRIVATE) KEY-----/', '', $publicKey);
        $key = preg_replace('/\s+/', '', $key);

        return substr(hash('sha256', $key), 0, 16);
    }

    /**
     * Message de vérification
     */
    private function getVerificationMessage($success, $documentIntegrity, $signatureValid)
    {
        if ($success) {
            return 'The document is authentic and the signature is valid.';
        }

        if (! $documentIntegrity && ! $signatureValid) {
            return 'The document has been modified and the signature is invalid.';
        }

        if (! $documentIntegrity) {
            return 'The document has been modified since it was signed.';
        }

        if (! $signatureValid) {
            return 'The signature is invalid or has been tampered with.';
        }

        return 'Verification failed.';
    }

    /**
     * Route pour la vérification publique
     */
    public function publicVerify($token)
    {
        $verification = SignatureVerification::where('verification_token', $token)
            ->with(['signature.file', 'signature.signer', 'signature.keyPair'])
            ->firstOrFail();

        return inertia('verifications/Public', [
            'verification' => $verification,
        ]);
    }

    /**
     * Afficher les détails d'une vérification
     */
    public function show($id)
    {
        $userId = Auth::id();

        // Sécurité : seule la personne qui a demandé la vérification, qui a
        // signé le document, qui l'a uploadé, ou qui a réalisé la vérification
        // elle-même, peut la consulter.
        $verification = SignatureVerification::with([
            'signature.file',
            'signature.signer',
            'signature.keyPair',
            'verifier',
        ])
            ->where(function ($query) use ($userId) {
                $query->where('verifier_id', $userId)
                    ->orWhereHas('signature', function ($q) use ($userId) {
                        $q->where('signer_id', $userId)
                            ->orWhereHas('file', fn ($fq) => $fq->where('uploader_id', $userId));
                    });
            })
            ->findOrFail($id);

        return inertia('verifications/Show', [
            'verification' => $verification,
        ]);
    }
}
