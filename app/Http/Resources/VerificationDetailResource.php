<?php

namespace App\Http\Resources;

use App\Models\SignatureVerification;
use App\Services\Keys\KeyFingerprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Page de détail d'une vérification.
 *
 * Avant, le contrôleur renvoyait le modèle entier avec ses relations : les objets `User`
 * complets du signataire et du vérificateur partaient au navigateur (rôle, téléphone,
 * préférences, compteur d'échecs de connexion, date de verrouillage), ainsi que le chemin
 * de stockage du document. Ici, seuls les champs affichés sont exposés.
 *
 * @mixin SignatureVerification
 */
class VerificationDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'signature_id' => $this->signature_id,
            'verifier_id' => $this->verifier_id,
            'verified_hash' => $this->verified_hash,
            'expected_hash' => $this->expected_hash,
            'result' => (bool) $this->result,
            'verification_type' => $this->verification_type,
            'verification_details' => $this->verification_details,
            'error_message' => $this->error_message,
            'signature_timestamp' => $this->signature_timestamp?->toIso8601String(),
            'document_timestamp' => $this->document_timestamp?->toIso8601String(),
            'verifier_ip' => $this->verifier_ip,
            'verifier_user_agent' => $this->verifier_user_agent,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verification_duration_ms' => $this->verification_duration_ms,
            'certificate_chain' => $this->certificate_chain,
            'timestamp_authority_used' => $this->timestamp_authority_used,
            'verification_token' => $this->verification_token,
            'is_public_verification' => (bool) $this->is_public_verification,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'signature' => $this->signature ? [
                'id' => $this->signature->id,
                'file_id' => $this->signature->file_id,
                'key_pair_id' => $this->signature->key_pair_id,
                'signer_id' => $this->signature->signer_id,
                'signature_value' => $this->signature->signature_value,
                'signature_format' => $this->signature->signature_format,
                'algorithm_used' => $this->signature->algorithm_used,
                'signing_reason' => $this->signature->signing_reason,
                'signing_location' => $this->signature->signing_location,
                'contact_info' => $this->signature->contact_info,
                'visual_signature_data' => $this->signature->visual_signature_data,
                'is_valid' => (bool) $this->signature->is_valid,
                'validation_status' => $this->signature->validation_status,
                'validation_message' => $this->signature->validation_message,
                'nonce_value' => $this->signature->nonce_value,
                'timestamp_token' => $this->signature->timestamp_token,
                'signing_ip' => $this->signature->signing_ip,
                'signing_device_info' => $this->signature->signing_device_info,
                'signed_at' => $this->signature->signed_at?->toIso8601String(),
                'validated_at' => $this->signature->validated_at?->toIso8601String(),
                'revoked_at' => $this->signature->revoked_at?->toIso8601String(),
                'revocation_reason' => $this->signature->revocation_reason,
                'created_at' => $this->signature->created_at?->toIso8601String(),
                'updated_at' => $this->signature->updated_at?->toIso8601String(),

                'file' => $this->signature->file ? [
                    'id' => $this->signature->file->id,
                    'original_file_name' => $this->signature->file->original_file_name,
                    'file_name' => $this->signature->file->file_name,
                    'file_hash' => $this->signature->file->file_hash,
                    'file_size' => $this->signature->file->file_size,
                    'mime_type' => $this->signature->file->mime_type,
                    'file_extension' => $this->signature->file->file_extension,
                    'uploader_id' => $this->signature->file->uploader_id,
                    'created_at' => $this->signature->file->created_at?->toIso8601String(),
                ] : null,

                'signer' => $this->signature->signer ? [
                    'id' => $this->signature->signer->id,
                    'name' => $this->signature->signer->name,
                    'email' => $this->signature->signer->email,
                ] : null,

                'keyPair' => $this->signature->keyPair ? [
                    'id' => $this->signature->keyPair->id,
                    'key_name' => $this->signature->keyPair->key_name,
                    'algorithm_used' => $this->signature->keyPair->algorithm_used,
                    'key_size' => $this->signature->keyPair->key_size,
                    'public_key' => $this->signature->keyPair->public_key,
                    'fingerprint' => KeyFingerprint::short((string) $this->signature->keyPair->public_key),
                ] : null,
            ] : null,

            'verifier' => $this->verifier ? [
                'id' => $this->verifier->id,
                'name' => $this->verifier->name,
                'email' => $this->verifier->email,
            ] : null,
        ];
    }
}
