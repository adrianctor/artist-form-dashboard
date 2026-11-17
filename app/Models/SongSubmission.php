<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $song_id
 * @property array $payload
 * @property string|null $track_title
 */
class SongSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'song_id',
        'payload',
        'track_title',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }
}
