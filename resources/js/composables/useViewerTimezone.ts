import { onMounted } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Viewer timezone plumbing (bug fix: the calendar/dashboard grouped dates
 * by UTC, loading a submit made on the 19th at 20:22 local (UTC-5) as the
 * 20th).
 *
 * app.ts writes the browser offset to the `tz_offset` cookie on every full
 * page boot; servers read it (App\Support\ViewerTimezone) to bucket dates in
 * the viewer's timezone. This composable closes the one gap: a fresh
 * deep-link renders before any cookie exists — it then writes the cookie and
 * reloads once so the counts land on the right days.
 */

// Module scope: survives Inertia page swaps/remounts, so a browser that
// cannot store cookies gets exactly one corrective reload, never a loop.
let retried = false;

export function viewerTimezoneOffset(): number {
    return new Date().getTimezoneOffset();
}

export function setViewerTimezoneCookie(): void {
    document.cookie = `tz_offset=${viewerTimezoneOffset()};path=/;max-age=31536000;SameSite=Lax`;
}

/** `serverOffset` is the `tz` prop the server grouped the dates with. */
export function useViewerTimezone(serverOffset: number): void {
    onMounted(() => {
        const browserOffset = viewerTimezoneOffset();
        if (serverOffset === browserOffset || retried) return;

        retried = true;
        setViewerTimezoneCookie();
        router.reload();
    });
}
