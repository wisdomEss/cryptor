<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KeyPair extends Model
{
    use HasFactory;

    protected $table = 'key_pairs';

    protected $fillable = [
        'user_id',
        'public_key',
        'private_key',
        'private_key_iv',
        'key_name',
        'algorithm_used',
        'key_size',
        'key_identifier',
        'is_default',
        'is_active',
        'valid_to',
        'certificate_data',
        'certificate_issuer',
        'valid_from'
    ];

    protected $hidden = [
        'private_key',
        'private_key_iv'
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'is_revoked' => 'boolean',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'last_used' => 'datetime',
        'revoked_at' => 'datetime',
        'key_size' => 'integer'
    ];

    // Relations
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('is_revoked', false);
    }

public function scopeValid($query)
{
    $now = now();
    return $query
        ->where('is_active', true)
        ->where('is_revoked', false)
        ->where('valid_from', '<=', $now)
        ->where(function ($q) use ($now) {
            $q->whereNull('valid_to')
              ->orWhere('valid_to', '>=', $now);
        });
}

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    // Méthodes
    public function isValid(): bool
    {
        $now = now();
        return $this->is_active && !$this->is_revoked 
            && $this->valid_from <= $now 
            && ($this->valid_to === null || $this->valid_to >= $now);
    }
}