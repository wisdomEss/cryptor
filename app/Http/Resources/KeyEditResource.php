<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Formulaire d'édition : ni certificat ni clé, seulement ce qui est modifiable. @mixin \App\Models\KeyPair */
class KeyEditResource extends JsonResource
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
            'valid_to' => $this->valid_to?->format('Y-m-d'),
            'certificate_issuer' => $this->certificate_issuer,
        ];
    }
}
