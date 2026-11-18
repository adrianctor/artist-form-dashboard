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
     * Songs visible to the given user: admins see everything, users only
     * the ones they own (matched via the form's artist emails).
     */
    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, \App\Models\User $user): \Illuminate\Database\Eloquent\Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }

    public static function generateSongCode(): string
    {
        do {
            $code = 'SONG-'.strtoupper(bin2hex(random_bytes(4)));
        } while (static::where('song_code', $code)->exists());

        return $code;
    }
}
