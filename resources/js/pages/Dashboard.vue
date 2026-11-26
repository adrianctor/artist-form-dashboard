<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import SubmissionsChart from '@/components/charts/SubmissionsChart.vue';
import { useViewerTimezone } from '@/composables/useViewerTimezone';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import songRoutes from '@/routes/songs';

interface DashboardStats {
    total_songs: number;
    total_submissions: number;
    songs_this_month: number;
}

interface RecentSong {
    song_code: string;
    track_title: string | null;
    updated_at: string | null;
}

const props = defineProps<{
    stats: DashboardStats;
    recentSongs: RecentSong[];
    tz: number; // UTC offset the server grouped the dates with (minutes)
    submissionsDaily: Record<string, number>;
    recentSubmissions: RecentSubmission[];
}>();

// Fresh deep links arrive without the tz_offset cookie (UTC buckets) —
// write it and reload once so the chart matches the viewer's dates.
useViewerTimezone(props.tz);

interface RecentSubmission {
    id: number;
    song_code: string;
    track_title: string;
    created_by: string;
    created_at: string | null;
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

function formatDate(value: string | null): string {
    if (!value) return '—';

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
    }).format(new Date(value));
}
</script>

<template>
    <Head title="Dashboard" />

    <main class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <section aria-label="Song statistics" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground">Total songs</CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-3xl font-semibold">{{ stats.total_songs.toLocaleString() }}</p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground">Total submissions</CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-3xl font-semibold">{{ stats.total_submissions.toLocaleString() }}</p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium text-muted-foreground">Songs this month</CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-3xl font-semibold">{{ stats.songs_this_month.toLocaleString() }}</p>
                </CardContent>
            </Card>
        </section>

        <section class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
            <Card>
                <CardHeader>
                    <CardTitle>Submission activity</CardTitle>
                </CardHeader>
                <CardContent>
                    <SubmissionsChart :data="submissionsDaily" />

                    <div
                        v-if="recentSubmissions.length"
                        class="mt-4 space-y-2 border-t pt-4"
                    >
                        <p class="text-sm font-medium">Latest submissions</p>
                        <!-- Bounded so a flood of submits cannot stretch the card. -->
                        <ul class="max-h-72 space-y-1.5 overflow-y-auto pr-1">
                            <li
                                v-for="submission in recentSubmissions"
                                :key="submission.id"
                                class="flex items-center justify-between gap-3 text-sm"
                            >
                                <Link
                                    :href="songRoutes.show.url({ songCode: submission.song_code })"
                                    class="min-w-0 truncate hover:text-primary"
                                >
                                    {{ submission.track_title }}
                                </Link>
                                <span class="shrink-0 text-xs text-muted-foreground">
                                    {{ submission.created_by }} · {{ formatDate(submission.created_at) }}
                                </span>
                            </li>
                        </ul>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex-row items-center justify-between space-y-0">
                    <CardTitle>Recent songs</CardTitle>
                    <Link
                        :href="songRoutes.index.url()"
                        class="text-sm font-medium text-primary underline-offset-4 hover:underline"
                    >
                        View all
                    </Link>
                </CardHeader>
                <CardContent>
                    <ul v-if="recentSongs.length" class="divide-y divide-border">
                        <li v-for="song in recentSongs" :key="song.song_code" class="py-3 first:pt-0 last:pb-0">
                            <Link
                                :href="songRoutes.show.url({ songCode: song.song_code })"
                                class="flex items-start justify-between gap-4 rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate font-medium">{{ song.track_title || 'Untitled song' }}</span>
                                    <span class="mt-1 block text-xs text-muted-foreground">Catalogo · {{ song.song_code }}</span>
                                </span>
                                <span class="shrink-0 text-right text-xs text-muted-foreground">
                                    <span class="block">{{ formatDate(song.updated_at) }}</span>
                                </span>
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">No songs have been submitted yet.</p>
                </CardContent>
            </Card>
        </section>
    </main>
</template>
