<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FileSigned extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'files_signeds';

    protected $fillable = [
        'file_name',
        'original_file_name',
        'file_path',
        'file_hash',
        'original_hash',
        'hash_algorithm',
        'uploader_id',
        'file_size',
        'mime_type',
        'file_extension',
        'is_encrypted',
        'encryption_algorithm',
        'status',
        'total_signatures_needed',
        'expires_at',
        'retention_period'
    ];

    protected $casts = [
        'is_encrypted' => 'boolean',
        'file_size' => 'integer',
        'total_signatures_needed' => 'integer',
        'signatures_count' => 'integer',
        'retention_period' => 'integer',
        'expires_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    protected $appends = ['is_expired', 'is_fully_signed'];

    // Relations
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class, 'file_id');
    }

    public function validSignatures()
    {
        return $this->signatures()->where('is_valid', true);
    }

    public function verifications()
    {
        return $this->hasManyThrough(
            SignatureVerification::class,
            Signature::class,
            'file_id', // Clé étrangère sur signatures
            'signature_id', // Clé étrangère sur verifications
            'id', // Clé locale sur files_signeds
            'id'  // Clé locale sur signatures
        );
    }

    // Accessors
    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at && $this->expires_at < now();
    }

    public function getIsFullySignedAttribute(): bool
    {
        return $this->signatures_count >= $this->total_signatures_needed;
    }

    public function getFilePathAttribute($value): string
    {
        return storage_path('app/' . $value);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending_signature')
            ->orWhere('status', 'partially_signed');
    }

    public function scopeSigned($query)
    {
        return $query->where('status', 'fully_signed');
    }

    public function scopeByUploader($query, $userId)
    {
        return $query->where('uploader_id', $userId);
    }

    // Méthodes
    public function addSignatureCount(): void
    {
        $this->increment('signatures_count');
        
        if ($this->signatures_count >= $this->total_signatures_needed) {
            $this->status = 'fully_signed';
        } else {
            $this->status = 'partially_signed';
        }
        
        $this->save();
    }
}