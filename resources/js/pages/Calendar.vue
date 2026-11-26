<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useViewerTimezone } from '@/composables/useViewerTimezone';
import { Button } from '@/components/ui/button';
import songRoutes from '@/routes/songs';

// Spec S3: one row per form submit (not per song), with its own date.
type DaySubmission = {
    id: number;
    song_code: string;
    track_title: string;
    artists: string;
    created_by: string;
    created_at: string;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type DayPage = {
    data: DaySubmission[];
    current_page: number;
    last_page: number;
    links: PaginationLink[];
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Calendar', href: '/calendar' },
        ],
    },
});

// Spec S4: everything derives from server props — only the requested month
// is aggregated and the selected day is loaded (paginated) on demand.
const props = defineProps<{
    month: string; // 'YYYY-MM'
    tz: number; // UTC offset the server grouped the dates with (minutes)
    dayCounts: Record<string, number>;
    day: string | null; // 'YYYY-MM-DD' | null
    dayPage: DayPage | null;
}>();

// Fresh deep links arrive without the tz_offset cookie (UTC grouping) —
// write it and reload once so the day buckets match the viewer's calendar.
useViewerTimezone(props.tz);

const [initialYear, initialMonth] = props.month.split('-').map(Number);
const viewYear = ref(initialYear);
const viewMonth = ref(initialMonth - 1); // 0-11

watch(
    () => props.month,
    (value) => {
        const [year, month] = value.split('-').map(Number);
        viewYear.value = year;
        viewMonth.value = month - 1;
    },
);

const monthLabel = computed(() =>
    new Date(viewYear.value, viewMonth.value, 1).toLocaleString('en-US', {
        month: 'long',
        year: 'numeric',
    }),
);

const weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

type DayCell = { day: number; key: string; count: number } | null;

const days = computed<DayCell[]>(() => {
    const firstWeekday = new Date(viewYear.value, viewMonth.value, 1).getDay();
    const daysInMonth = new Date(
        viewYear.value,
        viewMonth.value + 1,
        0,
    ).getDate();

    const cells: DayCell[] = [];
    for (let i = 0; i < firstWeekday; i++) {
        cells.push(null);
    }
    for (let day = 1; day <= daysInMonth; day++) {
        const key = `${viewYear.value}-${String(viewMonth.value + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        cells.push({
            day,
            key,
            count: props.dayCounts[key] ?? 0,
        });
    }
    return cells;
});

const selectedSubmissions = computed<DaySubmission[]>(
    () => props.dayPage?.data ?? [],
);

const selectedLabel = computed(() =>
    props.day
        ? new Date(`${props.day}T00:00:00`).toLocaleDateString('en-US', {
              weekday: 'long',
              month: 'long',
              day: 'numeric',
              year: 'numeric',
          })
        : null,
);

/** Submit date/time in the viewer's local timezone (medium date + short time). */
function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function monthParam(offset: number): string {
    const date = new Date(viewYear.value, viewMonth.value + offset, 1);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function previousMonth(): void {
    router.get('/calendar', { month: monthParam(-1) });
}

function nextMonth(): void {
    router.get('/calendar', { month: monthParam(1) });
}

function selectDay(cell: DayCell): void {
    if (cell && cell.count > 0) {
        // Spec S4: day detail comes from the server (paginated).
        router.get('/calendar', { month: props.month, day: cell.key });
    }
}

function goToPage(page: number): void {
    router.get(
        '/calendar',
        { month: props.month, day: props.day, page },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Calendar" />

    <main class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Song calendar
                </h1>
                <p class="text-sm text-muted-foreground">
                    Every form submit, grouped by the date it was made.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" @click="previousMonth">
                    ←
                </Button>
                <span class="min-w-40 text-center text-sm font-medium">
                    {{ monthLabel }}
                </span>
                <Button variant="outline" size="sm" @click="nextMonth">
                    →
                </Button>
            </div>
        </header>

        <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <!-- Weekday headers -->
            <div class="mb-2 grid grid-cols-7 gap-1 text-center text-xs font-medium text-muted-foreground">
                <div v-for="label in weekdayLabels" :key="label">
                    {{ label }}
                </div>
            </div>

            <!-- Day grid -->
            <div class="grid grid-cols-7 gap-1">
                <template v-for="(cell, index) in days" :key="cell?.key ?? `pad-${index}`">
                    <div v-if="!cell" class="aspect-square" />
                    <button
                        v-else
                        type="button"
                        class="relative flex aspect-square flex-col items-center justify-center rounded-lg border text-sm transition-colors"
                        :class="
                            cell.count === 0
                                ? 'border-transparent text-muted-foreground/50'
                                : props.day === cell.key
                                  ? 'border-primary bg-primary text-primary-foreground'
                                  : 'border-sidebar-border/70 bg-card hover:border-primary/60 dark:border-sidebar-border'
                        "
                        :disabled="cell.count === 0"
                        @click="selectDay(cell)"
                    >
                        <span>{{ cell.day }}</span>
                        <span
                            v-if="cell.count > 0"
                            class="mt-0.5 rounded-full px-1.5 text-[10px] font-semibold"
                            :class="
                                props.day === cell.key
                                    ? 'bg-primary-foreground/20 text-primary-foreground'
                                    : 'bg-primary/15 text-primary'
                            "
                        >
                            {{ cell.count }}
                        </span>
                    </button>
                </template>
            </div>
        </div>

        <!-- Selected day detail (server-loaded, paginated — spec S4) -->
        <section
            v-if="props.day && selectedSubmissions.length > 0"
            class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <h2 class="mb-3 text-lg font-semibold">
                {{ selectedLabel }}
                <span class="ml-2 text-sm font-normal text-muted-foreground">
                    {{ selectedSubmissions.length }}
                    {{ selectedSubmissions.length === 1 ? 'submit' : 'submits' }}
                </span>
            </h2>
            <ul class="divide-y divide-sidebar-border/50 dark:divide-sidebar-border">
                <li
                    v-for="submission in selectedSubmissions"
                    :key="submission.id"
                    class="flex items-center justify-between gap-4 py-2"
                >
                    <div class="min-w-0">
                        <Link
                            :href="songRoutes.show({ songCode: submission.song_code })"
                            class="block truncate text-sm font-medium hover:text-primary"
                        >
                            {{ submission.track_title }}
                        </Link>
                        <span class="block text-xs text-muted-foreground">
                            Catalogo
                            <span class="font-mono">{{ submission.song_code }}</span>
                            · {{ formatDateTime(submission.created_at) }}
                        </span>
                        <span class="block truncate text-xs text-muted-foreground">
                            Artists: {{ submission.artists || '—' }}{{
                                submission.created_by
                                    ? ` · by ${submission.created_by}`
                                    : ''
                            }}
                        </span>
                    </div>
                </li>
            </ul>

            <nav
                v-if="props.dayPage && props.dayPage.last_page > 1"
                class="mt-4 flex items-center justify-between"
                aria-label="Day pagination"
            >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="props.dayPage.current_page <= 1"
                    @click="goToPage(props.dayPage.current_page - 1)"
                >
                    ← Previous
                </Button>
                <span class="text-sm text-muted-foreground">
                    Page {{ props.dayPage.current_page }} of {{ props.dayPage.last_page }}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="props.dayPage.current_page >= props.dayPage.last_page"
                    @click="goToPage(props.dayPage.current_page + 1)"
                >
                    Next →
                </Button>
            </nav>
        </section>

        <p
            v-else-if="props.day"
            class="rounded-xl border border-sidebar-border/70 p-4 text-sm text-muted-foreground dark:border-sidebar-border"
        >
            No submits registered on this date.
        </p>
        <p
            v-else
            class="text-sm text-muted-foreground"
        >
            Select a highlighted day to see the submits made then.
        </p>
    </main>
</template>
