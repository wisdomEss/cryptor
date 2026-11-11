<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable , SoftDeletes, TwoFactorAuthenticatable;

    protected $table = 'users';

    protected $fillable = [
        'email',
        'username',
        'password',
        'name',
        'phone_number',
        'is_active',
        'is_email_verified',
        'two_factor_enabled',
        'role',
        'timezone',
        'language',
        'preferences',
        'last_login',
        'failed_login_attempts',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'is_active' => 'boolean',
        'is_email_verified' => 'boolean',
        'two_factor_enabled' => 'boolean',
        'preferences' => 'array',
        'last_login' => 'datetime',
        'last_password_change' => 'datetime',
        'account_locked_until' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'two_factor_confirmed_at' => 'datetime',
    ];

    // Relations
    public function keyPairs()
    {
        return $this->hasMany(KeyPair::class);
    }

    public function defaultKeyPair()
    {
        return $this->hasOne(KeyPair::class)->where('is_default', true);
    }

    public function filesSigned()
    {
        return $this->hasMany(FileSigned::class, 'uploader_id');
    }

    public function signaturesMade()
    {
        return $this->hasMany(Signature::class, 'signer_id');
    }

    public function verificationsDone()
    {
        return $this->hasMany(SignatureVerification::class, 'verifier_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAdmin($query)
    {
        return $query->where('role', 'admin');
    }

    // Méthodes utiles
    public function canSign(): bool
    {
        return $this->is_active && $this->is_email_verified;
    }

    public function hasKeyPair(): bool
    {
        return $this->keyPairs()->where('is_active', true)->exists();
    }
}
