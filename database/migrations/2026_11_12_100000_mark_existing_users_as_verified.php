<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * L'obligation de confirmer son adresse e-mail est introduite après la création des premiers
     * comptes, qui n'ont jamais reçu de lien de confirmation. Sans cette migration, tous les
     * comptes existants se retrouveraient bloqués au premier déploiement.
     *
     * Les comptes déjà présents sont donc considérés comme confirmés ; seuls les comptes créés
     * à partir de maintenant doivent confirmer leur adresse.
     */
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Irréversible : on ne sait plus quels comptes étaient réellement non confirmés.
    }
};
