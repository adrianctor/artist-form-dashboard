<?php

namespace App\Http\Controllers;

use App\Models\Song;
use App\Models\SongSubmission;
use App\Support\ViewerTimezone;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard home: metrics + recent song activity.
 * Implemented by the songs-pages work unit.
 *
 * Date-bucketed metrics use the viewer's timezone (the `tz_offset`
 * cookie) — same fix as the calendar: UTC bucketing put a submit made at
 * 20:22 local (UTC-5) on the next day.
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        // Users only see their own songs; admins see everything (spec S5).
        $visible = fn () => Song::query()->visibleTo($request->user());

        $tz = ViewerTimezone::offset($request);

        return Inertia::render('Dashboard', [
            'stats' => [
                'total_songs' => $visible()->count(),
                'total_submissions' => (int) $visible()->sum('submission_count'),
                'songs_this_month' => $visible()
                    // Viewer-local month start, expressed in UTC for the
                    // created_at comparison (local = UTC - $tz).
                    ->where('created_at', '>=', now()->subMinutes($tz)->startOfMonth()->addMinutes($tz))
                    ->count(),
            ],
            // The offset the server used — the client reloads once if the
            // browser's cookie was missing/stale on a fresh deep link.
            'tz' => $tz,
            'recentSongs' => $visible()
                ->orderByDesc('updated_at')
                ->limit(8)
                ->get()
                ->map(fn (Song $song) => [
                    'song_code' => $song->song_code,
                    'track_title' => $song->track_title,
                    'updated_at' => $song->updated_at?->toIso8601String(),
                ]),
            // Submissions per day for the last 30 days (ECharts bar/line)
            'submissionsDaily' => $this->submissionsDaily($tz),
            // Latest submits (spec: Submission activity gets real content)
            'recentSubmissions' => $this->recentSubmissions($request),
        ]);
    }

    /**
     * @return array<int, array{id: int, song_code: string, track_title: string, created_by: string, created_at: string|null}>
     */
    private function recentSubmissions(Request $request): array
    {
        return SongSubmission::query()
            ->whereIn('song_id', Song::visibleTo($request->user())->select('id'))
            ->with(['createdBy', 'song:id,song_code,track_title'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (SongSubmission $submission) => [
                'id' => $submission->id,
                'song_code' => $submission->song->song_code,
                'track_title' => $submission->track_title ?? $submission->song->track_title,
                'created_by' => $submission->createdBy?->name ?? '—',
                'created_at' => $submission->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<string, int> */
    private function submissionsDaily(int $tz): array
    {
        // Viewer-local "today" (UTC minus the offset); bucket keys are
        // viewer-local dates.
        $today = now()->subMinutes($tz)->startOfDay();
        $start = $today->copy()->subDays(29);
        $counts = SongSubmission::query()
            ->selectRaw(ViewerTimezone::localDateSql('created_at', -$tz).' as day, COUNT(*) as total')
            ->where('created_at', '>=', $start->copy()->addMinutes($tz))
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day');

        $daily = [];
        for ($offset = 0; $offset < 30; $offset++) {
            $day = $start->copy()->addDays($offset)->toDateString();
            $daily[$day] = (int) ($counts[$day] ?? 0);
        }

        return $daily;
    }
}
