<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Une signature dans les listes (toutes, en attente, révoquées, expirées). @mixin \App\Models\Signature */
class SignatureListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'signature_value' => substr($this->signature_value, 0, 20).'...',
            'signature_format' => $this->signature_format,
            'algorithm_used' => $this->algorithm_used,
            'signing_reason' => $this->signing_reason,
            'signing_location' => $this->signing_location,
            'is_valid' => $this->is_valid,
            'validation_status' => $this->validation_status,
            'validation_message' => $this->validation_message,
            'signed_at' => $this->signed_at?->format('Y-m-d H:i'),
            'validated_at' => $this->validated_at?->format('Y-m-d H:i'),
            'revoked_at' => $this->revoked_at?->format('Y-m-d H:i'),
            'verification_count' => $this->verifications()->count(),
            'file' => [
                'id' => $this->file->id,
                'original_file_name' => $this->file->original_file_name,
                'file_size' => $this->file->file_size,
                'mime_type' => $this->file->mime_type,
                'file_extension' => $this->file->file_extension,
            ],
            'key_pair' => [
                'id' => $this->keyPair->id,
                'key_name' => $this->keyPair->key_name,
                'algorithm_used' => $this->keyPair->algorithm_used,
            ],
            'signer' => [
                'id' => $this->signer->id,
                'name' => $this->signer->name,
                'email' => $this->signer->email,
            ],
        ];
    }
}
