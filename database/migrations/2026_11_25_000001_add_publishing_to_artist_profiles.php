<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spec S6 (odd/tasks/profile-writers-publishing.md): the profile
        // carries the artist's publishing info; the release form autofills
        // it per artist. IPI is optional ("if available").
        Schema::table('artist_profiles', function (Blueprint $table) {
            $table->string('publishing')->nullable(); // AFRODISE | COPYRIGHT CONTROL | OTHER
            $table->string('publishing_custom')->nullable(); // required only when publishing = OTHER
            $table->string('ipi_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('artist_profiles', function (Blueprint $table) {
            $table->dropColumn(['publishing', 'publishing_custom', 'ipi_number']);
        });
    }
};
