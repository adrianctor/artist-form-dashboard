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
        'created_by',
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

    /** User that hit submit for this version (spec S6). */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Display names of the artists carried by this submit, so listings can
     * show them before the detail page is opened. Falls back to the song's
     * denormalized email list when the payload has no artist rows (API
     * submits may omit them entirely).
     *
     * @return array<int, string>
     */
    public function artistNames(?string $fallbackEmails = null): array
    {
        /** @var list<mixed> $artists Payload rows; the docblock only declares `array`. */
        $artists = $this->payload['artists'] ?? [];

        $names = collect($artists)
            ->map(fn ($artist) => is_array($artist) ? trim((string) ($artist['name'] ?? '')) : '')
            ->filter()
            ->values()
            ->all();

        if ($names !== []) {
            return $names;
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) ($fallbackEmails ?? '')))));
    }
}
