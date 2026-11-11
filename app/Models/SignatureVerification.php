<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SignatureVerification extends Model
{
    use HasFactory;

    protected $table = 'signatures_verifications';

    protected $fillable = [
        'signature_id',
        'verifier_id',
        'verified_hash',
        'expected_hash',
        'result',
        'verification_type',
        'verification_details',
        'error_message',
        'signature_timestamp',
        'document_timestamp',
        'verifier_ip',
        'verifier_user_agent',
        'verification_duration_ms',
        'certificate_chain',
        'timestamp_authority_used',
        'verification_token',
        'is_public_verification',
    ];

    protected $casts = [
        'result' => 'boolean',
        'is_public_verification' => 'boolean',
        'verification_details' => 'array',
        'signature_timestamp' => 'datetime',
        'document_timestamp' => 'datetime',
        'verified_at' => 'datetime',
        'verification_duration_ms' => 'integer',
    ];

    // Relations
    public function signature()
    {
        return $this->belongsTo(Signature::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verifier_id');
    }

    // Scopes
    public function scopeSuccessful($query)
    {
        return $query->where('result', true);
    }

    public function scopeFailed($query)
    {
        return $query->where('result', false);
    }

    public function scopePublic($query)
    {
        return $query->where('is_public_verification', true);
    }

    public function scopeByToken($query, $token)
    {
        return $query->where('verification_token', $token);
    }

    public function scopeRecent($query, $days = 30)
    {
        return $query->where('verified_at', '>=', now()->subDays($days));
    }

    // Accessors
    public function getVerificationTypeLabelAttribute(): string
    {
        return match ($this->verification_type) {
            'hash' => 'Vérification de Hash',
            'signature' => 'Vérification de Signature',
            'certificate' => 'Vérification de Certificat',
            'timestamp' => 'Vérification d\'Horodatage',
            default => 'Inconnu'
        };
    }

    // Méthodes
    public function generateVerificationToken(): string
    {
        if (! $this->verification_token) {
            $this->verification_token = bin2hex(random_bytes(32));
            $this->save();
        }

        return $this->verification_token;
    }

    // public function getVerificationUrl(): string
    // {
    //     return route('signature.verify.public', [
    //         'token' => $this->verification_token
    //     ]);
    // }
}
