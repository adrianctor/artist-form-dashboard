<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Artist profile (spec S5) — the artist section of the release form,
 * mandatory for role=user before entering the dashboard.
 *
 * @property int $id
 * @property int $user_id
 * @property string $artist_name
 * @property string $real_name
 * @property string $role
 * @property string $country
 * @property string $city
 * @property string $estate
 * @property string $photo
 * @property string $bio
 * @property string $status  'New Artist' | 'Existing Artist'
 * @property array|null $links
 */
#[Fillable([
    'user_id',
    'artist_name',
    'real_name',
    'role',
    'country',
    'city',
    'estate',
    'photo',
    'bio',
    'status',
    'links',
])]
class ArtistProfile extends Model
{
    protected $table = 'artist_profiles';

    protected function casts(): array
    {
        return [
            'links' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Spec S5: email is not stored on the profile — it is the user's login email.
     */
    public function getEmailAttribute(): string
    {
        return $this->user?->email ?? '';
    }

    /**
     * Completeness rule (S5): every field non-empty; platform links are
     * required only when the artist already has profiles (Existing Artist).
     */
    public function isComplete(): bool
    {
        $required = [
            'artist_name',
            'real_name',
            'role',
            'country',
            'city',
            'estate',
            'photo',
            'bio',
            'status',
        ];

        foreach ($required as $field) {
            if (trim((string) ($this->{$field} ?? '')) === '') {
                return false;
            }
        }

        if (! in_array($this->status, ['New Artist', 'Existing Artist'], true)) {
            return false;
        }

        if ($this->status === 'Existing Artist') {
            $links = $this->links ?? [];
            if (! is_array($links) || $links === []) {
                return false;
            }
            // At least one non-empty platform link.
            foreach ($links as $url) {
                if (trim((string) $url) !== '') {
                    return true;
                }
            }

            return false;
        }

        return true;
    }
}
