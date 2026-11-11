<?php

namespace App\Services\Signing;

use App\Models\Signature;
use App\Models\SignatureVerification;
use App\Models\User;
use App\Support\ClientContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Cycle de vie d'une signature existante : validation, révocation, suppression.
 * L'accès (le signataire seul) est contrôlé par la SignaturePolicy, côté contrôleur.
 */
class SignatureManager
{
    public function validate(Signature $signature, User $by, ?string $notes, ClientContext $client): void
    {
        DB::transaction(function () use ($signature, $by, $notes, $client) {
            $signature->update([
                'validation_status' => 'valid',
                'is_valid' => true,
                'validated_at' => now(),
                'validation_message' => $notes ?? 'Signature validated successfully',
            ]);

            $this->journal($signature, $by, $client, true, [
                'action' => 'validation',
                'notes' => $notes,
                'validated_by' => $by->name,
                'validated_at' => now()->toIso8601String(),
            ]);
        });
    }

    public function revoke(Signature $signature, User $by, string $reason, ClientContext $client): void
    {
        DB::transaction(function () use ($signature, $by, $reason, $client) {
            $signature->update([
                'validation_status' => 'revoked',
                'is_valid' => false,
                'revoked_at' => now(),
                'revocation_reason' => $reason,
                'validation_message' => 'Signature revoked: '.$reason,
            ]);

            $this->journal($signature, $by, $client, false, [
                'action' => 'revocation',
                'reason' => $reason,
                'revoked_by' => $by->name,
                'revoked_at' => now()->toIso8601String(),
            ], 'Signature revoked: '.$reason);
        });
    }

    /**
     * Supprime la signature et, si c'était la dernière sur le document, le document lui-même.
     * Les fichiers sont effacés APRÈS la validation de la transaction : si elle échoue,
     * rien n'a été détruit.
     */
    public function delete(Signature $signature): void
    {
        $filesToRemove = [];

        DB::transaction(function () use ($signature, &$filesToRemove) {
            $file = $signature->file;

            if ($signature->signature_file_path) {
                $filesToRemove[] = $signature->signature_file_path;
            }

            $signature->verifications()->delete();
            $signature->delete();

            if ($file && $file->signatures()->count() === 0) {
                if ($path = $file->getRawOriginal('file_path')) {
                    $filesToRemove[] = $path;
                }
                $file->delete();
            } elseif ($file) {
                $file->decrement('signatures_count');
            }
        });

        foreach ($filesToRemove as $path) {
            Storage::disk('local')->delete($path);
        }
    }

    /** Trace de l'opération dans le journal des vérifications (type « manual »). */
    private function journal(Signature $signature, User $by, ClientContext $client, bool $result, array $details, ?string $error = null): void
    {
        $verification = SignatureVerification::create([
            'signature_id' => $signature->id,
            'verifier_id' => $by->id,
            'verified_hash' => $signature->file->file_hash,
            'expected_hash' => $signature->file->file_hash,
            'result' => $result,
            'verification_type' => 'manual',
            'verification_details' => $details,
            'error_message' => $error,
            'signature_timestamp' => $signature->signed_at,
            'document_timestamp' => $signature->file->created_at,
            'verifier_ip' => $client->ip,
            'verifier_user_agent' => $client->userAgent,
            'verification_duration_ms' => 0,
            'is_public_verification' => false,
            'verified_at' => now(),
        ]);

        $verification->generateVerificationToken();
    }
}
