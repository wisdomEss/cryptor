<?php

namespace App\Http\Resources;

use App\Services\Keys\KeyFingerprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Une clé proposée dans le formulaire de signature. @mixin \App\Models\KeyPair */
class KeyOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key_name' => $this->key_name,
            'algorithm_used' => $this->algorithm_used,
            'key_size' => $this->key_size,
            'is_default' => $this->is_default,
            'fingerprint' => KeyFingerprint::short($this->public_key),
        ];
    }
}
