<?php

namespace App\Http\Resources;

use App\Services\Keys\KeyFingerprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Une clé dans la liste « Mes clés ». @mixin \App\Models\KeyPair */
class KeyListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key_name' => $this->key_name,
            'algorithm_used' => $this->algorithm_used,
            'key_size' => $this->key_size,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'is_revoked' => $this->is_revoked ?? false,
            'valid_from' => $this->valid_from?->format('Y-m-d'),
            'valid_to' => $this->valid_to?->format('Y-m-d'),
            'created_at' => $this->created_at->diffForHumans(),
            'fingerprint' => KeyFingerprint::short($this->public_key),
        ];
    }
}
