<?php

namespace App\Http\Controllers\Signatures;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Http\Resources\KeyOptionResource;
use App\Http\Resources\SignatureDetailResource;
use App\Http\Resources\SignatureListResource;
use App\Models\KeyPair;
use App\Models\Signature;
use App\Services\Signing\DocumentSigner;
use App\Services\Signing\SignatureManager;
use App\Services\Signing\SigningRequest;
use App\Support\ClientContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * Contrôleur mince : validation, autorisation (SignaturePolicy), appel au service, réponse.
 * La signature est dans DocumentSigner, le cycle de vie dans SignatureManager.
 */
class SignatureController extends Controller
{
    public function __construct(
        private readonly DocumentSigner $signer,
        private readonly SignatureManager $signatures,
    ) {}

    // ------------------------------------------------------------- listes

    public function index(Request $request)
    {
        return $this->list(Signature::query());
    }

    public function pending(Request $request)
    {
        return $this->list(Signature::where('validation_status', 'pending'), 'pending');
    }

    public function revoked(Request $request)
    {
        return $this->list(Signature::whereNotNull('revoked_at')->reorder()->latest('revoked_at'), 'revoked');
    }

    public function expired(Request $request)
    {
        return $this->list(Signature::whereHas('file', fn ($q) => $q->where('expires_at', '<', now())), 'expired');
    }

    /** Les signatures de l'utilisateur connecté, éventuellement filtrées. */
    private function list($query, ?string $type = null)
    {
        $signatures = $query
            ->with(['file', 'keyPair', 'signer'])
            ->where('signer_id', auth()->id())
            ->latest('signed_at')
            ->get();

        return Inertia::render('signatures/Index', array_filter([
            'signatures' => SignatureListResource::collection($signatures)->resolve(),
            'type' => $type,
        ], fn ($value) => $value !== null));
    }

    // ------------------------------------------------------------ détail

    public function show(Request $request, $id)
    {
        $signature = Signature::with([
            'file',
            'keyPair',
            'signer',
            'verifications' => fn ($query) => $query->latest('verified_at')->limit(10),
            'verifications.verifier',
        ])->findOrFail($id);

        $this->authorize('view', $signature); // seul le signataire (404 sinon)

        return Inertia::render('signatures/Show', [
            'signature' => SignatureDetailResource::make($signature)->resolve(),
        ]);
    }

    /** Non utilisé : la validation se fait directement depuis la page de détail. */
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

    // ----------------------------------------------------------- signature

    public function create()
    {
        $keys = KeyPair::where('user_id', auth()->id())
            ->active()
            ->valid()
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('signatures/Create', [
            'keys' => KeyOptionResource::collection($keys)->resolve(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10 Mo
            'key_pair_id' => 'required|exists:key_pairs,id,user_id,'.auth()->id(),
            'signing_reason' => 'nullable|string|max:500',
            'signing_location' => 'nullable|string|max:255',
            'contact_info' => 'nullable|string|max:255',
            'signature_format' => 'required|in:cms,pdf',
            'include_visual' => 'boolean',
            'expires_in_days' => 'required|integer|min:0|max:3650',
        ]);

        $key = KeyPair::where('user_id', auth()->id())->findOrFail($request->key_pair_id);

        try {
            $signature = $this->signer->sign(
                $request->user(),
                $key,
                $request->file('file'),
                SigningRequest::fromRequest($request),
                ClientContext::from($request),
            );
        } catch (\Throwable $e) {
            if (! $e instanceof UserFacingException) {
                \Log::error('Signature failed', ['exception' => $e, 'user_id' => auth()->id()]);
            }

            return back()->withErrors(['error' => 'Failed to sign document: '.UserFacingException::safeMessage($e)]);
        }

        return redirect()->route('signatures.show', $signature->id)
            ->with('success', 'Document signed successfully.');
    }

    // ------------------------------------------------- cycle de vie

    /** Valide une signature (le signataire confirme qu'elle est correcte). */
    public function update(Request $request, $id)
    {
        $signature = $this->ownedSignature($id, 'update');

        $request->validate(['validation_notes' => 'nullable|string|max:500']);

        return $this->act('validate', $id, fn () => $this->signatures->validate(
            $signature, $request->user(), $request->validation_notes, ClientContext::from($request)
        ), redirect()->back(), 'Signature validated successfully.');
    }

    public function revoke(Request $request, $id)
    {
        $signature = $this->ownedSignature($id, 'update');

        $request->validate(['revocation_reason' => 'required|string|max:500']);

        return $this->act('revoke', $id, fn () => $this->signatures->revoke(
            $signature, $request->user(), $request->revocation_reason, ClientContext::from($request)
        ), redirect()->back(), 'Signature revoked successfully.');
    }

    /** Supprime une signature et, si c'est la dernière sur le document, le document associé. */
    public function destroy($id)
    {
        $signature = $this->ownedSignature($id, 'delete');

        return $this->act('delete', $id, fn () => $this->signatures->delete($signature),
            redirect()->route('signatures.index'), 'Signature deleted successfully.');
    }

    // ----------------------------------------------------------- helpers

    /**
     * Exécute une opération. Toute erreur est journalisée avec sa trace ; l'utilisateur ne voit
     * que « Failed to <action> signature. » (jamais le détail technique).
     */
    private function act(string $action, int|string $id, callable $operation, $onSuccess, string $success)
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            \Log::error("Signature {$action} failed", ['exception' => $e, 'signature_id' => $id]);

            return back()->withErrors(['error' => "Failed to {$action} signature."]);
        }

        return $onSuccess->with('success', $success);
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
