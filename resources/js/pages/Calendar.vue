<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import songRoutes from '@/routes/songs';

type DaySong = {
    song_code: string;
    track_title: string;
    submission_count: number;
    created_at: string;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Calendar', href: '/calendar' },
        ],
    },
});

const props = defineProps<{
    songsByDate: Record<string, DaySong[]>;
}>();

const today = new Date();
const viewYear = ref(today.getFullYear());
const viewMonth = ref(today.getMonth()); // 0-11
const selectedDate = ref<string | null>(null);

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
            count: (props.songsByDate[key] ?? []).length,
        });
    }
    return cells;
});

const selectedSongs = computed<DaySong[]>(() =>
    selectedDate.value ? (props.songsByDate[selectedDate.value] ?? []) : [],
);

const selectedLabel = computed(() =>
    selectedDate.value
        ? new Date(`${selectedDate.value}T00:00:00`).toLocaleDateString('en-US', {
              weekday: 'long',
              month: 'long',
              day: 'numeric',
              year: 'numeric',
          })
        : null,
);

function previousMonth(): void {
    if (viewMonth.value === 0) {
        viewMonth.value = 11;
        viewYear.value -= 1;
    } else {
        viewMonth.value -= 1;
    }
    selectedDate.value = null;
}

function nextMonth(): void {
    if (viewMonth.value === 11) {
        viewMonth.value = 0;
        viewYear.value += 1;
    } else {
        viewMonth.value += 1;
    }
    selectedDate.value = null;
}

function selectDay(cell: DayCell): void {
    if (cell && cell.count > 0) {
        selectedDate.value = cell.key;
    }
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
                    Every registered song, grouped by date.
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
                                : selectedDate === cell.key
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
                                selectedDate === cell.key
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

        <!-- Selected day detail -->
        <section
            v-if="selectedDate && selectedSongs.length > 0"
            class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <h2 class="mb-3 text-lg font-semibold">
                {{ selectedLabel }}
                <span class="ml-2 text-sm font-normal text-muted-foreground">
                    {{ selectedSongs.length }}
                    {{ selectedSongs.length === 1 ? 'song' : 'songs' }}
                </span>
            </h2>
            <ul class="divide-y divide-sidebar-border/50 dark:divide-sidebar-border">
                <li
                    v-for="song in selectedSongs"
                    :key="song.song_code"
                    class="flex items-center justify-between gap-4 py-2"
                >
                    <div class="min-w-0">
                        <Link
                            :href="songRoutes.show({ songCode: song.song_code })"
                            class="block truncate text-sm font-medium hover:text-primary"
                        >
                            {{ song.track_title }}
                        </Link>
                        <span class="font-mono text-xs text-muted-foreground">
                            {{ song.song_code }}
                        </span>
                    </div>
                    <span class="shrink-0 rounded-md bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                        {{ song.submission_count }}
                        {{ song.submission_count === 1 ? 'version' : 'versions' }}
                    </span>
                </li>
            </ul>
        </section>

        <p
            v-else-if="selectedDate"
            class="rounded-xl border border-sidebar-border/70 p-4 text-sm text-muted-foreground dark:border-sidebar-border"
        >
            No songs registered on this date.
        </p>
        <p
            v-else
            class="text-sm text-muted-foreground"
        >
            Select a highlighted day to see the songs registered then.
        </p>
    </main>
</template>
