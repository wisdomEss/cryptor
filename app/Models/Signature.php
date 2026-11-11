<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Signature extends Model
{
    use HasFactory;

    protected $table = 'signatures';

    protected $fillable = [
        'file_id',
        'key_pair_id',
        'signer_id',
        'signature_value',
        'signature_file_path',
        'signature_format',
        'algorithm_used',
        'signing_reason',
        'signing_location',
        'contact_info',
        'visual_signature_data',
        'is_valid',
        'validation_status',
        'validation_message',
        'nonce_value',
        'timestamp_token',
        'signing_ip',
        'signing_device_info',
        'validated_at',
        'revoked_at',
        'revocation_reason',
    ];

    protected $casts = [
        'is_valid' => 'boolean',
        'signed_at' => 'datetime',
        'validated_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $appends = ['verification_count'];

    // Relations
    public function file()
    {
        return $this->belongsTo(FileSigned::class, 'file_id');
    }

    public function keyPair()
    {
        return $this->belongsTo(KeyPair::class);
    }

    public function signer()
    {
        return $this->belongsTo(User::class, 'signer_id');
    }

    public function verifications()
    {
        return $this->hasMany(SignatureVerification::class);
    }

    public function latestVerification()
    {
        return $this->hasOne(SignatureVerification::class)->latest();
    }

    // Accessors
    public function getVerificationCountAttribute(): int
    {
        return $this->verifications()->count();
    }

    // Scopes
    public function scopeValid($query)
    {
        return $query->where('is_valid', true)
            ->where('validation_status', 'valid');
    }

    public function scopeBySigner($query, $userId)
    {
        return $query->where('signer_id', $userId);
    }

    public function scopeByFile($query, $fileId)
    {
        return $query->where('file_id', $fileId);
    }

    public function scopePendingValidation($query)
    {
        return $query->where('validation_status', 'pending');
    }

    // Méthodes
    public function verify(): bool
    {
        // Logique de vérification de la signature
        // À implémenter selon votre algorithme
        return $this->is_valid;
    }

    public function markAsValid(?string $message = null): void
    {
        $this->update([
            'is_valid' => true,
            'validation_status' => 'valid',
            'validation_message' => $message,
            'validated_at' => now(),
        ]);
    }

    public function markAsInvalid(string $message): void
    {
        $this->update([
            'is_valid' => false,
            'validation_status' => 'invalid',
            'validation_message' => $message,
            'validated_at' => now(),
        ]);
    }
}
