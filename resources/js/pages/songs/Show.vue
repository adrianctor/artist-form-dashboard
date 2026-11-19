<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { dashboard } from '@/routes';
import songRoutes from '@/routes/songs';

type PayloadValue = unknown;
type SongPayload = Record<string, PayloadValue>;

interface SongDetails {
    id: number;
    song_code: string;
    track_title: string | null;
    submission_count: number;
    updated_at: string | null;
}

interface Submission {
    id: number;
    version: number;
    track_title: string | null;
    payload: SongPayload;
    created_at: string | null;
    created_by: string | null;
}

interface ArtistDetails {
    name: string;
    fields: Array<{ label: string; value: string }>;
}

defineProps<{
    song: SongDetails;
    submissions: Submission[];
}>();

function isRecord(value: PayloadValue): value is Record<string, PayloadValue> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isUrl(value: string): boolean {
    try {
        const url = new URL(value);
        return url.protocol === 'https:' || url.protocol === 'http:';
    } catch {
        return false;
    }
}

function stringFields(value: Record<string, PayloadValue>): ArtistDetails['fields'] {
    return Object.entries(value)
        .filter(([, fieldValue]) => fieldValue !== null && fieldValue !== undefined && fieldValue !== '')
        .map(([label, fieldValue]) => ({
            label: label.replaceAll('_', ' '),
            value: typeof fieldValue === 'string' ? fieldValue : stringify(fieldValue),
        }));
}

function artistsFromText(value: string): ArtistDetails[] {
    const sections = value.split(/(?=^\s*ARTIST\s+\d+)/im).filter((section) => section.trim());

    return sections.map((section, index) => {
        const fields: ArtistDetails['fields'] = [];
        const lines = section.split(/\r?\n/);
        const nameMatch = section.match(/^\s*Name:\s*\n\s*([^\n]+)/im);

        for (let lineIndex = 0; lineIndex < lines.length; lineIndex += 1) {
            const label = lines[lineIndex].trim().replace(/:$/, '');
            if (!label || label.toUpperCase().startsWith('ARTIST ') || label.includes('---')) continue;
            if (!/^[A-Za-z][A-Za-z ]*$/.test(label) || !lines[lineIndex].trim().endsWith(':')) continue;

            const fieldValue = lines.slice(lineIndex + 1).find((line) => line.trim())?.trim();
            if (fieldValue) fields.push({ label, value: fieldValue });
        }

        return {
            name: nameMatch?.[1]?.trim() || `Artist ${index + 1}`,
            fields,
        };
    });
}

function artistsFor(payload: SongPayload): ArtistDetails[] {
    if (Array.isArray(payload.artists)) {
        return payload.artists.map((artist, index) => {
            if (typeof artist === 'string') {
                return { name: artist, fields: [] };
            }

            if (isRecord(artist)) {
                const fields = stringFields(artist);
                const name = fields.find((field) => ['name', 'artist name', 'artist_name'].includes(field.label.toLowerCase()))?.value;
                return { name: name || `Artist ${index + 1}`, fields };
            }

            return { name: `Artist ${index + 1}`, fields: [{ label: 'Details', value: stringify(artist) }] };
        });
    }

    return typeof payload.artists_info === 'string' ? artistsFromText(payload.artists_info) : [];
}

function stringify(value: PayloadValue): string {
    if (typeof value === 'string') return value;
    if (value === null || value === undefined) return '—';
    if (typeof value === 'number' || typeof value === 'boolean') return String(value);

    try {
        return JSON.stringify(value, null, 2) ?? String(value);
    } catch {
        return String(value);
    }
}

function labelFor(key: string): string {
    return key
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatDate(value: string | null): string {
    if (!value) return 'Date unavailable';

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'long',
        timeStyle: 'short',
    }).format(new Date(value));
}

const payloadFields = (payload: SongPayload) => Object.entries(payload)
    .filter(([key, value]) => !['artists', 'artists_info'].includes(key) && value !== null && value !== undefined && value !== '');

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Songs', href: songRoutes.index() },
        ],
    },
});
</script>

<template>
    <Head :title="`${song.song_code} · Song history`" />

    <main class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="space-y-3">
            <Link :href="songRoutes.index.url()" class="text-sm text-muted-foreground hover:text-foreground">
                ← All songs
            </Link>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-sm text-muted-foreground">
                        Catalogo
                        <span class="font-mono">{{ song.song_code }}</span>
                    </p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight">{{ song.track_title || 'Untitled song' }}</h1>
                </div>
                <Badge variant="secondary">{{ song.submission_count }} {{ song.submission_count === 1 ? 'version' : 'versions' }}</Badge>
            </div>
        </header>

        <section aria-labelledby="history-heading" class="space-y-4">
            <div>
                <h2 id="history-heading" class="text-xl font-semibold">Submission history</h2>
                <p class="mt-1 text-sm text-muted-foreground">Newest submissions appear first.</p>
            </div>

            <div v-if="submissions.length" class="space-y-4">
                <Card v-for="(submission, index) in submissions" :key="submission.id">
                    <details :open="index === 0" class="group">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-6 py-5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&::-webkit-details-marker]:hidden">
                            <div class="min-w-0">
                                <p class="font-medium">Version {{ submission.version }} · {{ submission.track_title || song.track_title || 'Untitled song' }}</p>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ formatDate(submission.created_at) }}
                                    <!-- Spec S6: the history says who created the submit. -->
                                    <template v-if="submission.created_by">
                                        · Created by <span class="font-medium text-foreground">{{ submission.created_by }}</span>
                                    </template>
                                </p>
                            </div>
                            <span aria-hidden="true" class="shrink-0 text-muted-foreground transition-transform group-open:rotate-180">⌄</span>
                        </summary>
                        <CardContent class="border-t pt-6">
                            <dl v-if="payloadFields(submission.payload).length" class="grid gap-x-8 gap-y-5 sm:grid-cols-2">
                                <div v-for="[key, value] in payloadFields(submission.payload)" :key="key" class="min-w-0 space-y-1">
                                    <dt class="text-sm font-medium">{{ labelFor(key) }}</dt>
                                    <dd v-if="typeof value === 'string' && isUrl(value)" class="break-all text-sm">
                                        <a :href="value" target="_blank" rel="noopener noreferrer" class="text-primary underline underline-offset-4">{{ value }}</a>
                                    </dd>
                                    <dd v-else class="whitespace-pre-wrap break-words text-sm text-muted-foreground">{{ stringify(value) }}</dd>
                                </div>
                            </dl>

                            <section v-if="artistsFor(submission.payload).length" aria-label="Artists" class="mt-6 space-y-3">
                                <h3 class="text-sm font-semibold">Artists</h3>
                                <div class="grid gap-3 lg:grid-cols-2">
                                    <article
                                        v-for="(artist, artistIndex) in artistsFor(submission.payload)"
                                        :key="`${artist.name}-${artistIndex}`"
                                        class="rounded-lg border bg-muted/20 p-4"
                                    >
                                        <h4 class="font-medium">{{ artist.name }}</h4>
                                        <dl v-if="artist.fields.length" class="mt-3 grid gap-3 sm:grid-cols-2">
                                            <div v-for="(field, fieldIndex) in artist.fields" :key="`${field.label}-${fieldIndex}`" class="min-w-0">
                                                <dt class="text-xs font-medium text-muted-foreground">{{ labelFor(field.label) }}</dt>
                                                <dd v-if="isUrl(field.value)" class="mt-1 break-all text-sm">
                                                    <a :href="field.value" target="_blank" rel="noopener noreferrer" class="text-primary underline underline-offset-4">{{ field.value }}</a>
                                                </dd>
                                                <dd v-else class="mt-1 whitespace-pre-wrap break-words text-sm">{{ field.value }}</dd>
                                            </div>
                                        </dl>
                                    </article>
                                </div>
                            </section>

                            <p v-if="payloadFields(submission.payload).length === 0 && artistsFor(submission.payload).length === 0" class="text-sm text-muted-foreground">
                                This submission has no additional form details.
                            </p>
                        </CardContent>
                    </details>
                </Card>
            </div>
            <Card v-else>
                <CardHeader><CardTitle>No submissions found</CardTitle></CardHeader>
                <CardContent class="text-sm text-muted-foreground">There is no submission history for this song yet.</CardContent>
            </Card>
        </section>
    </main>
</template>
