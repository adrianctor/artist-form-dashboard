<?php

namespace App\Http\Controllers;

use App\Models\Song;
use App\Models\SongSubmission;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard home: metrics + recent song activity.
 * Implemented by the songs-pages work unit.
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => [
                'total_songs' => Song::count(),
                'total_submissions' => (int) Song::sum('submission_count'),
                'songs_this_month' => Song::where('created_at', '>=', now()->startOfMonth())->count(),
            ],
            'recentSongs' => Song::query()
                ->orderByDesc('updated_at')
                ->limit(8)
                ->get()
                ->map(fn (Song $song) => [
                    'song_code' => $song->song_code,
                    'track_title' => $song->track_title,
                    'submission_count' => $song->submission_count,
                    'updated_at' => $song->updated_at?->toIso8601String(),
                ]),
            // Submissions per day for the last 30 days (ECharts bar/line)
            'submissionsDaily' => $this->submissionsDaily(),
        ]);
    }

    /** @return array<string, int> */
    private function submissionsDaily(): array
    {
        $today = now()->startOfDay();
        $start = $today->copy()->subDays(29);
        $counts = SongSubmission::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->where('created_at', '>=', $start)
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
