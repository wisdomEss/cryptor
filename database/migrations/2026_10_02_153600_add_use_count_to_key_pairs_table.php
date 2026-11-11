<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('key_pairs', function (Blueprint $table) {
            $table->unsignedBigInteger('use_count')->default(0)->after('last_used');
        });
    }

    public function down(): void
    {
        Schema::table('key_pairs', function (Blueprint $table) {
            $table->dropColumn('use_count');
        });
    }
};
