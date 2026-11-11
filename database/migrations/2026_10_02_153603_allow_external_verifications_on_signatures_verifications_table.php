<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Une vérification "externe" (document non connu du système, signature
     * fournie séparément) n'a pas de signature associée en base et doit
     * pouvoir être journalisée avec le type 'external'.
     */
    public function up(): void
    {
        Schema::table('signatures_verifications', function (Blueprint $table) {
            $table->dropForeign(['signature_id']);
        });

        Schema::table('signatures_verifications', function (Blueprint $table) {
            $table->unsignedBigInteger('signature_id')->nullable()->change();
            $table->enum('verification_type', [
                'hash', 'signature', 'certificate', 'timestamp', 'auto', 'manual', 'external',
            ])->change();
        });

        Schema::table('signatures_verifications', function (Blueprint $table) {
            $table->foreign('signature_id')->references('id')->on('signatures')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('signatures_verifications', function (Blueprint $table) {
            $table->dropForeign(['signature_id']);
        });

        Schema::table('signatures_verifications', function (Blueprint $table) {
            $table->unsignedBigInteger('signature_id')->nullable(false)->change();
            $table->enum('verification_type', [
                'hash', 'signature', 'certificate', 'timestamp', 'auto', 'manual',
            ])->change();
        });

        Schema::table('signatures_verifications', function (Blueprint $table) {
            $table->foreign('signature_id')->references('id')->on('signatures')->onDelete('cascade');
        });
    }
};
