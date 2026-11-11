<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Une signature externe peut ne pas fournir l'empreinte attendue du document
     * (`file_hash`) : la vérification est alors journalisée avec expected_hash = null.
     * Auparavant la colonne était NOT NULL et la vérification plantait (erreur 500).
     */
    public function up(): void
    {
        Schema::table('signatures_verifications', function (Blueprint $table) {
            $table->string('expected_hash')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('signatures_verifications', function (Blueprint $table) {
            $table->string('expected_hash')->nullable(false)->change();
        });
    }
};
