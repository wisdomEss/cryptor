<?php

namespace App\Http\Controllers\Keys;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Http\Resources\KeyDetailResource;
use App\Http\Resources\KeyEditResource;
use App\Http\Resources\KeyListResource;
use App\Http\Resources\RevokedKeyResource;
use App\Models\KeyPair;
use App\Services\Keys\KeyBackupService;
use App\Services\Keys\KeyManager;
use App\Services\Keys\SshKeyExporter;
use App\Support\ClientContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Contrôleur mince : validation, autorisation (KeyPairPolicy), appel au service, réponse.
 * Les règles métier sont dans KeyManager, la sauvegarde dans KeyBackupService.
 */
class KeyController extends Controller
{
    public function __construct(
        private readonly KeyManager $keys,
        private readonly KeyBackupService $backups,
        private readonly SshKeyExporter $ssh,
    ) {}

    public function index()
    {
        $keys = KeyPair::where('user_id', Auth::id())
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('keys/Index', [
            'keys' => KeyListResource::collection($keys)->resolve(),
        ]);
    }

    public function create()
    {
        return Inertia::render('keys/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'key_name' => 'required|string|max:255',
            'algorithm' => 'required|in:rsa,ecdsa',
            'key_size' => 'required_if:algorithm,rsa|in:2048,4096',
            'valid_days' => 'nullable|integer|min:1|max:3650',
            'set_as_default' => 'boolean',
        ]);

        $this->keys->create($request->user(), $validated, ClientContext::from($request));

        return redirect()->route('keys.index')
            ->with('success', 'Key pair generated successfully.');
    }

    public function revoked()
    {
        $keys = KeyPair::where('user_id', Auth::id())
            ->where('is_revoked', true)
            ->orderBy('revoked_at', 'desc')
            ->get();

        return Inertia::render('keys/Revoke', [
            'keys' => RevokedKeyResource::collection($keys)->resolve(),
        ]);
    }

    public function show($id)
    {
        $key = $this->ownedKey($id, 'view');

        return Inertia::render('keys/Show', [
            'keyData' => KeyDetailResource::make($key)->resolve(),
        ]);
    }

    public function edit($id)
    {
        $key = $this->ownedKey($id, 'update');

        return Inertia::render('keys/Edit', [
            'keyData' => KeyEditResource::make($key)->resolve(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $key = $this->ownedKey($id, 'update');

        // Le certificat et son émetteur ne se modifient pas : ils sont émis par l'AC.
        $validated = $request->validate([
            'key_name' => 'sometimes|string|max:255',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'valid_to' => 'nullable|date|after:today',
        ]);

        return $this->act(fn () => $this->keys->update($key, $validated), route('keys.show', $id), 'Key updated successfully.');
    }

    public function destroy($id)
    {
        $key = $this->ownedKey($id, 'delete');

        return $this->act(fn () => $this->keys->delete($key), route('keys.index'), 'Key deleted successfully.');
    }

    public function revoke(Request $request, $id)
    {
        $key = $this->ownedKey($id, 'update');

        $validated = $request->validate(['reason' => 'nullable|string|max:500']);

        return $this->act(fn () => $this->keys->revoke($key, $validated['reason'] ?? null), route('keys.show', $id), 'Key revoked successfully.');
    }

    public function activate($id)
    {
        $key = $this->ownedKey($id, 'update');

        return $this->act(fn () => $this->keys->activate($key), route('keys.show', $id), 'Key activated successfully.');
    }

    public function deactivate($id)
    {
        $key = $this->ownedKey($id, 'update');

        return $this->act(fn () => $this->keys->deactivate($key), route('keys.show', $id), 'Key deactivated successfully.');
    }

    public function setDefault($id)
    {
        $key = $this->ownedKey($id, 'update');

        return $this->act(fn () => $this->keys->setDefault($key), route('keys.show', $id), 'Key set as default successfully.');
    }

    // ------------------------------------------------------------ exports

    /** Clé publique, au format PEM (.pub). */
    public function download($id)
    {
        $key = $this->ownedKey($id, 'view');

        return $this->textDownload($key->public_key, Str::slug($key->key_name).'.pub', 'application/x-pem-file');
    }

    public function exportPem($id)
    {
        $key = $this->ownedKey($id, 'view');

        return $this->textDownload($key->public_key, Str::slug($key->key_name).'.pem', 'application/x-pem-file');
    }

    public function exportSsh($id)
    {
        $key = $this->ownedKey($id, 'view');

        try {
            $line = $this->ssh->export($key->public_key, $key->key_name);
        } catch (UserFacingException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return $this->textDownload($line, Str::slug($key->key_name).'.pub', 'text/plain');
    }

    /**
     * Sauvegarde chiffrée : fichier PKCS#12 (.p12) protégé par une phrase secrète, importable
     * ailleurs (navigateur, système, openssl) ou dans CRYPTOR (keys.import).
     */
    public function exportBackup(Request $request, $id)
    {
        $key = $this->ownedKey($id, 'view');

        $request->validate([
            'password' => 'required|current_password',
            'passphrase' => 'required|string|min:12|max:255|confirmed',
        ]);

        try {
            $p12 = $this->backups->export($key, $request->passphrase);
        } catch (UserFacingException $e) {
            throw ValidationException::withMessages(['password' => $e->getMessage()]);
        }

        return response($p12, 200, [
            'Content-Type' => 'application/x-pkcs12',
            'Content-Disposition' => 'attachment; filename="'.(Str::slug($key->key_name) ?: 'key').'.p12"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function importForm()
    {
        return Inertia::render('keys/Import');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:64', // Ko : un .p12 de clé + certificats est minuscule
            'passphrase' => 'required|string|max:255',
            'key_name' => 'required|string|max:255',
        ]);

        try {
            $key = $this->backups->import(
                $request->user(),
                (string) file_get_contents($request->file('file')->getRealPath()),
                $request->passphrase,
                $request->key_name,
                ClientContext::from($request),
            );
        } catch (UserFacingException $e) {
            throw ValidationException::withMessages([$e->field ?? 'file' => $e->getMessage()]);
        }

        return redirect()->route('keys.show', $key->id)
            ->with('success', 'Key imported successfully.');
    }

    // ----------------------------------------------------------- helpers

    /**
     * Exécute une action métier. Une règle violée (UserFacingException) devient un message
     * d'erreur, ou une erreur de validation si elle désigne un champ ; sinon, redirection.
     */
    private function act(callable $action, string $redirectTo, string $success)
    {
        try {
            $action();
        } catch (UserFacingException $e) {
            if ($e->field) {
                throw ValidationException::withMessages([$e->field => $e->getMessage()]);
            }

            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect($redirectTo)->with('success', $success);
    }

    private function textDownload(string $content, string $filename, string $type)
    {
        return response($content, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Récupère une clé et vérifie via la KeyPairPolicy que l'utilisateur y a droit
     * (404 sinon, pour ne pas révéler l'existence de la ressource).
     */
    private function ownedKey(int|string $id, string $ability = 'view'): KeyPair
    {
        $key = KeyPair::findOrFail($id);
        $this->authorize($ability, $key);

        return $key;
    }
}
