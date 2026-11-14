<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `is_email_verified` était un doublon mort de `email_verified_at` : jamais écrit, lu
 * nulle part sauf par `User::canSign()` (supprimé), qui renvoyait donc toujours faux.
 * La confirmation d'adresse passe par `MustVerifyEmail` / `email_verified_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_email_verified');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_email_verified')->default(false)->after('username');
        });
    }
};
