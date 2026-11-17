<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('songs', function (Blueprint $table) {
            $table->id();
            // Unique identifier shared with the Elementor form (SONG-XXXXXXXX)
            $table->string('song_code', 32)->unique();
            // Latest known title, denormalized for list/search
            $table->string('track_title')->nullable();
            $table->unsignedInteger('submission_count')->default(0);
            $table->timestamps();
        });

        Schema::create('song_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            // Full form payload (artists, links, cover info, ...) as submitted
            $table->json('payload');
            $table->string('track_title')->nullable();
            $table->timestamps();

            $table->index(['song_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('song_submissions');
        Schema::dropIfExists('songs');
    }
};
