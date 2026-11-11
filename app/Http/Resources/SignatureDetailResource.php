<?php

namespace App\Http\Resources;

use App\Services\Keys\KeyFingerprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Page de détail d'une signature. @mixin \App\Models\Signature */
class SignatureDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'signature_value' => $this->signature_value,
            'signature_format' => $this->signature_format,
            'algorithm_used' => $this->algorithm_used,
            'signing_reason' => $this->signing_reason,
            'signing_location' => $this->signing_location,
            'contact_info' => $this->contact_info,
            'visual_signature_data' => $this->visual_signature_data,
            'is_valid' => $this->is_valid,
            'validation_status' => $this->validation_status,
            'validation_message' => $this->validation_message,
            'signed_at' => $this->signed_at?->toIso8601String(),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'signing_ip' => $this->signing_ip,
            'signing_device_info' => $this->signing_device_info,
            'nonce_value' => $this->nonce_value,
            'timestamp_token' => $this->timestamp_token,
            'verification_count' => $this->verifications()->count(),

            'file' => [
                'id' => $this->file->id,
                'original_file_name' => $this->file->original_file_name,
                'file_size' => $this->file->file_size,
                'mime_type' => $this->file->mime_type,
                'file_extension' => $this->file->file_extension,
                'file_hash' => $this->file->file_hash,
                'expires_at' => $this->file->expires_at?->toIso8601String(),
                'created_at' => $this->file->created_at->toIso8601String(),
            ],

            'key_pair' => [
                'id' => $this->keyPair->id,
                'key_name' => $this->keyPair->key_name,
                'algorithm_used' => $this->keyPair->algorithm_used,
                'key_size' => $this->keyPair->key_size,
                'fingerprint' => KeyFingerprint::short($this->keyPair->public_key),
                'public_key' => $this->keyPair->public_key,
            ],

            'signer' => [
                'id' => $this->signer->id,
                'name' => $this->signer->name,
                'email' => $this->signer->email,
            ],

            'verifications' => $this->verifications->map(fn ($verification) => [
                'id' => $verification->id,
                'result' => $verification->result,
                'verified_at' => $verification->verified_at?->toIso8601String(),
                'verification_type' => $verification->verification_type,
                'verifier' => $verification->verifier ? [
                    'name' => $verification->verifier->name,
                    'email' => $verification->verifier->email,
                ] : null,
            ]),
        ];
    }
}
