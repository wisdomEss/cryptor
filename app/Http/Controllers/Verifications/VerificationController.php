<?php

namespace App\Http\Controllers\Verifications;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Models\Signature;
use App\Models\SignatureVerification;
use App\Services\Keys\KeyFingerprint;
use App\Services\Verification\SignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class VerificationController extends Controller
{
    public function __construct(private readonly SignatureVerifier $verifier) {}

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
        return inertia('verifications/Create', [
            'recentSignatures' => $this->getRecentSignatures(),
        ]);
    }

    /**
     * Vérification d'un document, signé dans notre système ou externe.
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

            $fileSigned = $this->verifier->findSignedFile(hash_file('sha256', $file->getRealPath()));

            $result = $fileSigned
                ? $this->verifyInternal($fileSigned, $file, $signatureFile, $request, $startTime)
                : $this->verifyExternal($file, $signatureFile, $request, $startTime);

            DB::commit();

            return Inertia::render('verifications/Create', [
                'recentSignatures' => $this->getRecentSignatures(),
                'verificationResult' => $result,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Verification failed', ['exception' => $e]);

            return Inertia::render('verifications/Create', [
                'recentSignatures' => $this->getRecentSignatures(),
                'verificationResult' => [
                    'success' => false,
                    'message' => 'Verification failed: '.UserFacingException::safeMessage($e),
                    'details' => [],
                ],
            ]);
        }
    }

    private function verifyInternal($fileSigned, $file, $signatureFile, Request $request, float $startTime): array
    {
        $r = $this->verifier->verifyInternal(
            $fileSigned, $file, $signatureFile, $request, $startTime,
            verifierId: auth()->id(),
            isPublic: ! auth()->check(),
            withToken: (bool) $request->include_details,
        );

        $signature = $r['signature'];

        return [
            'success' => $r['success'],
            'message' => $this->verifier->message($r['success'], $r['document_integrity'], $r['signature_valid']),
            'details' => [
                'signature_valid' => $r['signature_valid'],
                'document_integrity' => $r['document_integrity'],
                'certificate_trust' => $r['certificate_trust'],
                'timestamp' => $r['timestamp'],
                'signer' => $signature->signer->name ?? 'Unknown',
                'signed_at' => $signature->signed_at->toIso8601String(),
                'algorithm' => $signature->algorithm_used,
                'fingerprint' => KeyFingerprint::short($signature->keyPair->public_key ?? ''),
            ],
            'id' => $r['verification']->id,
            'token' => $r['verification']->verification_token,
        ];
    }

    private function verifyExternal($file, $signatureFile, Request $request, float $startTime): array
    {
        $r = $this->verifier->verifyExternal(
            $file, $signatureFile, $request, $startTime,
            verifierId: auth()->id(),
            isPublic: ! auth()->check(),
            withToken: false,
        );

        return [
            'success' => $r['success'],
            'message' => $this->verifier->text($r['success'] ? 'external_valid' : 'external_invalid'),
            'details' => [
                'signature_valid' => $r['signature_valid'],
                'document_integrity' => $r['document_integrity'],
                'certificate_trust' => $r['certificate_trust'],
                'external' => true,
            ],
            'id' => $r['verification']->id,
        ];
    }

    /**
     * Signatures récentes de l'utilisateur connecté (signées ou déposées par lui).
     */
    private function getRecentSignatures()
    {
        return Signature::with(['file', 'signer'])
            ->where(function ($q) {
                $q->where('signer_id', Auth::id())
                    ->orWhereHas('file', fn ($f) => $f->where('uploader_id', Auth::id()));
            })
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($signature) => [
                'id' => $signature->id,
                'file_name' => $signature->file->original_file_name,
                'signer_name' => $signature->signer->name,
                'signed_at' => $signature->signed_at->diffForHumans(),
            ]);
    }

    /**
     * Afficher les détails d'une vérification
     */
    public function show($id)
    {
        $verification = SignatureVerification::with([
            'signature.file',
            'signature.signer',
            'signature.keyPair',
            'verifier',
        ])->findOrFail($id);

        // SignatureVerificationPolicy : auteur de la vérification, signataire ou uploader (404 sinon).
        $this->authorize('view', $verification);

        return inertia('verifications/Show', [
            'verification' => $verification,
        ]);
    }
}
