<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatures_verifications', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->foreignId('signature_id')->constrained()->onDelete('cascade');
            $table->foreignId('verifier_id')->nullable()->constrained('users')->onDelete('set null');

            // Vérification
            $table->string('verified_hash');
            $table->string('expected_hash');
            $table->boolean('result');
            $table->enum('verification_type', ['hash', 'signature', 'certificate', 'timestamp', 'auto', 'manual']);

            // Détails de vérification
            $table->json('verification_details')->nullable();
            $table->text('error_message')->nullable();

            // Timestamps vérifiés
            $table->timestamp('signature_timestamp')->nullable();
            $table->timestamp('document_timestamp')->nullable();

            // Contexte
            $table->string('verifier_ip', 45);
            $table->text('verifier_user_agent')->nullable();
            $table->timestamp('verified_at')->useCurrent();

            // Performance
            $table->unsignedInteger('verification_duration_ms')->nullable();

            // Références
            $table->text('certificate_chain')->nullable();
            $table->string('timestamp_authority_used')->nullable();

            // Audit
            $table->string('verification_token')->unique()->nullable();
            $table->boolean('is_public_verification')->default(false);

            // Timestamps
            $table->timestamps();

            // Index
            $table->index('signature_id');
            $table->index(['result', 'verified_at'], 'sig_verif_result_date_idx');
            $table->index(['is_public_verification', 'verification_token'], 'sig_verif_public_token_idx');
            $table->index('verifier_id');
            $table->index('verified_hash');

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatures_verifications');
    }
};
