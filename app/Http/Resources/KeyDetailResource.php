<?php

namespace App\Http\Resources;

use App\Services\Keys\KeyFingerprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Page de détail d'une clé (la clé privée n'est jamais exposée). @mixin \App\Models\KeyPair */
class KeyDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key_name' => $this->key_name,
            'algorithm_used' => $this->algorithm_used,
            'key_size' => $this->key_size,
            'key_identifier' => $this->key_identifier,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'is_revoked' => $this->is_revoked ?? false,
            'public_key' => $this->public_key,
            'valid_from' => $this->valid_from?->format('Y-m-d H:i:s'),
            'valid_to' => $this->valid_to?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            'last_used' => $this->last_used?->format('Y-m-d H:i:s'),
            'revoked_at' => $this->revoked_at?->format('Y-m-d H:i:s'),
            'revoked_reason' => $this->revocation_reason,
            'certificate_data' => $this->certificate_data,
            'certificate_issuer' => $this->certificate_issuer,
            // Empreinte complète sur la page de détail ; les listes affichent son début.
            'fingerprint' => KeyFingerprint::of($this->public_key),
            'signatures_count' => $this->signatures()->count(),
        ];
    }
}
