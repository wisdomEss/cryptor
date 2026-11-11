<?php

namespace App\Http\Controllers\Signatures;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Models\FileSigned;
use App\Models\KeyPair;
use App\Models\Signature;
use App\Models\SignatureVerification;
use App\Services\Crypto\CertificateAuthority;
use App\Services\Crypto\CmsService;
use App\Services\Crypto\TimestampAuthority;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class SignatureController extends Controller
{
    public function __construct(
        private readonly CmsService $cms,
        private readonly CertificateAuthority $ca,
        private readonly TimestampAuthority $tsa,
    ) {}

    public function show(Request $request, $id)
    {
        $signature = Signature::with([
            'file',
            'keyPair',
            'signer',
            'verifications' => function ($query) {
                $query->latest('verified_at')->limit(10);
            },
            'verifications.verifier',
        ])
            ->findOrFail($id);

        $this->authorize('view', $signature); // seul le signataire (404 sinon)

        // Formater les données pour la vue
        $formattedSignature = [
            'id' => $signature->id,
            'signature_value' => $signature->signature_value,
            'signature_format' => $signature->signature_format,
            'algorithm_used' => $signature->algorithm_used,
            'signing_reason' => $signature->signing_reason,
            'signing_location' => $signature->signing_location,
            'contact_info' => $signature->contact_info,
            'visual_signature_data' => $signature->visual_signature_data,
            'is_valid' => $signature->is_valid,
            'validation_status' => $signature->validation_status,
            'validation_message' => $signature->validation_message,
            'signed_at' => $signature->signed_at?->toIso8601String(),
            'validated_at' => $signature->validated_at?->toIso8601String(),
            'revoked_at' => $signature->revoked_at?->toIso8601String(),
            'signing_ip' => $signature->signing_ip,
            'signing_device_info' => $signature->signing_device_info,
            'nonce_value' => $signature->nonce_value,
            'timestamp_token' => $signature->timestamp_token,
            'verification_count' => $signature->verifications()->count(),

            'file' => [
                'id' => $signature->file->id,
                'original_file_name' => $signature->file->original_file_name,
                'file_size' => $signature->file->file_size,
                'mime_type' => $signature->file->mime_type,
                'file_extension' => $signature->file->file_extension,
                'file_hash' => $signature->file->file_hash,
                'expires_at' => $signature->file->expires_at?->toIso8601String(),
                'created_at' => $signature->file->created_at->toIso8601String(),
            ],

            'key_pair' => [
                'id' => $signature->keyPair->id,
                'key_name' => $signature->keyPair->key_name,
                'algorithm_used' => $signature->keyPair->algorithm_used,
                'key_size' => $signature->keyPair->key_size,
                'fingerprint' => $this->generateFingerprint($signature->keyPair->public_key),
                'public_key' => $signature->keyPair->public_key,
            ],

            'signer' => [
                'id' => $signature->signer->id,
                'name' => $signature->signer->name,
                'email' => $signature->signer->email,
            ],

            'verifications' => $signature->verifications->map(function ($verif) {
                return [
                    'id' => $verif->id,
                    'result' => $verif->result,
                    'verified_at' => $verif->verified_at?->toIso8601String(),
                    'verification_type' => $verif->verification_type,
                    'verifier' => $verif->verifier ? [
                        'name' => $verif->verifier->name,
                        'email' => $verif->verifier->email,
                    ] : null,
                ];
            }),
        ];

        return Inertia::render('signatures/Show', [
            'signature' => $formattedSignature,
        ]);
    }

    // Dans SignatureController.php

    /**
     * Update the specified signature (validate).
     */
    public function update(Request $request, $id)
    {
        $signature = $this->ownedSignature($id, 'update');

        $request->validate([
            'validation_notes' => 'nullable|string|max:500',
        ]);

        try {
            DB::beginTransaction();

            $signature->update([
                'validation_status' => 'valid',
                'is_valid' => true,
                'validated_at' => now(),
                'validation_message' => $request->validation_notes ?? 'Signature validated successfully',
            ]);

            // Créer une vérification automatique
            $verification = SignatureVerification::create([
                'signature_id' => $signature->id,
                'verifier_id' => auth()->id(),
                'verified_hash' => $signature->file->file_hash,
                'expected_hash' => $signature->file->file_hash,
                'result' => true,
                'verification_type' => 'manual',
                'verification_details' => [
                    'action' => 'validation',
                    'notes' => $request->validation_notes,
                    'validated_by' => auth()->user()->name,
                    'validated_at' => now()->toIso8601String(),
                ],
                'signature_timestamp' => $signature->signed_at,
                'document_timestamp' => $signature->file->created_at,
                'verifier_ip' => $request->ip(),
                'verifier_user_agent' => $request->userAgent(),
                'verification_duration_ms' => 0,
                'is_public_verification' => false,
                'verified_at' => now(),
            ]);

            $verification->generateVerificationToken();

            DB::commit();

            return redirect()->back()->with('success', 'Signature validated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Signature validate failed', ['exception' => $e, 'signature_id' => $id]);

            return back()->withErrors(['error' => 'Failed to validate signature.']);
        }
    }

    /**
     * Revoke the specified signature.
     */
    public function revoke(Request $request, $id)
    {
        $signature = $this->ownedSignature($id, 'update');

        $request->validate([
            'revocation_reason' => 'required|string|max:500',
        ]);

        try {
            DB::beginTransaction();

            $signature->update([
                'validation_status' => 'revoked',
                'is_valid' => false,
                'revoked_at' => now(),
                'revocation_reason' => $request->revocation_reason,
                'validation_message' => 'Signature revoked: '.$request->revocation_reason,
            ]);

            // Créer une vérification pour la révocation
            $verification = SignatureVerification::create([
                'signature_id' => $signature->id,
                'verifier_id' => auth()->id(),
                'verified_hash' => $signature->file->file_hash,
                'expected_hash' => $signature->file->file_hash,
                'result' => false,
                'verification_type' => 'manual',
                'verification_details' => [
                    'action' => 'revocation',
                    'reason' => $request->revocation_reason,
                    'revoked_by' => auth()->user()->name,
                    'revoked_at' => now()->toIso8601String(),
                ],
                'error_message' => 'Signature revoked: '.$request->revocation_reason,
                'signature_timestamp' => $signature->signed_at,
                'document_timestamp' => $signature->file->created_at,
                'verifier_ip' => $request->ip(),
                'verifier_user_agent' => $request->userAgent(),
                'verification_duration_ms' => 0,
                'is_public_verification' => false,
                'verified_at' => now(),
            ]);

            $verification->generateVerificationToken();

            DB::commit();

            return redirect()->back()->with('success', 'Signature revoked successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Signature revoke failed', ['exception' => $e, 'signature_id' => $id]);

            return back()->withErrors(['error' => 'Failed to revoke signature.']);
        }
    }

    /**
     * Affiche le formulaire de modification (non utilisé : la validation se
     * fait directement depuis la page Show).
     */
    public function edit($id)
    {
        return redirect()->route('signatures.show', $id);
    }

    /**
     * Télécharge la signature détachée (.p7s, CMS en DER).
     * Les anciennes signatures (JSON historique) sont servies telles quelles.
     */
    public function download($id)
    {
        $signature = $this->ownedSignature($id, 'view');
        $name = 'signature_'.$signature->id.'.p7s';
        $headers = ['Content-Type' => 'application/pkcs7-signature'];

        $path = $signature->signature_file_path;
        if ($path && Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->download($path, $name, $headers);
        }

        return response((string) $signature->signature_value, 200, $headers + [
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    /**
     * Supprime une signature et, si c'est la dernière sur le document,
     * le document associé.
     */
    public function destroy($id)
    {
        $signature = $this->ownedSignature($id, 'delete');

        try {
            DB::beginTransaction();

            $file = $signature->file;

            $signature->verifications()->delete();
            $signature->delete();

            if ($file && $file->signatures()->count() === 0) {
                $path = $file->getRawOriginal('file_path');
                if ($path && Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
                }
                $file->delete();
            } elseif ($file) {
                $file->decrement('signatures_count');
            }

            DB::commit();

            return redirect()->route('signatures.index')
                ->with('success', 'Signature deleted successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Signature delete failed', ['exception' => $e, 'signature_id' => $id]);

            return back()->withErrors([
                'error' => 'Failed to delete signature.',
            ]);
        }
    }

    public function index(Request $request)
    {

        $signatures = Signature::with(['file', 'keyPair', 'signer'])
            ->where('signer_id', auth()->id())
            ->latest('signed_at')
            ->get()
            ->map(function ($signature) {
                return [
                    'id' => $signature->id,
                    'signature_value' => substr($signature->signature_value, 0, 20).'...',
                    'signature_format' => $signature->signature_format,
                    'algorithm_used' => $signature->algorithm_used,
                    'signing_reason' => $signature->signing_reason,
                    'signing_location' => $signature->signing_location,
                    'is_valid' => $signature->is_valid,
                    'validation_status' => $signature->validation_status,
                    'validation_message' => $signature->validation_message,
                    'signed_at' => $signature->signed_at?->format('Y-m-d H:i'),
                    'validated_at' => $signature->validated_at?->format('Y-m-d H:i'),
                    'revoked_at' => $signature->revoked_at?->format('Y-m-d H:i'),
                    'verification_count' => $signature->getVerificationCountAttribute(),
                    'file' => [
                        'id' => $signature->file->id,
                        'original_file_name' => $signature->file->original_file_name,
                        'file_size' => $signature->file->file_size,
                        'mime_type' => $signature->file->mime_type,
                        'file_extension' => $signature->file->file_extension,
                    ],
                    'key_pair' => [
                        'id' => $signature->keyPair->id,
                        'key_name' => $signature->keyPair->key_name,
                        'algorithm_used' => $signature->keyPair->algorithm_used,
                    ],
                    'signer' => [
                        'id' => $signature->signer->id,
                        'name' => $signature->signer->name,
                        'email' => $signature->signer->email,
                    ],
                ];
            });

        return Inertia::render('signatures/Index', [
            'signatures' => $signatures,
        ]);
    }

    public function pending(Request $request)
    {
        try {
            $signatures = Signature::with(['file', 'keyPair', 'signer'])
                ->where('signer_id', auth()->id())
                ->where('validation_status', 'pending') // Au lieu de pendingValidation()
                ->latest('signed_at')
                ->get()
                ->map(fn ($signature) => $this->formatSignature($signature));

            return Inertia::render('signatures/Index', [
                'signatures' => $signatures,
                'type' => 'pending',
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to load pending signatures: '.$e->getMessage());

            return Inertia::render('signatures/Index', [
                'signatures' => [],
                'type' => 'pending',
                'error' => 'Failed to load pending signatures.',
            ]);
        }
    }

    public function revoked(Request $request)
    {
        $signatures = Signature::with(['file', 'keyPair', 'signer'])
            ->where('signer_id', auth()->id())
            ->whereNotNull('revoked_at')
            ->latest('revoked_at')
            ->get()
            ->map(fn ($signature) => $this->formatSignature($signature));

        return Inertia::render('signatures/Index', [
            'signatures' => $signatures,
            'type' => 'revoked',
        ]);
    }

    public function expired(Request $request)
    {
        $signatures = Signature::with(['file', 'keyPair', 'signer'])
            ->where('signer_id', auth()->id())
            ->whereHas('file', fn ($q) => $q->where('expires_at', '<', now()))
            ->latest('signed_at')
            ->get()
            ->map(fn ($signature) => $this->formatSignature($signature));

        return Inertia::render('signatures/Index', [
            'signatures' => $signatures,
            'type' => 'expired',
        ]);
    }

    private function formatSignature($signature)
    {
        return [
            'id' => $signature->id,
            'signature_value' => substr($signature->signature_value, 0, 20).'...',
            'signature_format' => $signature->signature_format,
            'algorithm_used' => $signature->algorithm_used,
            'signing_reason' => $signature->signing_reason,
            'signing_location' => $signature->signing_location,
            'is_valid' => $signature->is_valid,
            'validation_status' => $signature->validation_status,
            'validation_message' => $signature->validation_message,
            'signed_at' => $signature->signed_at?->format('Y-m-d H:i'),
            'validated_at' => $signature->validated_at?->format('Y-m-d H:i'),
            'revoked_at' => $signature->revoked_at?->format('Y-m-d H:i'),
            'verification_count' => $signature->verifications()->count(),
            'file' => [
                'id' => $signature->file->id,
                'original_file_name' => $signature->file->original_file_name,
                'file_size' => $signature->file->file_size,
                'mime_type' => $signature->file->mime_type,
                'file_extension' => $signature->file->file_extension,
            ],
            'key_pair' => [
                'id' => $signature->keyPair->id,
                'key_name' => $signature->keyPair->key_name,
                'algorithm_used' => $signature->keyPair->algorithm_used,
            ],
            'signer' => [
                'id' => $signature->signer->id,
                'name' => $signature->signer->name,
                'email' => $signature->signer->email,
            ],
        ];
    }

    public function create()
    {

        $keys = KeyPair::where('user_id', auth()->id())
            ->active()
            ->valid()
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($key) {
                return [
                    'id' => $key->id,
                    'key_name' => $key->key_name,
                    'algorithm_used' => $key->algorithm_used,
                    'key_size' => $key->key_size,
                    'is_default' => $key->is_default,
                    'fingerprint' => $this->generateFingerprint($key->public_key),
                ];
            });

        return Inertia::render('signatures/Create', [
            'keys' => $keys,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
            'key_pair_id' => 'required|exists:key_pairs,id,user_id,'.auth()->id(),
            'signing_reason' => 'nullable|string|max:500',
            'signing_location' => 'nullable|string|max:255',
            'contact_info' => 'nullable|string|max:255',
            'signature_format' => 'required|in:cms,pdf',
            'include_visual' => 'boolean',
            'expires_in_days' => 'required|integer|min:0|max:3650',
        ]);

        try {
            DB::beginTransaction();

            // Récupérer la clé avec vérification complète
            $keyPair = KeyPair::where('id', $request->key_pair_id)
                ->where('user_id', auth()->id())
                ->where('is_active', true)
                ->where('is_revoked', false)
                ->firstOrFail();

            // Vérification approfondie de la validité de la clé
            if (! $keyPair->isValid()) {
                throw new UserFacingException('The selected key is not valid or has expired.');
            }

            // Vérifier que la clé privée est disponible
            if (empty($keyPair->private_key)) {
                throw new UserFacingException('The private key is not available.');
            }

            // Upload et stockage sécurisé du fichier
            $file = $request->file('file');

            // Générer un nom de fichier unique et sécurisé
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $fileName = time().'_'.uniqid().'.'.$extension;

            // Stocker dans un répertoire utilisateur spécifique
            $userDir = 'signatures/'.auth()->id().'/documents/'.date('Y/m/d');
            $filePath = $file->storeAs($userDir, $fileName, 'local');

            if (! $filePath) {
                throw new UserFacingException('Failed to upload file.');
            }

            // Calculer plusieurs hash pour plus de sécurité
            $hashSha256 = hash_file('sha256', $file->getRealPath());
            $hashSha512 = hash_file('sha512', $file->getRealPath());
            $hashMd5 = hash_file('md5', $file->getRealPath());

            // Créer l'entrée FileSigned
            $fileSigned = FileSigned::create([
                'file_name' => $fileName,
                'original_file_name' => $originalName,
                'file_path' => $filePath,
                'file_hash' => $hashSha256,
                'original_hash' => $hashSha256,
                'hash_algorithm' => 'sha256',
                'uploader_id' => auth()->id(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'file_extension' => $extension,
                'is_encrypted' => false,
                'status' => 'pending_signature',
                'total_signatures_needed' => 1,
                'signatures_count' => 0,
                'expires_at' => (int) $request->expires_in_days > 0
                    ? now()->addDays((int) $request->expires_in_days)
                    : null,
                'retention_period' => $request->expires_in_days,
                'upload_ip' => $request->ip(),
                'upload_user_agent' => $request->userAgent(),
                'metadata' => [
                    'sha512' => $hashSha512,
                    'md5' => $hashMd5,
                    'uploaded_at' => now()->toIso8601String(),
                    'upload_ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            ]);

            // La clé privée est déchiffrée automatiquement par le cast
            // 'encrypted' du modèle KeyPair.
            $privateKey = $keyPair->private_key;

            if (empty($privateKey)) {
                throw new UserFacingException('Failed to decrypt private key.');
            }

            // Générer la signature selon le format choisi
            // Signature CMS détachée (RFC 5652) produite par OpenSSL, certificat inclus.
            $signatureValue = $this->generateSignature(
                $file->getRealPath(),
                $privateKey,
                $keyPair->certificate_data,
            );
            // Préparer les données de signature visuelle si demandé
            $visualData = null;
            if ($request->include_visual && $request->signature_format === 'pdf') {
                $visualData = $this->generateVisualSignature($fileSigned, $request);
            }

            // Créer la signature
            $signature = Signature::create([
                'file_id' => $fileSigned->id,
                'key_pair_id' => $keyPair->id,
                'signer_id' => auth()->id(),
                'signature_value' => $signatureValue,
                'signature_format' => $request->signature_format,
                'algorithm_used' => $keyPair->algorithm_used,
                'signing_reason' => $request->signing_reason,
                'signing_location' => $request->signing_location,
                'contact_info' => $request->contact_info,
                'visual_signature_data' => $visualData,
                'is_valid' => true,
                'validation_status' => 'pending',
                'signed_at' => now(),
                'signing_ip' => $request->ip(),
                'signing_device_info' => $request->userAgent(),
                'nonce_value' => bin2hex(random_bytes(32)), // Pour anti-replay
                'timestamp_token' => $this->timestampToken($signatureValue),
            ]);

            // Stocker la signature détachée (.p7s, CMS encodé en DER)
            $this->storeDetachedSignature($signature, $signatureValue, $fileSigned);

            // Mettre à jour le compteur de signatures
            $fileSigned->addSignatureCount();

            // Mettre à jour la date de dernière utilisation de la clé
            $keyPair->update(['last_used' => now()]);
            $keyPair->increment('use_count');

            // Créer une entrée de vérification initiale
            $this->createInitialVerification($signature, $request);

            DB::commit();

            return redirect()->route('signatures.show', $signature->id)
                ->with('success', 'Document signed successfully.');

        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            return back()->withErrors([
                'key_pair_id' => 'The selected key was not found or does not belong to you.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            // Nettoyer le fichier uploadé en cas d'erreur
            if (isset($filePath) && Storage::disk('local')->exists($filePath)) {
                Storage::disk('local')->delete($filePath);
            }

            // Log l'erreur
            \Log::error('Signature failed: '.$e->getMessage(), [
                'user_id' => auth()->id(),
                'file' => $originalName ?? null,
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->withErrors([
                'error' => 'Failed to sign document: '.UserFacingException::safeMessage($e),
            ]);
        }
    }

    /**
     * Signe le fichier : CMS (RFC 5652) détaché, encodé en PEM.
     *
     * Les formats « cms » et « pdf » produisent le même conteneur : une vraie
     * signature PAdES (incorporée dans le PDF) demanderait une bibliothèque PDF dédiée.
     */
    private function generateSignature(string $filePath, string $privateKey, ?string $certificate): string
    {
        if (empty($certificate)) {
            throw new UserFacingException('Signature generation failed: the selected key has no certificate.');
        }

        try {
            // Si le certificat vient de notre AC, on embarque celui de l'AC : un vérificateur
            // tiers pourra alors valider toute la chaîne (`openssl cms -verify -CAfile ca.crt`).
            $caPath = $this->ca->isIssuedByCa($certificate) ? $this->ca->certificatePath() : null;

            return $this->cms->sign($filePath, $certificate, $privateKey, $caPath);
        } catch (\Exception $e) {
            // Le détail (erreurs OpenSSL) reste dans les logs, pas à l'écran.
            \Log::error('CMS signature generation failed', ['exception' => $e]);

            throw new UserFacingException('Signature generation failed.');
        }
    }

    private function generateFingerprint($publicKey)
    {
        // Nettoyer la clé
        $key = preg_replace('/-----(BEGIN|END) (PUBLIC|PRIVATE) KEY-----/', '', $publicKey);
        $key = preg_replace('/\s+/', '', $key);

        // Calculer le hash SHA-256 et prendre les 16 premiers caractères
        return substr(hash('sha256', $key), 0, 16);
    }

    /**
     * Horodatage de la signature : RFC 3161 auprès de la TSA configurée, sinon (ou si elle
     * est injoignable) horodatage interne. Une TSA en panne ne doit jamais empêcher de signer.
     */
    private function timestampToken(string $signatureValue): string
    {
        if ($this->tsa->enabled()) {
            try {
                // On horodate la signature elle-même (le .p7s en DER).
                return json_encode($this->tsa->timestamp($this->cms->pemToDer($signatureValue)));
            } catch (\Throwable $e) {
                \Log::warning('Timestamp authority unavailable, falling back to the internal timestamp: '.$e->getMessage());
            }
        }

        return $this->generateTimestampToken();
    }

    /**
     * Horodatage interne : heure du serveur, sans tiers de confiance.
     */
    private function generateTimestampToken()
    {
        return json_encode([
            'type' => 'internal',
            'time' => now()->toIso8601String(),
            'nonce' => bin2hex(random_bytes(16)),
            'server' => gethostname(),
        ]);
    }

    /**
     * Stocke la signature détachée
     */
    private function storeDetachedSignature($signature, $signatureValue, $fileSigned)
    {
        $signatureDir = 'signatures/'.auth()->id().'/signatures/'.date('Y/m/d');
        // L'identifiant évite l'écrasement entre deux documents de même nom.
        $signatureFileName = Str::slug(pathinfo($fileSigned->original_file_name, PATHINFO_FILENAME)).'-'.$signature->id.'.p7s';
        $signaturePath = $signatureDir.'/'.$signatureFileName;

        // Fichier .p7s standard : le CMS en DER (et non son armure PEM).
        Storage::disk('local')->put($signaturePath, $this->cms->pemToDer($signatureValue));

        // Mettre à jour la signature avec le chemin
        $signature->update([
            'signature_file_path' => $signaturePath,
        ]);

        return $signaturePath;
    }

    /**
     * Génère une signature visuelle pour PDF
     */
    private function generateVisualSignature($fileSigned, $request)
    {
        $user = auth()->user();

        // Configuration de la signature visuelle
        $visualData = [
            'page' => 1, // Dernière page par défaut
            'x' => 50, // Position X en pixels
            'y' => 50, // Position Y en pixels
            'width' => 200, // Largeur du bloc
            'height' => 80, // Hauteur du bloc
            'name' => $user->name,
            'date' => now()->format('d/m/Y H:i:s'),
            'reason' => $request->signing_reason ?? 'Document approval',
            'location' => $request->signing_location ?? 'Unknown',
            'contact' => $request->contact_info ?? $user->email,
            'certificate_issuer' => 'Self-signed', // À adapter
            'certificate_validity' => 'Valid',
            'signature_image' => null, // Pour ajouter une image de signature plus tard
            'text_style' => [
                'font' => 'Helvetica',
                'size' => 10,
                'color' => [0, 0, 0], // RGB
            ],
            'border' => [
                'width' => 1,
                'color' => [0, 0, 0],
            ],
            'background' => [255, 255, 255], // Blanc
        ];

        return json_encode($visualData);
    }

    /**
     * Crée une vérification initiale de la signature
     */
    private function createInitialVerification($signature, $request)
    {

        $verification = new SignatureVerification([
            'signature_id' => $signature->id,
            'verifier_id' => auth()->id(),
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
            'verifier_ip' => $request->ip(),
            'verifier_user_agent' => $request->userAgent(),
            'verification_duration_ms' => 0,
            'is_public_verification' => false,
            'verified_at' => now(),
        ]);

        $verification->save();

        return $verification;
    }

    /**
     * Récupère une signature et vérifie via la SignaturePolicy que l'utilisateur
     * en est le signataire (404 sinon).
     */
    private function ownedSignature(int|string $id, string $ability = 'view'): Signature
    {
        $signature = Signature::findOrFail($id);
        $this->authorize($ability, $signature);

        return $signature;
    }
}
