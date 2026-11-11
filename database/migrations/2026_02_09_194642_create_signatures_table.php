<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->foreignId('file_id')->constrained('files_signeds')->onDelete('cascade');
            $table->foreignId('key_pair_id')->constrained()->onDelete('cascade');
            $table->foreignId('signer_id')->constrained('users')->onDelete('cascade');

            // Signature
            $table->text('signature_value');
            $table->enum('signature_format', ['cms', 'CAdES', 'PAdES', 'pdf'])->default('cms');
            $table->string('algorithm_used');

            // Métadonnées de signature
            $table->text('signing_reason')->nullable();
            $table->string('signing_location')->nullable();
            $table->string('contact_info')->nullable();
            $table->text('visual_signature_data')->nullable();

            // État
            $table->boolean('is_valid')->default(true);
            $table->enum('validation_status', ['pending', 'valid', 'invalid', 'expired', 'revoked'])->default('pending');
            $table->string('validation_message', 500)->nullable();

            // Sécurité
            $table->string('nonce_value')->nullable();
            $table->text('timestamp_token')->nullable();

            // Audit
            $table->string('signing_ip', 45)->nullable();
            $table->text('signing_device_info')->nullable();
            $table->timestamp('signed_at')->useCurrent();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();

            // Timestamps
            $table->timestamps();

            // Index
            $table->index('file_id');
            $table->index('signer_id');
            $table->index(['validation_status', 'is_valid']);
            $table->index('signed_at');
            $table->index(['key_pair_id', 'is_valid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};
