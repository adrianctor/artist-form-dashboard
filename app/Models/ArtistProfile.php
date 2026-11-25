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
 * @property string $status 'New Artist' | 'Existing Artist'
 * @property array|null $links
 * @property string|null $publishing 'AFRODISE' | 'COPYRIGHT CONTROL' | 'OTHER'
 * @property string|null $publishing_custom required when publishing = OTHER
 * @property string|null $ipi_number
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
    'publishing',
    'publishing_custom',
    'ipi_number',
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
     * Completeness rule (S5 + spec S6 of profile-writers-publishing): every
     * field non-empty; platform links are required only when the artist
     * already has profiles (Existing Artist); publishing is required (and
     * the custom company name only when publishing = OTHER).
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

        // Spec S6: publishing is part of the gate.
        $publishing = trim((string) ($this->publishing ?? ''));
        if ($publishing === '') {
            return false;
        }
        if ($publishing === 'OTHER' && trim((string) ($this->publishing_custom ?? '')) === '') {
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
