<?php

namespace App\Services\Keys;

use App\Exceptions\UserFacingException;
use App\Models\KeyPair;
use App\Models\User;
use App\Services\Crypto\CertificateAuthority;
use App\Support\ClientContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cycle de vie des paires de clés d'un utilisateur et règles qui l'encadrent.
 *
 * Une règle violée lève une UserFacingException (message affichable, `field` si elle
 * concerne un champ de formulaire). L'autorisation d'accès à la clé relève de la
 * KeyPairPolicy, appliquée par le contrôleur : ici on suppose la clé déjà autorisée.
 */
class KeyManager
{
    public function __construct(
        private readonly KeyGenerator $generator,
        private readonly CertificateAuthority $authority,
    ) {}

    /**
     * Génère une paire de clés et son certificat (émis par l'AC interne).
     *
     * @param  array{key_name: string, algorithm: string, key_size?: int|string|null, valid_days?: int|string|null, set_as_default?: bool}  $data
     */
    public function create(User $user, array $data, ClientContext $client): KeyPair
    {
        $pair = $this->generator->generate($data['algorithm'], (int) ($data['key_size'] ?? 2048));

        $certificate = $this->authority->issue(
            $pair['private_key'],
            $this->authority->subjectFor($data['key_name'], $user->email),
            (int) ($data['valid_days'] ?? config('cryptor.certificate.default_days')),
        );

        return DB::transaction(function () use ($user, $data, $client, $pair, $certificate) {
            $setDefault = (bool) ($data['set_as_default'] ?? false);

            if ($setDefault) {
                $this->clearDefault($user->id);
            }

            // La clé privée est chiffrée par le cast `encrypted` du modèle ; la validité
            // de la clé est celle de son certificat.
            return KeyPair::create([
                'user_id' => $user->id,
                'key_name' => $data['key_name'],
                'public_key' => $pair['public_key'],
                'private_key' => $pair['private_key'],
                'algorithm_used' => $data['algorithm'],
                'key_size' => $pair['key_size'],
                'key_identifier' => Str::uuid()->toString(),
                'is_default' => $setDefault,
                'is_active' => true,
                'is_revoked' => false,
                'valid_from' => $certificate['valid_from'],
                'valid_to' => $certificate['valid_to'],
                'certificate_data' => $certificate['certificate'],
                'certificate_issuer' => $certificate['issuer'],
                'created_by_ip' => $client->ip,
                'created_by_user_agent' => $client->userAgent,
            ]);
        });
    }

    /**
     * Met à jour le nom, la clé par défaut, l'activation et la date de fin.
     * Le certificat et son émetteur ne sont jamais modifiables.
     *
     * @param  array{key_name?: string, is_default?: bool, is_active?: bool, valid_to?: ?string}  $data
     */
    public function update(KeyPair $key, array $data): KeyPair
    {
        if ($key->is_revoked) {
            throw new UserFacingException('A revoked key cannot be modified.');
        }

        // La validité d'une clé ne peut pas dépasser celle de son certificat.
        $certificateEnd = $key->certificate_data
            ? (@openssl_x509_parse($key->certificate_data)['validTo_time_t'] ?? null)
            : null;

        if ($certificateEnd && array_key_exists('valid_to', $data)) {
            if (empty($data['valid_to'])) {
                $data['valid_to'] = Carbon::createFromTimestamp($certificateEnd);
            } elseif (Carbon::parse($data['valid_to'])->timestamp > $certificateEnd) {
                throw new UserFacingException(
                    'The key cannot be valid beyond its certificate ('.Carbon::createFromTimestamp($certificateEnd)->toDateString().').',
                    'valid_to'
                );
            }
        }

        return DB::transaction(function () use ($key, $data) {
            if (isset($data['is_default']) && $data['is_default'] && ! $key->is_default) {
                $this->clearDefault($key->user_id);
            }

            // On retire la clé par défaut : une autre clé utilisable prend le relais.
            if (isset($data['is_default']) && ! $data['is_default'] && $key->is_default) {
                KeyPair::where('user_id', $key->user_id)
                    ->where('id', '!=', $key->id)
                    ->where('is_active', true)
                    ->where('is_revoked', false)
                    ->first()
                    ?->update(['is_default' => true]);
            }

            $key->update($data);

            return $key;
        });
    }

    public function activate(KeyPair $key): void
    {
        if ($key->is_revoked) {
            throw new UserFacingException('Cannot activate a revoked key.');
        }

        $key->update(['is_active' => true]);
    }

    public function deactivate(KeyPair $key): void
    {
        if ($key->is_default) {
            throw new UserFacingException('Cannot deactivate default key. Set another key as default first.');
        }

        $key->update(['is_active' => false]);
    }

    public function setDefault(KeyPair $key): void
    {
        if ($key->is_revoked || ! $key->is_active) {
            throw new UserFacingException('Cannot set revoked or inactive key as default.');
        }

        DB::transaction(function () use ($key) {
            $this->clearDefault($key->user_id);
            $key->update(['is_default' => true]);
        });
    }

    /** Révocation définitive : la clé n'est plus utilisable et son certificat est publié comme révoqué. */
    public function revoke(KeyPair $key, ?string $reason = null): void
    {
        if ($key->is_default) {
            throw new UserFacingException('Cannot revoke default key. Set another key as default first.');
        }

        $key->update([
            'is_revoked' => true,
            'is_active' => false,
            'is_default' => false,
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ]);
    }

    public function delete(KeyPair $key): void
    {
        if ($key->is_default && KeyPair::where('user_id', $key->user_id)->where('id', '!=', $key->id)->exists()) {
            throw new UserFacingException('Cannot delete default key. Set another key as default first.');
        }

        $key->delete();
    }

    private function clearDefault(int $userId): void
    {
        KeyPair::where('user_id', $userId)->where('is_default', true)->update(['is_default' => false]);
    }
}
