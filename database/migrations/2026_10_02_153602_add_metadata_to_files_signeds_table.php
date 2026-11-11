<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files_signeds', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('retention_period');
        });
    }

    public function down(): void
    {
        Schema::table('files_signeds', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
