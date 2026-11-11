<?php

namespace App\Http\Controllers\Keys;

use App\Http\Controllers\Controller;
use App\Models\KeyPair;
use App\Services\Crypto\CertificateAuthority;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        ]);

        // Génération des clés selon l'algorithme choisi
        $keyPair = $this->generateKeyPair(
            $validated['algorithm'],
            $validated['key_size'] ?? 2048
        );
        // Certificat X.509 émis par l'autorité de certification interne (créée au besoin).
        $certData = $this->generateCertificate(
            $keyPair['private_key'],
            $validated['key_name'],
            (int) ($validated['valid_days'] ?? config('cryptor.certificate.default_days'))
        );

        $certificate = $certData['certificate'];
        $issuer = $certData['issuer'];

        // Générer un identifiant unique pour la clé
        $keyIdentifier = Str::uuid()->toString();

        // La validité de la clé est celle de son certificat.
        $validFrom = $certData['valid_from'];
        $validTo = $certData['valid_to'];

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

    private function generateCertificate(string $privateKeyPem, string $commonName, int $validDays): array
    {
        $ca = app(CertificateAuthority::class);

        return $ca->issue($privateKeyPem, $ca->subjectFor($commonName, Auth::user()?->email), $validDays);
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

        // Le certificat et son émetteur ne se modifient pas : ils sont émis par l'AC.
        $validated = $request->validate([
            'key_name' => 'sometimes|string|max:255',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'valid_to' => 'nullable|date|after:today',
        ]);

        if ($key->is_revoked) {
            return redirect()->back()->with('error', 'A revoked key cannot be modified.');
        }

        // La validité d'une clé ne peut pas dépasser celle de son certificat.
        $certificateEnd = $key->certificate_data
            ? (@openssl_x509_parse($key->certificate_data)['validTo_time_t'] ?? null)
            : null;

        if ($certificateEnd && array_key_exists('valid_to', $validated)) {
            if (empty($validated['valid_to'])) {
                $validated['valid_to'] = Carbon::createFromTimestamp($certificateEnd);
            } elseif (Carbon::parse($validated['valid_to'])->timestamp > $certificateEnd) {
                throw ValidationException::withMessages([
                    'valid_to' => 'The key cannot be valid beyond its certificate ('.Carbon::createFromTimestamp($certificateEnd)->toDateString().').',
                ]);
            }
        }

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
     * Sauvegarde chiffrée d'une clé : fichier PKCS#12 (.p12) protégé par une phrase secrète,
     * contenant la clé privée, son certificat et celui de l'AC. Format standard, importable
     * ailleurs (navigateur, système, openssl) ou dans CRYPTOR (keys.import).
     */
    public function exportBackup(Request $request, $id)
    {
        $key = $this->ownedKey($id, 'view');

        $request->validate([
            'password' => 'required|current_password',
            'passphrase' => 'required|string|min:12|max:255|confirmed',
        ]);

        if (! $key->certificate_data) {
            throw ValidationException::withMessages(['password' => 'This key has no certificate to export.']);
        }

        $ca = app(CertificateAuthority::class);

        $ok = @openssl_pkcs12_export($key->certificate_data, $p12, $key->private_key, $request->passphrase, [
            'extracerts' => $ca->isIssuedByCa($key->certificate_data) ? [$ca->certificate()] : [],
            'friendly_name' => $key->key_name,
        ]);

        if (! $ok) {
            throw ValidationException::withMessages(['password' => 'The backup could not be generated.']);
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

    /**
     * Importe une clé depuis un fichier PKCS#12 (sauvegarde CRYPTOR ou clé externe).
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:64', // Ko : un .p12 de clé + certificats est minuscule
            'passphrase' => 'required|string|max:255',
            'key_name' => 'required|string|max:255',
        ]);

        $bundle = [];
        $read = @openssl_pkcs12_read((string) file_get_contents($request->file('file')->getRealPath()), $bundle, $request->passphrase);
        while (openssl_error_string() !== false); // vide la file d'erreurs OpenSSL

        if (! $read || empty($bundle['pkey']) || empty($bundle['cert'])) {
            throw ValidationException::withMessages([
                'passphrase' => 'Unable to read this PKCS#12 file: wrong passphrase or unsupported format.',
            ]);
        }

        $private = openssl_pkey_get_private($bundle['pkey']);
        $details = $private ? openssl_pkey_get_details($private) : null;

        if (! $details || ! openssl_x509_check_private_key($bundle['cert'], $bundle['pkey'])) {
            throw ValidationException::withMessages(['file' => 'The private key does not match the certificate.']);
        }

        if ($details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 2048) {
            $algorithm = 'rsa';
        } elseif ($details['type'] === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? null) === 'prime256v1') {
            $algorithm = 'ecdsa';
        } else {
            throw ValidationException::withMessages(['file' => 'Unsupported key (RSA 2048+ or ECDSA P-256 only).']);
        }

        $cert = openssl_x509_parse($bundle['cert']);

        if (now()->timestamp > $cert['validTo_time_t']) {
            throw ValidationException::withMessages(['file' => 'This certificate has expired.']);
        }

        $normalize = fn (string $pem) => preg_replace('/\s+/', '', $pem);
        $duplicate = KeyPair::where('user_id', Auth::id())->pluck('public_key')
            ->contains(fn ($existing) => $normalize($existing) === $normalize($details['key']));

        if ($duplicate) {
            throw ValidationException::withMessages(['file' => 'You already have this key.']);
        }

        openssl_pkey_export($private, $privatePem);

        $key = KeyPair::create([
            'user_id' => Auth::id(),
            'key_name' => $request->key_name,
            'public_key' => $details['key'],
            'private_key' => $privatePem,
            'algorithm_used' => $algorithm,
            'key_size' => $details['bits'],
            'key_identifier' => Str::uuid()->toString(),
            'is_default' => false,
            'is_active' => true,
            'is_revoked' => false,
            'valid_from' => Carbon::createFromTimestamp($cert['validFrom_time_t']),
            'valid_to' => Carbon::createFromTimestamp($cert['validTo_time_t']),
            'certificate_data' => $bundle['cert'],
            'certificate_issuer' => $cert['issuer']['CN'] ?? null,
            'created_by_ip' => $request->ip(),
            'created_by_user_agent' => $request->userAgent(),
        ]);

        return redirect()->route('keys.show', $key->id)
            ->with('success', 'Key imported successfully.');
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
