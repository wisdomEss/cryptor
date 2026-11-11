<?php

namespace App\Http\Controllers\Keys;

use App\Http\Controllers\Controller;
use App\Models\KeyPair;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;

class KeyController extends Controller
{
    public function index()
    {
        $keys = KeyPair::where('user_id', auth()->id())
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
                    'is_active' => $key->is_active,
                    'is_revoked' => $key->is_revoked ?? false,
                    'valid_from' => $key->valid_from?->format('Y-m-d'),
                    'valid_to' => $key->valid_to?->format('Y-m-d'),
                    'created_at' => $key->created_at->diffForHumans(),
                    'fingerprint' => $this->generateFingerprint($key->public_key),
                ];
            });

        return Inertia::render('keys/Index', [
            'keys' => $keys,
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
            'certificate_data' => 'nullable|string',
            'certificate_issuer' => 'nullable|string|max:255',
        ]);

        // Génération des clés selon l'algorithme choisi
        $keyPair = $this->generateKeyPair(
            $validated['algorithm'],
            $validated['key_size'] ?? 2048
        );
        // en principe si l'utilisateur ne fournie pas le certificat on cree mais ce n'est pas encore gerer
        $certData = $this->generateCertificate(
            $keyPair['private_key'],
            $validated['key_name'],
            $validated['valid_days'] ?? 30
        );

        $certificate = $certData['certificate'];
        $issuer = $certData['issuer'];

        // Générer un identifiant unique pour la clé
        $keyIdentifier = Str::uuid()->toString();

        // Déterminer les dates de validité
        $validFrom = Carbon::now();
        $validTo = isset($validated['valid_days'])
            ? Carbon::now()->addDays((int) $validated['valid_days'])
            : null;

        // Si set_as_default est true, enlever le default des autres clés
        if ($validated['set_as_default'] ?? false) {
            KeyPair::where('user_id', Auth::id())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        // Créer la clé (la clé privée est chiffrée automatiquement par le cast
        // 'encrypted' du modèle KeyPair, private_key_iv n'est plus utilisé).
        $key = KeyPair::create([
            'user_id' => Auth::id(),
            'key_name' => $validated['key_name'],
            'public_key' => $keyPair['public_key'],
            'private_key' => $keyPair['private_key'],
            'algorithm_used' => $validated['algorithm'],
            'key_size' => $keyPair['key_size'] ?? $validated['key_size'] ?? 2048,
            'key_identifier' => $keyIdentifier,
            'is_default' => $validated['set_as_default'] ?? false,
            'is_active' => true,
            'is_revoked' => false,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'certificate_data' => $certificate ?? null,
            'certificate_issuer' => $issuer ?? null,
            'created_by_ip' => $request->ip(),
            'created_by_user_agent' => $request->userAgent(),
        ]);

        return redirect()->route('keys.index')
            ->with('success', 'Key pair generated successfully.');
    }

    private function generateKeyPair(string $algorithm, int $keySize = 2048): array
    {
        switch ($algorithm) {
            case 'rsa':
                // Génération RSA
                $config = [
                    'digest_alg' => 'sha256',
                    'private_key_bits' => $keySize,
                    'private_key_type' => OPENSSL_KEYTYPE_RSA,
                ];

                $resource = openssl_pkey_new($config);
                openssl_pkey_export($resource, $privateKey);
                $publicKey = openssl_pkey_get_details($resource)['key'];

                return [
                    'public_key' => $publicKey,
                    'private_key' => $privateKey,
                    'key_size' => $keySize,
                ];

            case 'ecdsa':
                // Génération ECDSA (courbe elliptique)
                $config = [
                    'digest_alg' => 'sha256',
                    'private_key_type' => OPENSSL_KEYTYPE_EC,
                    'curve_name' => 'prime256v1',
                ];

                $resource = openssl_pkey_new($config);
                openssl_pkey_export($resource, $privateKey);
                $publicKey = openssl_pkey_get_details($resource)['key'];

                return [
                    'public_key' => $publicKey,
                    'private_key' => $privateKey,
                    'key_size' => 256,
                ];

            default:
                throw new \Exception('Unsupported algorithm');
        }
    }

    private function generateFingerprint(string $publicKey): string
    {
        // Génère une empreinte SHA-256 de la clé publique et prend les 8 premiers octets
        $hash = hash('sha256', $publicKey);

        return implode(':', str_split(substr($hash, 0, 16), 2));
    }

    private function generateCertificate(string $privateKeyPem, string $commonName, int $validDays = 30): array
    {

        // DN (Distinguished Name)
        $dn = [
            'countryName' => 'MA',
            'stateOrProvinceName' => 'Souss-Massa',
            'localityName' => 'Taroudant',
            'organizationName' => 'CRYPTOR',
            'organizationalUnitName' => 'PKI Department',
            'commonName' => $commonName,
            'emailAddress' => Auth::user()->email ?? 'cryptor@gmail.com',
        ];

        // Charger la clé privée
        $privateKey = openssl_pkey_get_private($privateKeyPem);

        if (! $privateKey) {
            throw new \Exception('Invalid private key provided.');
        }

        // Générer la CSR (Certificate Signing Request)
        $csr = openssl_csr_new(
            $dn,
            $privateKey,
            [
                'digest_alg' => 'sha256',
            ]
        );

        if (! $csr) {
            throw new \Exception('Failed to generate CSR.');
        }

        // Auto-signature du certificat
        $x509 = openssl_csr_sign(
            $csr,
            null,          // null = auto-signé
            $privateKey,
            $validDays
        );

        if (! $x509) {
            throw new \Exception('Failed to sign certificate.');
        }

        // Exporter certificat en PEM
        openssl_x509_export($x509, $certificatePem);

        return [
            'certificate' => $certificatePem,
            'issuer' => 'CRYPTOR Internal CA',
            'valid_from' => now(),
            'valid_to' => now()->addDays($validDays),
        ];
    }

    public function revoked()
    {
        $keys = KeyPair::where('user_id', auth()->id())
            ->where('is_revoked', true)
            ->orderBy('revoked_at', 'desc')
            ->get()
            ->map(function ($key) {
                return [
                    'id' => $key->id,
                    'key_name' => $key->key_name,
                    'algorithm_used' => $key->algorithm_used,
                    'key_size' => $key->key_size,
                    'revoked_at' => $key->revoked_at?->format('Y-m-d H:i'),
                    'fingerprint' => $this->generateFingerprint($key->public_key),
                ];
            });

        return Inertia::render('keys/Revoke', [
            'keys' => $keys,
        ]);
    }

    /**
     * Affiche les détails d'une clé spécifique
     */
    public function show($id)
    {

        $key = $this->ownedKey($id, 'view');

        return Inertia::render('keys/Show', [
            'keyData' => [
                'id' => $key->id,
                'key_name' => $key->key_name,
                'algorithm_used' => $key->algorithm_used,
                'key_size' => $key->key_size,
                'key_identifier' => $key->key_identifier,
                'is_default' => $key->is_default,
                'is_active' => $key->is_active,
                'is_revoked' => $key->is_revoked ?? false,
                'public_key' => $key->public_key,
                'valid_from' => $key->valid_from?->format('Y-m-d H:i:s'),
                'valid_to' => $key->valid_to?->format('Y-m-d H:i:s'),
                'created_at' => $key->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $key->updated_at->format('Y-m-d H:i:s'),
                'last_used' => $key->last_used?->format('Y-m-d H:i:s'),
                'revoked_at' => $key->revoked_at?->format('Y-m-d H:i:s'),
                'revoked_reason' => $key->revocation_reason,
                'certificate_data' => $key->certificate_data,
                'certificate_issuer' => $key->certificate_issuer,
                'fingerprint' => $this->generateFingerprint($key->public_key),
                'signatures_count' => $key->signatures()->count(),
            ],
        ]);
    }

    /**
     * Affiche le formulaire d'édition d'une clé
     */
    public function edit($id)
    {
        $key = $this->ownedKey($id, 'view');

        return Inertia::render('keys/Edit', [
            'keyData' => [
                'id' => $key->id,
                'key_name' => $key->key_name,
                'algorithm_used' => $key->algorithm_used,
                'key_size' => $key->key_size,
                'is_default' => $key->is_default,
                'is_active' => $key->is_active,
                'is_revoked' => $key->is_revoked ?? false,
                'valid_to' => $key->valid_to?->format('Y-m-d'),
                'certificate_issuer' => $key->certificate_issuer,
                'certificate_data' => $key->certificate_data,
            ],
        ]);
    }

    /**
     * Met à jour une clé
     */
    public function update(Request $request, $id)
    {
        $key = $this->ownedKey($id, 'update');

        $validated = $request->validate([
            'key_name' => 'sometimes|string|max:255',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'valid_to' => 'nullable|date|after:today',
            'certificate_issuer' => 'nullable|string|max:255',
            'certificate_data' => 'nullable|string',
        ]);

        // Si cette clé devient la clé par défaut
        if (isset($validated['is_default']) && $validated['is_default'] && ! $key->is_default) {
            KeyPair::where('user_id', Auth::id())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        // Si on désactive la clé par défaut
        if (isset($validated['is_default']) && ! $validated['is_default'] && $key->is_default) {
            // Vérifier s'il existe une autre clé pour devenir défaut
            $otherKey = KeyPair::where('user_id', Auth::id())
                ->where('id', '!=', $id)
                ->where('is_active', true)
                ->where('is_revoked', false)
                ->first();

            if ($otherKey) {
                $otherKey->update(['is_default' => true]);
            }
        }

        $key->update($validated);

        return redirect()->route('keys.show', $id)
            ->with('success', 'Key updated successfully.');
    }

    /**
     * Supprime une clé
     */
    public function destroy($id)
    {
        $key = $this->ownedKey($id, 'delete');

        // Empêcher la suppression si c'est la clé par défaut et qu'il y a d'autres clés
        if ($key->is_default) {
            $otherKeysCount = KeyPair::where('user_id', Auth::id())
                ->where('id', '!=', $id)
                ->count();

            if ($otherKeysCount > 0) {
                return redirect()->back()
                    ->with('error', 'Cannot delete default key. Set another key as default first.');
            }
        }

        // Supprimer les signatures associées ? Ou les garder avec une référence null ?
        // $key->signatures()->delete();

        $key->delete();

        return redirect()->route('keys.index')
            ->with('success', 'Key deleted successfully.');
    }

    /**
     * Télécharge la clé publique
     */
    public function download($id)
    {
        $key = $this->ownedKey($id, 'view');

        $filename = Str::slug($key->key_name).'.pub';
        $headers = [
            'Content-Type' => 'application/x-pem-file',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return response($key->public_key, 200, $headers);
    }

    /**
     * Télécharge la clé privée (nécessite mot de passe)
     */
    public function downloadPrivate($id, Request $request)
    {
        $key = $this->ownedKey($id, 'view');

        // Vérifier le mot de passe
        $request->validate([
            'password' => 'required|current_password',
        ]);

        $filename = Str::slug($key->key_name).'.key';
        $headers = [
            'Content-Type' => 'application/x-pem-file',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return response($key->private_key, 200, $headers);
    }

    /**
     * Révoque une clé
     */
    public function revoke(Request $request, $id)
    {
        $key = $this->ownedKey($id, 'update');

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        // Empêcher la révocation de la clé par défaut
        if ($key->is_default) {
            return redirect()->back()
                ->with('error', 'Cannot revoke default key. Set another key as default first.');
        }

        $key->update([
            'is_revoked' => true,
            'is_active' => false,
            'is_default' => false,
            'revoked_at' => Carbon::now(),
            'revocation_reason' => $validated['reason'] ?? null,
        ]);

        return redirect()->route('keys.show', $id)
            ->with('success', 'Key revoked successfully.');
    }

    /**
     * Active une clé
     */
    public function activate($id)
    {
        $key = $this->ownedKey($id, 'update');

        if ($key->is_revoked) {
            return redirect()->back()
                ->with('error', 'Cannot activate a revoked key.');
        }

        $key->update([
            'is_active' => true,
        ]);

        return redirect()->route('keys.show', $id)
            ->with('success', 'Key activated successfully.');
    }

    /**
     * Désactive une clé
     */
    public function deactivate($id)
    {
        $key = $this->ownedKey($id, 'update');

        // Empêcher la désactivation de la clé par défaut
        if ($key->is_default) {
            return redirect()->back()
                ->with('error', 'Cannot deactivate default key. Set another key as default first.');
        }

        $key->update([
            'is_active' => false,
        ]);

        return redirect()->route('keys.show', $id)
            ->with('success', 'Key deactivated successfully.');
    }

    /**
     * Définit comme clé par défaut
     */
    public function setDefault($id)
    {
        $key = $this->ownedKey($id, 'update');

        if ($key->is_revoked || ! $key->is_active) {
            return redirect()->back()
                ->with('error', 'Cannot set revoked or inactive key as default.');
        }

        // Enlever le default des autres clés
        KeyPair::where('user_id', Auth::id())
            ->where('is_default', true)
            ->update(['is_default' => false]);

        // Définir celle-ci comme défaut
        $key->update(['is_default' => true]);

        return redirect()->route('keys.show', $id)
            ->with('success', 'Key set as default successfully.');
    }

    /**
     * Exporte la clé publique au format PEM
     */
    public function exportPem($id)
    {
        $key = $this->ownedKey($id, 'view');

        return response($key->public_key)
            ->header('Content-Type', 'application/x-pem-file')
            ->header('Content-Disposition', 'attachment; filename="'.Str::slug($key->key_name).'.pem"');
    }

    /**
     * Exporte la clé publique au format SSH
     */
    public function exportSsh($id)
    {
        $key = $this->ownedKey($id, 'view');

        // Convertir PEM en SSH (si RSA)
        if ($key->algorithm_used === 'rsa') {
            $sshKey = $this->pemToSsh($key->public_key, $key->key_name);

            return response($sshKey)
                ->header('Content-Type', 'text/plain')
                ->header('Content-Disposition', 'attachment; filename="'.Str::slug($key->key_name).'.pub"');
        }

        return redirect()->back()->with('error', 'SSH export only available for RSA keys.');
    }

    /**
     * Convertit une clé publique PEM en format SSH
     */
    private function pemToSsh(string $pem, string $comment): string
    {
        $key = openssl_pkey_get_public($pem);
        $details = openssl_pkey_get_details($key);

        if ($details['type'] === OPENSSL_KEYTYPE_RSA) {
            $buffer = pack('N', 7).'ssh-rsa'.
                pack('N', strlen($details['rsa']['e'])).$details['rsa']['e'].
                pack('N', strlen($details['rsa']['n'])).$details['rsa']['n'];

            return 'ssh-rsa '.base64_encode($buffer).' '.$comment;
        }

        throw new \Exception('Unsupported key type for SSH export');
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
