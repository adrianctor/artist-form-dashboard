<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import songRoutes from '@/routes/songs';

interface SongRow {
    id: number;
    song_code: string;
    track_title: string | null;
    // Spec: the Versions column is gone — every artist of the track.
    artists: string;
    // Spec: who sent the track's first submit.
    created_by: string;
    updated_at: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface SongPage {
    data: SongRow[];
    current_page: number;
    last_page: number;
    total: number;
    links: PaginationLink[];
}

const props = defineProps<{
    songs: SongPage;
    q: string;
}>();

const filter = ref(props.q);

let debounceTimer: ReturnType<typeof setTimeout> | undefined;

// Spec: server-side search — client-side filtering would only see the
// current page of a table with millions of rows.
watch(filter, (value) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        const query = value.trim();
        router.get(
            songRoutes.index.url(),
            query ? { q: query } : {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }, 300);
});

function formatDate(value: string | null): string {
    if (!value) return '—';

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function openSong(song: SongRow): void {
    router.visit(songRoutes.show.url({ songCode: song.song_code }));
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Songs', href: songRoutes.index() },
        ],
    },
});
</script>

<template>
    <Head title="Songs" />

    <main class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Song history</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Browse the catalog by track, artists or creator.
                </p>
            </div>
            <div class="w-full sm:max-w-sm">
                <label for="songs-filter" class="sr-only">Filter by catalog, track title or artist</label>
                <Input
                    id="songs-filter"
                    v-model="filter"
                    type="search"
                    placeholder="Filter by catalog, track title or artist…"
                    autocomplete="off"
                />
            </div>
        </header>

        <section class="rounded-lg border bg-card">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Catalogo</TableHead>
                        <TableHead>Track Title</TableHead>
                        <TableHead>Artists</TableHead>
                        <TableHead>Created by</TableHead>
                        <TableHead>Last Updated</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="song in songs.data"
                        :key="song.id"
                        class="cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                        tabindex="0"
                        role="link"
                        :aria-label="`View ${song.track_title || song.song_code}`"
                        @click="openSong(song)"
                        @keydown.enter="openSong(song)"
                        @keydown.space.prevent="openSong(song)"
                    >
                        <TableCell class="font-mono text-sm">{{ song.song_code }}</TableCell>
                        <TableCell class="max-w-[18rem] truncate font-medium">
                            {{ song.track_title || 'Untitled song' }}
                        </TableCell>
                        <TableCell class="max-w-[16rem] truncate text-muted-foreground">
                            {{ song.artists }}
                        </TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ song.created_by }}
                        </TableCell>
                        <TableCell class="whitespace-nowrap text-muted-foreground">
                            {{ formatDate(song.updated_at) }}
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="songs.data.length === 0">
                        <TableCell colspan="5" class="h-24 text-center text-muted-foreground">
                            {{ filter ? 'No songs match your filter.' : 'No songs have been submitted yet.' }}
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </section>

        <nav
            v-if="songs.last_page > 1"
            aria-label="Song pages"
            class="flex flex-wrap items-center justify-center gap-2"
        >
            <template v-for="link in songs.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    :aria-current="link.active ? 'page' : undefined"
                    class="rounded-md border px-3 py-1.5 text-sm hover:bg-accent"
                    :class="link.active ? 'border-primary bg-accent font-medium' : 'border-border'"
                    v-html="link.label"
                />
                <span
                    v-else
                    class="rounded-md border border-border px-3 py-1.5 text-sm text-muted-foreground"
                    v-html="link.label"
                />
            </template>
        </nav>
        <p v-if="songs.last_page > 1" class="text-center text-xs text-muted-foreground">
            Page {{ songs.current_page }} of {{ songs.last_page }} · {{ songs.total }} songs
        </p>
    </main>
</template>
