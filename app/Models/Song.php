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

    public static function generateSongCode(): string
    {
        do {
            $code = 'SONG-'.strtoupper(bin2hex(random_bytes(4)));
        } while (static::where('song_code', $code)->exists());

        return $code;
    }
}
