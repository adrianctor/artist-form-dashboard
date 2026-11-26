<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Viewer timezone for date bucketing (bug fix: the calendar grouped submits
 * by UTC date, so a submit on the 19th at 20:22 local (UTC-5) landed on the
 * 20th).
 *
 * The browser reports its UTC offset via Date.prototype.getTimezoneOffset()
 * (minutes; UTC-5 → 300, UTC+2 → -120) and app.ts writes it to the
 * unencrypted `tz_offset` cookie on every page boot. Server code converts
 * between local wall time and UTC with it:
 *
 *   local date of a UTC instant  = DATE(created_at + INTERVAL -offset MINUTE)
 *   UTC instant of a local moment = local->addMinutes(offset)
 *
 * Without the cookie (fresh deep link, cookies disabled, first ever page
 * load) the offset is 0 → UTC grouping, i.e. the historical behavior.
 */
final class ViewerTimezone
{
    /** Cookie name; must be listed in the encryptCookies() except list. */
    public const COOKIE = 'tz_offset';

    /**
     * Browser UTC offset in minutes (getTimezoneOffset convention:
     * local = UTC - offset). Returns 0 for a missing or invalid cookie.
     */
    public static function offset(Request $request): int
    {
        $raw = $request->cookie(self::COOKIE);

        if (! is_string($raw) || preg_match('/^-?\d+$/', $raw) !== 1) {
            return 0;
        }

        $offset = (int) $raw;

        // Real-world range: UTC+14 (-840) .. UTC-12 (720).
        return ($offset >= -840 && $offset <= 720) ? $offset : 0;
    }

    /**
     * SQL expression for the viewer-local date of a UTC datetime column.
     *
     * $shift is minutes to ADD to UTC to reach viewer-local time
     * (= -offset). Dialect-aware because the suite runs on SQLite (no
     * INTERVAL syntax) while production runs on MySQL; the value is a
     * validated integer, inlined as a literal (no placeholder inside
     * INTERVAL, which is fragile across drivers).
     *
     * Shift 0 keeps the original DATE(column) expression byte-for-byte, so
     * the default (no cookie) path is exactly the historical behavior.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function localDateSql(string $column, int $shift): string
    {
        $expression = $shift === 0
            ? "DATE({$column})"
            : (DB::connection()->getDriverName() === 'sqlite'
                // SQLite has no INTERVAL syntax — it takes date modifiers.
                ? "DATE({$column}, '{$shift} minutes')"
                : "DATE({$column} + INTERVAL {$shift} MINUTE)");

        /** @var literal-string&non-falsy-string $expression Built from our own SQL fragments plus a validated int. */
        return $expression;
    }
}
