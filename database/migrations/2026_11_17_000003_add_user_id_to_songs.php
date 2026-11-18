<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            // Owner matched from the form's artist emails (spec: users only
            // see the songs they registered). Null = admin-only visibility.
            $table->foreignId('user_id')
                ->nullable()
                ->after('song_code')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
