<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('key_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->onDelete('cascade');

            // Clés
            $table->text('public_key');
            $table->text('private_key'); // Chiffré
            $table->string('private_key_iv')->nullable(); // Pour déchiffrement

            // Métadonnées
            $table->string('key_name');
            $table->string('algorithm_used');
            $table->unsignedInteger('key_size');
            $table->string('key_identifier')->unique();

            // État et validité
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_revoked')->default(false);
            $table->text('revocation_reason')->nullable();

            // Dates
            $table->timestamp('valid_from')->useCurrent();
            $table->timestamp('valid_to')->nullable();
            $table->timestamp('last_used')->nullable();

            // Certificat (optionnel)
            $table->text('certificate_data')->nullable();
            $table->string('certificate_issuer')->nullable();

            // Audit
            $table->string('created_by_ip', 45)->nullable();
            $table->text('created_by_user_agent')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Timestamps
            $table->timestamps();

            // Index
            $table->index(['user_id', 'is_active', 'is_default']);
            $table->index(['valid_from', 'valid_to']);
            $table->index('key_identifier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('key_pairs');
    }
};
