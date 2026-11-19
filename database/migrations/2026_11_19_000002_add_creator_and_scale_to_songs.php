<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('song_submissions', function (Blueprint $table) {
            // Who made this submit (spec S6: the history must say who
            // created it, besides the artists).
            $table->foreignId('created_by')
                ->nullable()
                ->after('track_title')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('songs', function (Blueprint $table) {
            // Denormalized ",email1,email2," list (lowercase) of every artist
            // email seen on any submission — used by scopeVisibleTo so ALL
            // artists with a dashboard account can see the song (spec S6).
            $table->text('artist_emails')->nullable()->after('user_id');
        });

        // Scale indexes (spec S4/S9): calendar range scans, dashboard
        // ordering/daily aggregates, creator lookups.
        Schema::table('songs', function (Blueprint $table) {
            $table->index('created_at');
            $table->index('updated_at');
        });
        Schema::table('song_submissions', function (Blueprint $table) {
            $table->index('created_at');
            $table->index('created_by');
        });

        $this->backfillArtistEmails();
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['updated_at']);
            $table->dropColumn('artist_emails');
        });
        Schema::table('song_submissions', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['created_by']);
            $table->dropConstrainedForeignId('created_by');
        });
    }

    /**
     * Build songs.artist_emails from the existing submissions (chunked so a
     * table with millions of rows does not load into memory at once).
     */
    private function backfillArtistEmails(): void
    {
        DB::table('songs')->select('id')->orderBy('id')->chunkById(500, function ($songs) {
            foreach ($songs as $song) {
                $emails = [];

                DB::table('song_submissions')
                    ->where('song_id', $song->id)
                    ->select('payload')
                    ->get()
                    ->each(function ($submission) use (&$emails) {
                        $payload = json_decode($submission->payload, true) ?? [];
                        foreach (array_column($payload['artists'] ?? [], 'email') as $email) {
                            $email = strtolower(trim((string) $email));
                            if ($email !== '') {
                                $emails[$email] = true;
                            }
                        }
                    });

                if ($emails !== []) {
                    DB::table('songs')
                        ->where('id', $song->id)
                        ->update(['artist_emails' => ','.implode(',', array_keys($emails)).',']);
                }
            }
        });
    }
};
