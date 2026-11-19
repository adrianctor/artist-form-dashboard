<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec S5: artist profile 1:1 with users — the artist section of the
        // release form. Email lives on users (no duplicate column).
        Schema::create('artist_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('artist_name');
            $table->string('real_name');
            $table->string('role');
            $table->string('country');
            $table->string('city');
            $table->string('estate');
            $table->string('photo'); // link to photo (Drive/Dropbox)
            $table->text('bio');
            $table->string('status'); // 'New Artist' | 'Existing Artist'
            $table->json('links')->nullable(); // platform links (spotify, apple, ...)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_profiles');
    }
};
