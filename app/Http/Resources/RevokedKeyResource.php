<?php

namespace App\Http\Resources;

use App\Services\Keys\KeyFingerprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Une clé dans la liste des clés révoquées. @mixin \App\Models\KeyPair */
class RevokedKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key_name' => $this->key_name,
            'algorithm_used' => $this->algorithm_used,
            'key_size' => $this->key_size,
            'revoked_at' => $this->revoked_at?->format('Y-m-d H:i'),
            'fingerprint' => KeyFingerprint::short($this->public_key),
        ];
    }
}
