<?php

namespace App\Http\Controllers;

use App\Models\Song;
use App\Models\SongSubmission;
use App\Support\ViewerTimezone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calendar (spec S2/S3/S4): every form submit grouped by the date it was
 * made — in the VIEWER's timezone (bug fix: grouping used the UTC date, so
 * a submit on the 19th at 20:22 local (UTC-5) landed on the 20th). The
 * browser sends its UTC offset in the `tz_offset` cookie (see
 * App\Support\ViewerTimezone); without it the historical UTC behavior holds.
 *
 * Scale: only the requested month is aggregated in SQL (day counts), and
 * the selected day's submissions are loaded on demand, paginated (50/page)
 * — a table with millions of rows never loads in full.
 */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        // Browser offset: local = UTC - $tz (UTC-5 → 300).
        $tz = ViewerTimezone::offset($request);

        $month = $this->parseMonth($request->query('month'), $tz);
        // Month bounds are built in viewer-local time, then shifted to the
        // UTC wall time that created_at stores.
        $startLocal = $month->copy()->startOfMonth();
        $endLocal = $month->copy()->endOfMonth();
        $start = $startLocal->copy()->addMinutes($tz);
        $end = $endLocal->copy()->addMinutes($tz);

        $visibleSongIds = Song::visibleTo($request->user())->select('id');

        // Spec S4: one aggregate row per day for the requested month only.
        $dayCounts = SongSubmission::query()
            ->whereIn('song_id', $visibleSongIds)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw(ViewerTimezone::localDateSql('created_at', -$tz).' as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->map(fn ($total) => (int) $total)
            ->all();

        // Spec S3/S4: the selected day's submits, loaded on demand.
        $day = $request->query('day');
        $dayPage = null;
        if (is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $dayStart = Carbon::createFromFormat('Y-m-d', $day)->startOfDay();

            // Only days inside the requested month produce a day page
            // (compared viewer-local vs viewer-local).
            if (! $dayStart->between($startLocal, $endLocal)) {
                $day = null;
            } else {
                $dayPage = SongSubmission::query()
                    ->whereIn('song_id', $visibleSongIds)
                    ->join('songs', 'songs.id', '=', 'song_submissions.song_id')
                    ->leftJoin('users', 'users.id', '=', 'song_submissions.created_by')
                    ->whereBetween('song_submissions.created_at', [
                        $dayStart->copy()->addMinutes($tz),
                        $dayStart->copy()->endOfDay()->addMinutes($tz),
                    ])
                    ->select([
                        'song_submissions.id as submission_id',
                        'songs.song_code',
                        'songs.track_title',
                        'songs.artist_emails',
                        'song_submissions.payload',
                        'song_submissions.created_at',
                    ])
                    ->addSelect('users.name as created_by_name')
                    ->orderBy('song_submissions.created_at')
                    ->orderBy('song_submissions.id')
                    ->paginate(50)
                    ->withQueryString()
                    ->through(function ($row) {
                        $attributes = $row->getAttributes();

                        return [
                            'id' => $row->submission_id,
                            'song_code' => $row->song_code,
                            'track_title' => $row->track_title,
                            // Enough info before opening the detail: the
                            // artists (payload rows, falling back to the song
                            // emails) and who created the submit.
                            'artists' => implode(', ', $row->artistNames($attributes['artist_emails'] ?? null)),
                            'created_by' => (string) ($attributes['created_by_name'] ?? ''),
                            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                        ];
                    });
            }
        }

        return Inertia::render('Calendar', [
            'month' => $month->format('Y-m'),
            // The offset the server actually used — the client compares it
            // with the browser's and reloads once if the cookie was missing.
            'tz' => $tz,
            'dayCounts' => $dayCounts,
            'day' => $dayPage !== null ? $day : null,
            'dayPage' => $dayPage,
        ]);
    }

    /** 'YYYY-MM' (validated) or the viewer's current month. */
    private function parseMonth(mixed $value, int $tz): Carbon
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value)) {
            try {
                return Carbon::createFromFormat('Y-m', $value)->startOfMonth();
            } catch (\Throwable) {
                // fall through to the current month
            }
        }

        // Viewer-local "now" (UTC minus the offset) decides the month, so
        // the 1st at 00:30 local doesn't jump to the wrong month.
        return Carbon::now()->subMinutes($tz)->startOfMonth();
    }
}
