<?php

namespace App\Http\Controllers;

use App\Models\Song;
use App\Models\SongSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calendar (spec S2/S3/S4): every form submit grouped by the date it was
 * made. Accessible to all authenticated users (own songs; admins see all).
 *
 * Scale: only the requested month is aggregated in SQL (day counts), and
 * the selected day's submissions are loaded on demand, paginated (50/page)
 * — a table with millions of rows never loads in full.
 */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $month = $this->parseMonth($request->query('month'));
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $visibleSongIds = Song::visibleTo($request->user())->select('id');

        // Spec S4: one aggregate row per day for the requested month only.
        $dayCounts = SongSubmission::query()
            ->whereIn('song_id', $visibleSongIds)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->map(fn ($total) => (int) $total)
            ->all();

        // Spec S3/S4: the selected day's submits, loaded on demand.
        $day = $request->query('day');
        $dayPage = null;
        if (is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $dayStart = Carbon::createFromFormat('Y-m-d', $day)->startOfDay();

            // Only days inside the requested month produce a day page.
            if (! $dayStart->between($start, $end)) {
                $day = null;
            } else {
                $dayPage = SongSubmission::query()
                ->whereIn('song_id', $visibleSongIds)
                ->join('songs', 'songs.id', '=', 'song_submissions.song_id')
                ->whereBetween('song_submissions.created_at', [$dayStart, $dayStart->copy()->endOfDay()])
                ->select([
                    'song_submissions.id as submission_id',
                    'songs.song_code',
                    'songs.track_title',
                    'songs.submission_count',
                    'song_submissions.created_at',
                ])
                ->orderBy('song_submissions.created_at')
                ->orderBy('song_submissions.id')
                ->paginate(50)
                ->withQueryString()
                ->through(fn ($row) => [
                    'id' => $row->submission_id,
                    'song_code' => $row->song_code,
                    'track_title' => $row->track_title,
                    'submission_count' => $row->submission_count,
                    'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                ]);
            }
        }

        return Inertia::render('Calendar', [
            'month' => $month->format('Y-m'),
            'dayCounts' => $dayCounts,
            'day' => $dayPage !== null ? $day : null,
            'dayPage' => $dayPage,
        ]);
    }

    /** 'YYYY-MM' (validated) or the current month. */
    private function parseMonth(mixed $value): Carbon
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value)) {
            try {
                return Carbon::createFromFormat('Y-m', $value)->startOfMonth();
            } catch (\Throwable) {
                // fall through to the current month
            }
        }

        return Carbon::now()->startOfMonth();
    }
}
