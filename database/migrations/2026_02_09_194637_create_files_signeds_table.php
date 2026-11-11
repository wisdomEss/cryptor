<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files_signeds', function (Blueprint $table) {
            $table->id();

            // Informations fichier
            $table->string('file_name');
            $table->string('original_file_name');
            $table->string('file_path', 500)->nullable();

            // Hashes
            $table->string('file_hash'); // Hash après signature
            $table->string('original_hash'); // Hash original
            $table->string('hash_algorithm')->default('SHA-256');

            // Métadonnées
            $table->foreignId('uploader_id')->constrained('users')->onDelete('cascade');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type');
            $table->string('file_extension', 50)->nullable();

            // Sécurité
            $table->boolean('is_encrypted')->default(false);
            $table->string('encryption_algorithm')->nullable();

            // État
            $table->enum('status', [
                'uploaded',
                'pending_signature',
                'partially_signed',
                'fully_signed',
                'verified',
                'archived',
                'expired',
                'deleted',
            ])->default('uploaded');

            // Signatures
            $table->unsignedInteger('total_signatures_needed')->default(1);
            $table->unsignedInteger('signatures_count')->default(0);

            // Dates
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('retention_period')->nullable(); // En jours

            // Audit
            $table->string('upload_ip', 45)->nullable();
            $table->text('upload_user_agent')->nullable();
            $table->timestamp('deleted_at')->nullable();

            // Timestamps
            $table->timestamps();

            // Index
            $table->index(['uploader_id', 'status']);
            $table->index('created_at');
            $table->index('expires_at');
            $table->index('file_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files_signeds');
    }
};
