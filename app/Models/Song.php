<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $song_code
 * @property string|null $track_title
 * @property int $submission_count
 */
class Song extends Model
{
    use HasFactory;

    protected $fillable = [
        'song_code',
        'user_id',
        'artist_emails',
        'track_title',
        'submission_count',
    ];

    protected function casts(): array
    {
        return [
            'submission_count' => 'integer',
        ];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(SongSubmission::class);
    }

    public function owner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /**
     * Songs visible to the given user (spec S6): admins see everything;
     * users see the songs they own (artist-email match), the ones they
     * created a submit for, and the ones where their email appears as an
     * artist on any submit (artist_emails list).
     */
    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, \App\Models\User $user): \Illuminate\Database\Eloquent\Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        $email = '%,'.addcslashes(strtolower($user->email), '%_').',%';

        return $query->where(function ($q) use ($user, $email) {
            $q->where('user_id', $user->id)
                ->orWhereHas(
                    'submissions',
                    fn ($s) => $s->where('created_by', $user->id)
                )
                ->orWhere('artist_emails', 'like', $email);
        });
    }

    public static function generateSongCode(): string
    {
        do {
            $code = 'SONG-'.strtoupper(bin2hex(random_bytes(4)));
        } while (static::where('song_code', $code)->exists());

        return $code;
    }
}
