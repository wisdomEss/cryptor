<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index manquants sur les colonnes servant au tri et au filtrage des listes paginées.
 *
 * - `signatures_verifications.verified_at` : tri de la liste des vérifications
 *   (`latest('verified_at')`). L'index existant `(result, verified_at)` n'est pas
 *   utilisable pour un tri sans filtre sur `result` : le moteur triait toute la table.
 * - `signatures.revoked_at` : liste des signatures révoquées (filtre + tri).
 * - `key_pairs.(user_id, is_revoked)` : liste des clés révoquées d'un utilisateur et
 *   liste de révocation de l'AC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signatures_verifications', function (Blueprint $table) {
            $table->index('verified_at', 'sig_verif_verified_at_idx');
        });

        Schema::table('signatures', function (Blueprint $table) {
            $table->index('revoked_at', 'signatures_revoked_at_idx');
        });

        Schema::table('key_pairs', function (Blueprint $table) {
            $table->index(['user_id', 'is_revoked'], 'key_pairs_user_revoked_idx');
        });
    }

    public function down(): void
    {
        Schema::table('signatures_verifications', function (Blueprint $table) {
            $table->dropIndex('sig_verif_verified_at_idx');
        });

        Schema::table('signatures', function (Blueprint $table) {
            $table->dropIndex('signatures_revoked_at_idx');
        });

        Schema::table('key_pairs', function (Blueprint $table) {
            $table->dropIndex('key_pairs_user_revoked_idx');
        });
    }
};
