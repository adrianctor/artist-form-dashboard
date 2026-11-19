<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
    FlexRender,
    columnFilteringFeature,
    createFilteredRowModel,
    globalFilteringFeature,
    tableFeatures,
    useTable,
} from '@tanstack/vue-table';
import type { ColumnDef } from '@tanstack/vue-table';
import { Head, Link, router } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import songRoutes from '@/routes/songs';

interface SongRow {
    id: number;
    song_code: string;
    track_title: string | null;
    submission_count: number;
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
    links: PaginationLink[];
}

const props = defineProps<{ songs: SongPage }>();
const filter = ref('');
const features = tableFeatures({
    columnFilteringFeature,
    globalFilteringFeature,
    filteredRowModel: createFilteredRowModel(),
});

function formatDate(value: string | null): string {
    if (!value) return '—';

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

const columns: ColumnDef<typeof features, SongRow>[] = [
    { accessorKey: 'song_code', header: 'Catalogo' },
    { accessorKey: 'track_title', header: 'Track Title' },
    { accessorKey: 'submission_count', header: 'Versions' },
    {
        accessorKey: 'updated_at',
        header: 'Last Updated',
        cell: ({ getValue }) => formatDate(getValue<string | null>()),
    },
];

const table = useTable({
    features,
    data: computed(() => props.songs.data),
    columns,
    globalFilterFn: (row, _columnId, value) => {
        const query = String(value).trim().toLocaleLowerCase();

        return !query || `${row.original.song_code} ${row.original.track_title ?? ''}`
            .toLocaleLowerCase()
            .includes(query);
    },
});

watch(filter, (value) => table.setGlobalFilter(value));

const visibleRows = computed(() => table.getRowModel().rows);

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
                <p class="mt-1 text-sm text-muted-foreground">Browse songs and their submission versions.</p>
            </div>
            <div class="w-full sm:max-w-sm">
                <label for="songs-filter" class="sr-only">Filter by song code or track title</label>
                <Input
                    id="songs-filter"
                    v-model="filter"
                    type="search"
                    placeholder="Filter by song code or track title…"
                    autocomplete="off"
                />
            </div>
        </header>

        <section class="rounded-lg border bg-card">
            <Table>
                <TableHeader>
                    <TableRow v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id">
                        <TableHead v-for="header in headerGroup.headers" :key="header.id">
                            <FlexRender
                                v-if="!header.isPlaceholder"
                                :header="header"
                            />
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="row in visibleRows"
                        :key="row.id"
                        class="cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                        tabindex="0"
                        role="link"
                        :aria-label="`View ${row.original.track_title || row.original.song_code}`"
                        @click="openSong(row.original)"
                        @keydown.enter="openSong(row.original)"
                        @keydown.space.prevent="openSong(row.original)"
                    >
                        <TableCell v-for="cell in row.getAllCells()" :key="cell.id">
                            <FlexRender :cell="cell" />
                        </TableCell>
                    </TableRow>
                    <TableRow v-if="visibleRows.length === 0">
                        <TableCell colspan="4" class="h-24 text-center text-muted-foreground">
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
                <span v-else class="rounded-md border border-border px-3 py-1.5 text-sm text-muted-foreground" v-html="link.label" />
            </template>
        </nav>
    </main>
</template>
