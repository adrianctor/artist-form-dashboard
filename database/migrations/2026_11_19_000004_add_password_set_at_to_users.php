<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // NULL = never chose a password (social signup): those users set
            // their first password via an emailed signed link (spec S2).
            $table->timestamp('password_set_at')->nullable()->after('is_active');
        });

        // Existing users are assumed to know their password; the ones that
        // don't can use "Forgot password" instead (spec S2).
        DB::table('users')->update(['password_set_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_set_at');
        });
    }
};
