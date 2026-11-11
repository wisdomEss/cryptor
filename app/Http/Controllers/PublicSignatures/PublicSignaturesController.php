<?php

namespace App\Http\Controllers\PublicSignatures;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Models\SignatureVerification;
use App\Services\Verification\SignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PublicSignaturesController extends Controller
{
    private const LOCALE = 'fr';

    public function __construct(private readonly SignatureVerifier $verifier) {}

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
                // Recalculé à chaque appel : une clé révoquée depuis apparaît comme telle.
                'certificate_trust' => $this->verifier->certificateTrust($signature->keyPair?->certificate_data),
                'timestamp' => $this->verifier->timestampStatus($signature),
                'signer' => $signature->signer->name ?? 'Inconnu',
                'signed_at' => $signature->signed_at?->toIso8601String(),
                'algorithm' => $signature->algorithm_used,
                'fingerprint' => $this->verifier->formattedFingerprint($signature->keyPair->public_key ?? ''),
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
                'message' => $this->verifier->message((bool) $signature->is_valid, true, true, self::LOCALE),
                'details' => $details,
                'verification_id' => $verification->id,
                'token' => $request->token,
            ]);

        } catch (\Exception $e) {
            \Log::error('Public verification by token failed', ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification : '.UserFacingException::safeMessage($e, UserFacingException::GENERIC_FR),
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

            // Le document existe-t-il dans notre système ?
            $fileSigned = $this->verifier->findSignedFile(hash_file('sha256', $file->getRealPath()));

            $result = $fileSigned
                ? $this->verifyInternalDocument($fileSigned, $file, $signatureFile, $request, $startTime)
                : $this->verifyExternalDocument($file, $signatureFile, $request, $startTime);

            DB::commit();

            return response()->json($result);
        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Public verification by upload failed', ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification : '.UserFacingException::safeMessage($e, UserFacingException::GENERIC_FR),
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

    private function verifyInternalDocument($fileSigned, $file, $signatureFile, Request $request, float $startTime): array
    {
        $r = $this->verifier->verifyInternal(
            $fileSigned, $file, $signatureFile, $request, $startTime,
            verifierId: null,
            isPublic: true,
            withToken: true,
            locale: self::LOCALE,
        );

        $signature = $r['signature'];

        return [
            'success' => $r['success'],
            'message' => $this->verifier->message($r['success'], $r['document_integrity'], $r['signature_valid'], self::LOCALE),
            'details' => [
                'signature_valid' => $r['signature_valid'],
                'document_integrity' => $r['document_integrity'],
                'certificate_trust' => $r['certificate_trust'],
                'timestamp' => $r['timestamp'],
                'signer' => $signature->signer->name ?? 'Inconnu',
                'signed_at' => $signature->signed_at?->toIso8601String(),
                'algorithm' => $signature->algorithm_used,
                'fingerprint' => $this->verifier->formattedFingerprint($signature->keyPair->public_key ?? ''),
                'file_name' => $fileSigned->original_file_name,
            ],
            'verification_id' => $r['verification']->id,
            'token' => $r['verification']->verification_token,
        ];
    }

    private function verifyExternalDocument($file, $signatureFile, Request $request, float $startTime): array
    {
        $r = $this->verifier->verifyExternal(
            $file, $signatureFile, $request, $startTime,
            verifierId: null,
            isPublic: true,
            withToken: true,
            locale: self::LOCALE,
        );

        $data = $r['data'];

        return [
            'success' => $r['success'],
            'message' => $this->verifier->text($r['success'] ? 'external_valid' : 'external_invalid', self::LOCALE),
            'details' => [
                'signature_valid' => $r['signature_valid'],
                'document_integrity' => $r['document_integrity'],
                'certificate_trust' => $r['certificate_trust'],
                'signer' => $data['signer'] ?? 'Signataire externe',
                'signed_at' => $data['timestamp'] ?? null,
                'algorithm' => $data['algorithm'] ?? 'Inconnu',
                'fingerprint' => $this->verifier->formattedFingerprint($r['public_key']),
                'warning' => 'Document externe - non enregistré dans notre système',
            ],
            'verification_id' => $r['verification']->id,
            'token' => $r['verification']->verification_token,
        ];
    }
}
