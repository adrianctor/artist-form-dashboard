<?php

namespace App\Http\Controllers;

use App\Models\Song;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard pages for song history.
 * Implemented by the songs-pages work unit.
 */
class SongPageController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        return Inertia::render('songs/Index', [
            // Spec: server-side filter + paginator so the list scales to
            // millions of rows (client-side filtering only sees one page).
            'songs' => Song::query()
                ->visibleTo($request->user())
                ->when($q !== '', function ($query) use ($q) {
                    $like = '%'.$q.'%';
                    $query->where(function ($query) use ($like) {
                        $query->where('song_code', 'like', $like)
                            ->orWhere('track_title', 'like', $like);
                    });
                })
                ->with([
                    'submissions' => fn ($q) => $q
                        ->orderBy('id')
                        ->select(['id', 'song_id', 'created_by', 'payload'])
                        ->with('createdBy'),
                ])
                ->orderByDesc('updated_at')
                ->paginate(15)
                ->withQueryString()
                ->through(function (Song $song) {
                    // Artists: the union across every submit (the Versions
                    // column is gone — spec: no version wording).
                    $artistNames = [];
                    foreach ($song->submissions as $index => $submission) {
                        foreach (($submission->payload['artists'] ?? []) as $artist) {
                            if (is_array($artist) && ! empty($artist['name'])) {
                                $artistNames[(string) $artist['name']] = true;
                            }
                        }
                    }
                    if ($artistNames === [] && $song->artist_emails) {
                        foreach (explode(',', trim((string) $song->artist_emails, ',')) as $email) {
                            if ($email !== '') {
                                $artistNames[$email] = true;
                            }
                        }
                    }

                    return [
                        'id' => $song->id,
                        'song_code' => $song->song_code,
                        'track_title' => $song->track_title,
                        'artists' => implode(', ', array_keys($artistNames)) ?: '—',
                        // Created by = whoever sent the track's first submit.
                        'created_by' => $song->submissions->first()?->createdBy?->name
                            ?? $song->submissions->first()?->createdBy?->email
                            ?? '—',
                        'updated_at' => $song->updated_at?->toIso8601String(),
                    ];
                }),
            'q' => $q,
        ]);
    }

    public function show(\Illuminate\Http\Request $request, string $songCode): Response
    {
        // Visibility via the same scope as the index (spec S6): owner,
        // creator of any submit, or any artist email — admins see all.
        $song = Song::visibleTo($request->user())
            ->where('song_code', $songCode)
            ->with(['submissions' => fn ($q) => $q->orderByDesc('id')->with('createdBy')])
            ->firstOrFail();

        return Inertia::render('songs/Show', [
            'song' => [
                'id' => $song->id,
                'song_code' => $song->song_code,
                'track_title' => $song->track_title,
                'updated_at' => $song->updated_at?->toIso8601String(),
            ],
            'submissions' => $song->submissions->values()->map(fn ($s) => [
                'id' => $s->id,
                // Spec S10: no "Version N" anywhere in a submit's info.
                'track_title' => $s->track_title,
                'payload' => $s->payload,
                'created_at' => $s->created_at?->toIso8601String(),
                // Spec S6: the history must say who created this submit.
                'created_by' => $s->createdBy?->name ?? $s->createdBy?->email,
                // Spec S11: only the submit's creator may edit/delete it.
                'editable' => $s->created_by !== null
                    && $s->created_by === $request->user()->id,
                // Spec S13: artists shaped for display — links are
                // {label,url} pairs (never JSON) so the page can render
                // them as real anchors.
                'artists' => $this->artistsForDisplay($s->payload),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{name: string, fields: array<int, array{label: string, value: string}>, links: array<int, array{label: string, url: string}>}>
     */
    private function artistsForDisplay(array $payload): array
    {
        $artists = $payload['artists'] ?? null;
        if (! is_array($artists)) {
            return [];
        }

        $display = [];

        foreach (array_values($artists) as $index => $artist) {
            if (! is_array($artist)) {
                $display[] = [
                    'name' => 'Artist '.($index + 1),
                    'fields' => [['label' => 'Details', 'value' => (string) $artist]],
                    'links' => [],
                ];

                continue;
            }

            $name = null;
            $fields = [];
            $links = [];

            foreach ($artist as $key => $value) {
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }

                if (in_array($key, ['name', 'artist_name'], true)) {
                    $name ??= (string) $value;

                    continue; // the card heading carries the name
                }

                if ($key === 'links') {
                    foreach ((array) $value as $platform => $url) {
                        $url = trim((string) $url);
                        if ($url !== '') {
                            $links[] = [
                                'label' => ucwords(str_replace('_', ' ', (string) $platform)),
                                'url' => $url,
                            ];
                        }
                    }

                    continue;
                }

                $fields[] = [
                    'label' => ucwords(str_replace('_', ' ', (string) $key)),
                    'value' => is_scalar($value) ? (string) $value : json_encode($value),
                ];
            }

            $display[] = [
                'name' => $name ?? ('Artist '.($index + 1)),
                'fields' => $fields,
                'links' => $links,
            ];
        }

        return $display;
    }
}
