<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';
import Swal from 'sweetalert2';
import { releaseForm } from '@/routes';
import '@/../css/music-release-form.css';

/**
 * In-app Music Release Form (spec S1) — ported as-is from
 * wordpress/music-release-form.html: 3 steps, disclaimer modal,
 * conditional cover fields, dynamic artist blocks with ARTIST PROFILE
 * STATUS cards, platform links. Added per spec: Publishing radio (S3)
 * and per-artist search/autofill from registered users (S4).
 *
 * Submission (store endpoint) is wired in T6.
 */

type ArtistLinks = Record<string, string>;

type ArtistBlock = {
    searchQuery: string;
    searchResults: SearchHit[];
    searchOpen: boolean;
    name: string;
    realName: string;
    email: string;
    role: string;
    country: string;
    city: string;
    estate: string;
    photo: string;
    bio: string;
    status: '' | 'New Artist' | 'Existing Artist';
    links: ArtistLinks;
    publishing: '' | 'AFRODISE' | 'COPYRIGHT CONTROL' | 'OTHER';
    publishingCustom: string;
    ipiNumber: string;
};

type SearchHit = {
    id: number;
    name: string;
    email: string;
    artist_name: string;
    real_name: string;
    role: string;
    country: string;
    city: string;
    estate: string;
    photo: string;
    bio: string;
    status: 'New Artist' | 'Existing Artist';
    links: ArtistLinks;
    publishing: 'AFRODISE' | 'COPYRIGHT CONTROL' | 'OTHER' | null;
    publishing_custom: string | null;
    ipi_number: string | null;
};

// Spec S3 (profile-writers-publishing): one writer row = profile real name
// select + "Rol del escritor" (3-value select). n rows, add/remove.
type WriterRow = { name: string; role: '' | 'Primary' | 'Featuring' | 'Remix' };

type ProfileOption = {
    id: number;
    real_name: string;
    artist_name: string;
};

// Specs S2 + S3: the artist role and the writer role share the same
// three values (the writer role has NO free-text option).
const ROLE_OPTIONS = ['Primary', 'Featuring', 'Remix'] as const;

const PUBLISHING_OPTIONS = [
    { value: 'AFRODISE', label: 'AFRODISE' },
    { value: 'COPYRIGHT CONTROL', label: 'COPYRIGHT CONTROL' },
    { value: 'OTHER', label: 'OTHER / CUSTOM PUBLISHING' },
] as const;


const LINK_FIELDS = [
    { key: 'spotify', label: 'Spotify Artist Link', core: true },
    { key: 'apple_music', label: 'Apple Music Artist Link', core: true },
    { key: 'beatport', label: 'Beatport Link', core: true },
    { key: 'youtube', label: 'YouTube Channel Link', core: false },
    { key: 'instagram', label: 'Instagram Profile Link', core: false },
    { key: 'facebook', label: 'Facebook Profile Link', core: false },
    { key: 'x', label: 'X Profile Link', core: false },
    { key: 'tiktok', label: 'TikTok Profile Link', core: false },
    { key: 'soundcloud', label: 'Soundcloud Profile Link', core: false },
    { key: 'songkick', label: 'Songkick Link', core: false },
    { key: 'bandsintown', label: 'Bandsintown Link', core: false },
    { key: 'website', label: 'Website Link', core: false },
] as const;

// Spec S11: when set, the page edits an existing submit instead of
// creating a new one (prefilled, no disclaimer, PATCH on submit).
const props = withDefaults(
    defineProps<{
        editSubmission?: { id: number; payload: Record<string, unknown> } | null;
    }>(),
    { editSubmission: null },
);

const isEdit = computed(() => props.editSubmission !== null);

const page = usePage();
const currentStep = ref(0);
const submitting = ref(false);
const locked = ref(!isEdit.value);

// Spec S5: server validation errors (422 -> redirect back) must be visible
// on the form — never fail silently.
const errors = computed<Record<string, string | string[]>>(
    () =>
        (page.props as { errors?: Record<string, string | string[]> }).errors ??
        {},
);
const errorList = computed(() => [
    ...new Set(Object.values(errors.value).flat().filter(Boolean)),
]);

// Spec S3 (profile-writers-publishing): publishing lives PER ARTIST now —
// the old global radio (Afrodise / I don't have this) is gone.

const release = reactive({
    track_title: '',
    cover_artwork_creative_direction: '',
    audio_link: '',
    genre: '',
    country_recording: '',
    audio_style: '' as '' | 'Vocal' | 'Instrumental',
    explicit_content: '' as '' | 'Yes' | 'No',
    is_cover: '' as '' | 'Yes' | 'No',
    original_song_link: '',
    original_track_credits: '',
});

// Spec S3: writers as a dynamic list (min 1 row), replacing the textarea.
const writers = ref<WriterRow[]>([{ name: '', role: '' }]);

// Spec S3: every profile's real name for the writers select.
const profileOptions = ref<ProfileOption[]>([]);

const artistCount = ref<string>('');
const artists = ref<ArtistBlock[]>([]);

const coverFieldsVisible = computed(() => release.is_cover === 'Yes');

function emptyArtist(): ArtistBlock {
    const links: ArtistLinks = {};
    for (const f of LINK_FIELDS) links[f.key] = '';

    return {
        searchQuery: '',
        searchResults: [],
        searchOpen: false,
        name: '',
        realName: '',
        email: '',
        role: '',
        country: '',
        city: '',
        estate: '',
        photo: '',
        bio: '',
        status: '',
        links,
        publishing: '',
        publishingCustom: '',
        ipiNumber: '',
    };
}

/* ---------- Writers list (spec S3) ---------- */

function addWriter() {
    writers.value.push({ name: '', role: '' });
}

function removeWriter(index: number) {
    if (writers.value.length <= 1) return;
    writers.value.splice(index, 1);
}

async function loadProfileOptions() {
    try {
        const res = await fetch('/profiles', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) return;
        profileOptions.value = (await res.json()) as ProfileOption[];
    } catch {
        /* network error → keep the select empty */
    }
}

function onArtistCountChange() {
    const count = parseInt(artistCount.value || '0', 10);
    const next: ArtistBlock[] = [];
    for (let i = 0; i < count; i++) {
        next.push(artists.value[i] ?? emptyArtist());
    }
    artists.value = next;
}

/* ---------- Artist search / autofill (spec S4) ---------- */

let searchTimer: ReturnType<typeof setTimeout> | undefined;

function onSearchInput(index: number) {
    const artist = artists.value[index];
    clearTimeout(searchTimer);
    const q = artist.searchQuery.trim();
    if (q.length < 2) {
        artist.searchResults = [];
        artist.searchOpen = false;
        return;
    }
    searchTimer = setTimeout(() => void runSearch(index, q), 250);
}

async function runSearch(index: number, q: string) {
    const artist = artists.value[index];
    try {
        const res = await fetch(`/artist-search?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) return;
        const hits: SearchHit[] = await res.json();
        // Ignore stale responses.
        if (artists.value[index]?.searchQuery.trim() !== q) return;
        artist.searchResults = hits;
        artist.searchOpen = hits.length > 0;
    } catch {
        /* network error → keep UI quiet */
    }
}

function applySearchHit(index: number, hit: SearchHit) {
    const artist = artists.value[index];
    artist.name = hit.artist_name;
    artist.realName = hit.real_name;
    artist.email = hit.email;
    // Spec S2: a legacy role outside the 3-value select starts empty so
    // the user picks one of Primary / Featuring / Remix.
    artist.role = ROLE_OPTIONS.includes(hit.role as (typeof ROLE_OPTIONS)[number])
        ? hit.role
        : '';
    artist.country = hit.country;
    artist.city = hit.city;
    artist.estate = hit.estate;
    artist.photo = hit.photo;
    artist.bio = hit.bio;
    artist.status = hit.status;
    for (const f of LINK_FIELDS) {
        artist.links[f.key] = hit.links?.[f.key] ?? '';
    }
    // Spec S5: publishing/IPI autofill from the profile, editable after.
    artist.publishing = hit.publishing ?? '';
    artist.publishingCustom = hit.publishing_custom ?? '';
    artist.ipiNumber = hit.ipi_number ?? '';
    artist.searchQuery = hit.artist_name;
    artist.searchOpen = false;
    artist.searchResults = [];
}

function closeSearchLater(index: number) {
    setTimeout(() => {
        if (artists.value[index]) artists.value[index].searchOpen = false;
    }, 200);
}

/* ---------- Step navigation / validation ---------- */

function validateStep(step: number): boolean {
    if (step === 0) {
        const required: (keyof typeof release)[] = [
            'track_title',
            'cover_artwork_creative_direction',
            'audio_link',
            'genre',
            'country_recording',
            'audio_style',
            'explicit_content',
        ];
        for (const key of required) {
            if (String(release[key]).trim() === '') {
                window.alert('Please complete all required fields.');
                return false;
            }
        }
        // Spec S3: every writer row needs a profile name and a role.
        for (const row of writers.value) {
            if (row.name.trim() === '' || row.role === '') {
                window.alert('Please complete all required fields.');
                return false;
            }
        }
        return true;
    }

    if (step === 1) {
        if (release.is_cover === '') {
            window.alert('Please complete all required fields.');
            return false;
        }
        if (
            release.is_cover === 'Yes' &&
            (release.original_song_link.trim() === '' ||
                release.original_track_credits.trim() === '')
        ) {
            window.alert('Please complete all required fields.');
            return false;
        }
        return true;
    }

    // Step 2: artists.
    if (artistCount.value === '') {
        window.alert('Please complete all required fields.');
        return false;
    }
    for (const artist of artists.value) {
        const required = [
            artist.name,
            artist.realName,
            artist.email,
            artist.role,
            artist.country,
            artist.city,
            artist.estate,
            artist.photo,
            artist.bio,
            artist.status,
            artist.publishing,
        ];
        for (const value of required) {
            if (String(value).trim() === '') {
                window.alert('Please complete all required fields.');
                return false;
            }
        }
        // Spec S6: the custom company name is required when OTHER.
        if (artist.publishing === 'OTHER' && artist.publishingCustom.trim() === '') {
            window.alert('Please complete all required fields.');
            return false;
        }
        // Spec S4: bio is capped at 500 characters.
        if (artist.bio.length > 500) {
            window.alert('Artist Bio must be 500 characters or fewer.');
            return false;
        }
        // Spec S2: the artist role is a 3-value select (legacy free text
        // from an old profile is not a valid value).
        if (!ROLE_OPTIONS.includes(artist.role as (typeof ROLE_OPTIONS)[number])) {
            window.alert('Please complete all required fields.');
            return false;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(artist.email)) {
            window.alert('Please complete all required fields.');
            return false;
        }
        if (artist.status === 'Existing Artist') {
            const hasLink = LINK_FIELDS.some((f) =>
                String(artist.links[f.key] || '').trim() !== '',
            );
            if (!hasLink) {
                window.alert(
                    'Please provide at least one platform link for an existing artist.',
                );
                return false;
            }
        }
    }
    return true;
}

function nextStep() {
    if (!validateStep(currentStep.value)) return;
    if (currentStep.value < 2) {
        currentStep.value += 1;
        return;
    }
    void submitForm();
}

function prevStep() {
    if (currentStep.value > 0) currentStep.value -= 1;
}

/* ---------- Submit (wired to POST /release-form in T6) ---------- */

async function submitForm() {
    if (submitting.value) return;
    submitting.value = true;
    try {
        const options = {
            onFinish: () => {
                submitting.value = false;
            },
        };

        if (props.editSubmission) {
            // Spec S11: edit in place — never creates a new version.
            router.patch(`/submissions/${props.editSubmission.id}`, buildPayload(), options);
        } else {
            router.post(releaseForm.url(), buildPayload(), options);
        }
    } catch {
        submitting.value = false;
        window.alert('Submission failed.');
    }
}

function buildPayload() {
    return {
        track_title: release.track_title,
        cover_artwork_creative_direction:
            release.cover_artwork_creative_direction,
        audio_link: release.audio_link,
        genre: release.genre,
        country_recording: release.country_recording,
        audio_style: release.audio_style,
        // Spec S3: writers as [{name, role}] — no more free-text textarea.
        writers: writers.value.map((w) => ({
            name: w.name.trim(),
            role: w.role,
        })),
        explicit_content: release.explicit_content,
        is_cover: release.is_cover,
        original_song_link:
            release.is_cover === 'Yes' ? release.original_song_link : '',
        original_track_credits:
            release.is_cover === 'Yes' ? release.original_track_credits : '',
        artists: artists.value.map((a) => ({
            name: a.name,
            real_name: a.realName,
            email: a.email,
            role: a.role,
            country: a.country,
            city: a.city,
            estate: a.estate,
            photo: a.photo,
            bio: a.bio,
            status: a.status,
            // Spec S5: publishing per artist (was a global radio).
            publishing: a.publishing,
            publishing_custom:
                a.publishing === 'OTHER' ? a.publishingCustom.trim() : '',
            ipi_number: a.ipiNumber.trim(),
            links: Object.fromEntries(
                LINK_FIELDS.map((f) => [f.key, a.links[f.key] || '']).filter(
                    ([, v]) => String(v).trim() !== '',
                ),
            ),
        })),
    };
}

/* ---------- Edit mode: prefill from the stored payload (spec S11) ---------- */

function prefillFromPayload(payload: Record<string, any>): void {
    release.track_title = String(payload.track_title ?? '');
    release.cover_artwork_creative_direction = String(
        payload.cover_artwork_creative_direction ?? '',
    );
    release.audio_link = String(payload.audio_link ?? '');
    release.genre = String(payload.genre ?? '');
    release.country_recording = String(payload.country_recording ?? '');
    release.audio_style = (payload.audio_style as 'Vocal' | 'Instrumental') || '';
    release.explicit_content =
        (payload.explicit_content as 'Yes' | 'No') || '';
    release.is_cover = (payload.is_cover as 'Yes' | 'No') || '';
    release.original_song_link = String(payload.original_song_link ?? '');
    release.original_track_credits = String(
        payload.original_track_credits ?? '',
    );

    // Spec S3: writers come as [{name, role}]; a legacy free-text string
    // becomes one row per line so old submits stay editable.
    if (Array.isArray(payload.writers)) {
        writers.value = payload.writers.map((w: Record<string, any>) => ({
            name: String(w?.name ?? ''),
            role: ROLE_OPTIONS.includes(w?.role)
                ? w.role
                : '',
        }));
    } else {
        const legacy = String(payload.writers ?? '')
            .split('\n')
            .map((line: string) => line.trim())
            .filter(Boolean);
        writers.value = legacy.length
            ? legacy.map((name: string) => ({ name, role: '' as const }))
            : [{ name: '', role: '' as const }];
    }

    // Spec S5: legacy payloads had a global publishing radio — seed every
    // artist from it (afrodise → AFRODISE, none → empty) so the per-artist
    // select starts from something sensible; the rest prefills per artist.
    const legacyPublishing =
        payload.publishing === 'afrodise' ? 'AFRODISE' : '';

    const list = Array.isArray(payload.artists) ? payload.artists : [];
    artistCount.value = list.length > 0 ? String(list.length) : '';
    artists.value = list.map((raw: Record<string, any>) => {
        const block = emptyArtist();
        block.searchQuery = String(raw.name ?? '');
        block.name = String(raw.name ?? '');
        block.realName = String(raw.real_name ?? '');
        block.email = String(raw.email ?? '');
        // Spec S2: legacy roles outside the select start empty.
        block.role = ROLE_OPTIONS.includes(raw.role)
            ? raw.role
            : '';
        block.country = String(raw.country ?? '');
        block.city = String(raw.city ?? '');
        block.estate = String(raw.estate ?? '');
        block.photo = String(raw.photo ?? '');
        block.bio = String(raw.bio ?? '');
        block.status =
            raw.status === 'New Artist' || raw.status === 'Existing Artist'
                ? raw.status
                : '';
        block.publishing = PUBLISHING_OPTIONS.some(
            (o) => o.value === raw.publishing,
        )
            ? raw.publishing
            : (legacyPublishing as ArtistBlock['publishing']);
        block.publishingCustom = String(raw.publishing_custom ?? '');
        block.ipiNumber = String(raw.ipi_number ?? '');
        if (raw.links && typeof raw.links === 'object') {
            for (const field of LINK_FIELDS) {
                block.links[field.key] = String(raw.links[field.key] ?? '');
            }
        }
        return block;
    });
}

/* ---------- Disclaimer (same content as the original form) ---------- */

onMounted(() => {
    // Spec S3: the writers select needs every profile's real name
    // (both for a new form and when editing an old submit).
    void loadProfileOptions();

    if (props.editSubmission) {
        prefillFromPayload(props.editSubmission.payload);
        return;
    }

    void Swal.fire({
        title: 'Important Information',
        width: 900,
        allowOutsideClick: false,
        allowEscapeKey: false,
        allowEnterKey: false,
        confirmButtonText: 'I Have Read and Accept',
        confirmButtonColor: '#4f46e5',
        html: `
        <div style="text-align:left;max-height:500px;overflow-y:auto;line-height:1.6;padding-right:10px;color:#545454;">
            <p>Thank you for taking the time to complete this form.</p>
            <p>The information provided will be used directly for the creation, distribution and official release of your music across digital platforms, online music stores and streaming services worldwide. For this reason, we kindly ask that you carefully review all information before submitting this form.</p>
            <p><strong>Please pay particular attention to the following:</strong></p>
            <ul>
                <li>Release title (Single, EP or Album).</li>
                <li>Track title(s).</li>
                <li>Artist name(s), including all primary and featured artists.</li>
                <li>Songwriting, production and participation credits.</li>
                <li>Correct use of capital letters, lower-case letters, accents, punctuation marks, symbols and special characters.</li>
            </ul>
            <p>It is essential that all information is entered exactly as you wish it to appear publicly on digital platforms. The details provided in this form will be treated as the official reference for the delivery and publication of your release.</p>
            <p>If you already have existing artist profiles on digital platforms, you must provide the relevant profile links where requested within this form. This may include, but is not limited to:</p>
            <ul>
                <li>Spotify</li>
                <li>Apple Music</li>
                <li>Beatport</li>
                <li>Traxsource</li>
                <li>SoundCloud</li>
                <li>YouTube Artist Channel</li>
                <li>Any other platform where your artist profile is currently active</li>
            </ul>
            <p>Providing these links is extremely important to ensure your release is delivered to the correct artist profiles and to avoid the creation of duplicate pages or artist profile mismatches.</p>
            <p>If you are a new artist and do not yet have official profiles on the platforms listed above, these links will not be required. In such cases, the platforms will automatically generate your artist profiles upon the release of your first publication.</p>
            <p>This process generally occurs only once and allows future releases to be correctly linked to your newly established artist pages.</p>
            <p>By submitting this form, you confirm that all information provided has been carefully reviewed, verified and approved by you for distribution and publication purposes.</p>
            <p>Please note that any discrepancies, typographical errors, omissions or inaccurate information resulting from the completion of this form will be deemed to originate from the information supplied by the applicant.</p>
            <p>Whilst corrections may be possible in certain circumstances, amendments are subject to the policies, approval processes and update schedules of individual digital platforms and music stores.</p>
            <p>As a result, corrections may take several weeks to be processed and reflected across all services. Such amendments may also affect release schedules, marketing plans, promotional campaigns, platform visibility and, in some cases, the intended release date itself.</p>
            <p>To ensure a smooth distribution process and avoid unnecessary delays, we kindly encourage you to review all information carefully before proceeding.</p>
            <p><strong>By continuing with this form, you acknowledge and accept the terms outlined above.</strong></p>
        </div>`,
    }).then((result) => {
        if (result.isConfirmed) locked.value = false;
    });
});
</script>

<template>
    <Head title="Music Release Form" />

    <div id="musicReleaseForm" :class="{ locked }">
        <div class="card">
            <h2>Music Release Form</h2>
            <p>Complete all required information.</p>

            <div class="steps">
                <div
                    v-for="i in 3"
                    :key="i"
                    class="step-dot"
                    :class="{ active: i - 1 <= currentStep }"
                />
            </div>

            <!-- Spec S5: visible server-side validation errors (422). -->
            <div
                v-if="errorList.length"
                role="alert"
                class="mb-4 rounded-lg border border-red-500/40 bg-red-500/10 p-4 text-sm text-red-200"
            >
                <p class="mb-2 font-semibold">The form could not be sent:</p>
                <ul class="list-inside list-disc space-y-1">
                    <li v-for="message in errorList" :key="message">
                        {{ message }}
                    </li>
                </ul>
            </div>

            <form id="releaseForm" @submit.prevent>
                <input type="checkbox" name="botcheck" style="display: none" />

                <!-- STEP 0: Release Info -->
                <div v-show="currentStep === 0" :class="['step', { active: currentStep === 0 }]">
                    <h3>Release Info</h3>
                    <div class="grid">
                        <div class="group">
                            <label>Track Title *</label>
                            <input
                                v-model="release.track_title"
                                type="text"
                                placeholder="Track Title"
                                required
                            />
                            <small class="field-help">
                                <strong>Example:</strong><br />
                                Aaron Sevilla, Badrops, Wrigley - Ya Zamani
                                (Original Mix)
                                <br /><br />
                                <strong>Format:</strong><br />
                                Artist 1, Artist 2, Artist 3 - Track Name
                                (Version)
                            </small>
                        </div>

                        <div class="group" style="grid-column: 1 / -1">
                            <label>Cover Artwork Creative Direction *</label>
                            <textarea
                                v-model="release.cover_artwork_creative_direction"
                                placeholder="Please tell us which visual elements best represent this release. You may mention colours, textures, styles, atmospheres, emotions, concepts, or any ideas that help convey the identity and energy of the music."
                                required
                            />
                            <small
                                style="
                                    display: block;
                                    margin-top: 10px;
                                    line-height: 1.6;
                                    opacity: 0.75;
                                "
                            >
                                Please tell us which visual elements best
                                represent this release. You may mention colours,
                                textures, styles, atmospheres, emotions,
                                concepts, or any ideas that help convey the
                                identity and energy of the music.
                                <br /><br />
                                Our creative team will use this information as a
                                guide to develop a visual concept that aligns
                                with the artistic vision of the project.
                            </small>
                        </div>

                        <div class="group">
                            <label>
                                Audio master link (Google Drive or Dropbox) *
                            </label>
                            <input
                                v-model="release.audio_link"
                                type="text"
                                placeholder="Please provide a link to the extended version and master radio at 44100 - 16 bit WAV on Google Drive or Dropbox."
                                required
                            />
                        </div>

                        <div class="group">
                            <label>Genre *</label>
                            <input
                                v-model="release.genre"
                                type="text"
                                placeholder="Please write the genre"
                                required
                            />
                        </div>

                        <div class="group">
                            <label>Country Of Recording *</label>
                            <input
                                v-model="release.country_recording"
                                type="text"
                                placeholder="Please write the country of recording"
                                required
                            />
                        </div>

                        <div class="group">
                            <label>Audio Style *</label>
                            <select v-model="release.audio_style" required>
                                <option value="">Select</option>
                                <option value="Vocal">Vocal</option>
                                <option value="Instrumental">
                                    Instrumental
                                </option>
                            </select>
                        </div>
                    </div>

                    <!-- Spec S3 (profile-writers-publishing): dynamic list
                         of n writers — profile real name + writer role. -->
                    <div class="group">
                        <label>Writers *</label>
                        <div class="space-y-3">
                            <div
                                v-for="(row, index) in writers"
                                :key="index"
                                class="flex flex-wrap items-end gap-3 rounded-lg border border-white/10 bg-white/5 p-3"
                            >
                                <div class="min-w-[220px] flex-1">
                                    <label
                                        class="mb-1 block text-xs opacity-70"
                                        :for="`writer_name_${index}`"
                                        >Writer *</label
                                    >
                                    <select
                                        :id="`writer_name_${index}`"
                                        v-model="row.name"
                                        required
                                    >
                                        <option value="">
                                            Select a writer
                                        </option>
                                        <option
                                            v-for="profile in profileOptions"
                                            :key="profile.id"
                                            :value="profile.real_name"
                                        >
                                            {{ profile.real_name }}
                                        </option>
                                    </select>
                                </div>
                                <div class="min-w-[180px] flex-1">
                                    <label
                                        class="mb-1 block text-xs opacity-70"
                                        :for="`writer_role_${index}`"
                                        >Rol del escritor *</label
                                    >
                                    <select
                                        :id="`writer_role_${index}`"
                                        v-model="row.role"
                                        required
                                    >
                                        <option value="">Select</option>
                                        <option
                                            v-for="option in ROLE_OPTIONS"
                                            :key="option"
                                            :value="option"
                                        >
                                            {{ option }}
                                        </option>
                                    </select>
                                </div>
                                <button
                                    v-if="writers.length > 1"
                                    type="button"
                                    class="rounded-md border border-white/20 px-3 py-2 text-sm opacity-80 hover:opacity-100"
                                    @click="removeWriter(index)"
                                >
                                    Remove
                                </button>
                            </div>
                            <button
                                type="button"
                                class="rounded-md bg-white px-4 py-2 text-sm font-semibold text-black hover:bg-white/90"
                                @click="addWriter"
                            >
                                + Add writer
                            </button>
                        </div>
                    </div>

                    <div class="group">
                        <label>Explicit Content *</label>
                        <div class="radio-group">
                            <label class="radio-card">
                                <input
                                    v-model="release.explicit_content"
                                    type="radio"
                                    value="Yes"
                                    required
                                />
                                <span>Yes</span>
                            </label>
                            <label class="radio-card">
                                <input
                                    v-model="release.explicit_content"
                                    type="radio"
                                    value="No"
                                    required
                                />
                                <span>No</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- STEP 1: Cover Information -->
                <div v-show="currentStep === 1" :class="['step', { active: currentStep === 1 }]">
                    <h3>Cover Information</h3>
                    <div class="group">
                        <label>Is this song a cover? *</label>
                        <div class="radio-group">
                            <label class="radio-card">
                                <input
                                    v-model="release.is_cover"
                                    type="radio"
                                    value="Yes"
                                    required
                                />
                                <span>Yes</span>
                            </label>
                            <label class="radio-card">
                                <input
                                    v-model="release.is_cover"
                                    type="radio"
                                    value="No"
                                    required
                                />
                                <span>No</span>
                            </label>
                        </div>
                    </div>

                    <div v-if="coverFieldsVisible">
                        <div class="group">
                            <label>
                                Please link Spotify or YouTube original song *
                            </label>
                            <input
                                v-model="release.original_song_link"
                                type="url"
                                required
                            />
                        </div>
                        <div class="group">
                            <label>
                                Write the credits or names of the authors or
                                composers of the original track *
                            </label>
                            <textarea
                                v-model="release.original_track_credits"
                                required
                            />
                        </div>
                    </div>
                </div>

                <!-- STEP 2: Artists -->
                <div v-show="currentStep === 2" :class="['step', { active: currentStep === 2 }]">
                    <h3>Artists</h3>

                    <!-- Spec S5: publishing moved INTO each artist block
                         (was a global radio before the artist count). -->

                    <div class="group">
                        <label>Number Of Artists *</label>
                        <select
                            v-model="artistCount"
                            required
                            @change="onArtistCountChange"
                        >
                            <option value="">Select</option>
                            <option value="1">1 Artist</option>
                            <option value="2">2 Artists</option>
                            <option value="3">3 Artists</option>
                            <option value="4">4 Artists</option>
                        </select>
                    </div>

                    <div
                        v-for="(artist, index) in artists"
                        :key="index"
                        class="artist-block"
                    >
                        <h3>Artist {{ index + 1 }} Info</h3>

                        <!-- Spec S4: search registered users to autofill. -->
                        <div class="group" style="position: relative">
                            <label>Search registered artist *</label>
                            <input
                                v-model="artist.searchQuery"
                                type="text"
                                placeholder="Search by name or email"
                                @input="onSearchInput(index)"
                                @focus="
                                    artist.searchOpen =
                                        artist.searchResults.length > 0
                                "
                                @blur="closeSearchLater(index)"
                            />
                            <div
                                v-if="
                                    artist.searchOpen &&
                                    artist.searchResults.length > 0
                                "
                                style="
                                    position: absolute;
                                    z-index: 20;
                                    top: 100%;
                                    left: 0;
                                    right: 0;
                                    background: #111;
                                    border: 1px solid rgba(255, 255, 255, 0.15);
                                    border-radius: 12px;
                                    overflow: hidden;
                                "
                            >
                                <button
                                    v-for="hit in artist.searchResults"
                                    :key="hit.id"
                                    type="button"
                                    style="
                                        display: block;
                                        width: 100%;
                                        padding: 12px 14px;
                                        background: transparent;
                                        border: none;
                                        color: #fff;
                                        text-align: left;
                                        cursor: pointer;
                                    "
                                    @mousedown.prevent="
                                        applySearchHit(index, hit)
                                    "
                                >
                                    <strong>{{ hit.artist_name }}</strong> —
                                    {{ hit.email }}
                                </button>
                            </div>
                        </div>

                        <div class="grid">
                            <div class="group">
                                <label>Artist Name *</label>
                                <input
                                    v-model="artist.name"
                                    type="text"
                                    required
                                />
                            </div>
                            <div class="group">
                                <label>First name and last name *</label>
                                <input
                                    v-model="artist.realName"
                                    type="text"
                                    required
                                />
                            </div>
                            <div class="group">
                                <label>Email *</label>
                                <input
                                    v-model="artist.email"
                                    type="email"
                                    required
                                />
                            </div>
                            <div class="group">
                                <label>Rol *</label>
                                <select v-model="artist.role" required>
                                    <option value="">Select</option>
                                    <option
                                        v-for="option in ROLE_OPTIONS"
                                        :key="option"
                                        :value="option"
                                    >
                                        {{ option }}
                                    </option>
                                </select>
                            </div>
                            <div class="group">
                                <label>Artist Country *</label>
                                <input
                                    v-model="artist.country"
                                    type="text"
                                    required
                                />
                            </div>
                            <div class="group">
                                <label>Artist City *</label>
                                <input
                                    v-model="artist.city"
                                    type="text"
                                    required
                                />
                            </div>
                            <div class="group">
                                <label>Artist Estate *</label>
                                <input
                                    v-model="artist.estate"
                                    type="text"
                                    required
                                />
                            </div>
                            <div class="group">
                                <label>
                                    Provide a link to a photograph, Google
                                    Drive, or Dropbox *
                                </label>
                                <input
                                    v-model="artist.photo"
                                    type="url"
                                    required
                                />
                            </div>
                        </div>

                        <div class="group">
                            <label>Artist Bio *</label>
                            <textarea
                                v-model="artist.bio"
                                maxlength="500"
                                required
                            />
                        </div>

                        <!-- Spec S5: publishing per artist + IPI, autofilled
                             from the profile and editable here. -->
                        <div class="grid">
                            <div class="group">
                                <label>Publishing *</label>
                                <select
                                    v-model="artist.publishing"
                                    required
                                >
                                    <option value="">Select</option>
                                    <option
                                        v-for="option in PUBLISHING_OPTIONS"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>
                            <div class="group">
                                <label>IPI Number</label>
                                <input
                                    v-model="artist.ipiNumber"
                                    type="text"
                                    placeholder="Enter IPI Number (if available)"
                                />
                            </div>
                            <div
                                v-if="artist.publishing === 'OTHER'"
                                class="group"
                                style="grid-column: 1 / -1"
                            >
                                <label
                                    >Write the publishing company name *</label
                                >
                                <input
                                    v-model="artist.publishingCustom"
                                    type="text"
                                    placeholder="Write the publishing company name"
                                    required
                                />
                            </div>
                        </div>

                        <!-- ARTIST PROFILE STATUS -->
                        <div class="artist-profile-status">
                            <h4>ARTIST PROFILE STATUS</h4>
                            <div class="subtitle">
                                Have you previously released music on any
                                digital platform under this artist name?
                            </div>

                            <div class="artist-status-options">
                                <label class="artist-status-card">
                                    <input
                                        v-model="artist.status"
                                        type="radio"
                                        value="New Artist"
                                        required
                                    />
                                    <div class="artist-status-content">
                                        <div class="artist-status-icon" />
                                        <div class="artist-status-title">
                                            No, this is a new artist profile
                                        </div>
                                        <div class="artist-status-text">
                                            This artist does not currently have
                                            any official profiles on digital
                                            streaming platforms.
                                            <br /><br />
                                            Profile links are optional and may
                                            be left blank.
                                            <br /><br />
                                            New artist profiles will be
                                            automatically created by the
                                            platforms upon the release of their
                                            first publication.
                                        </div>
                                    </div>
                                </label>

                                <label class="artist-status-card">
                                    <input
                                        v-model="artist.status"
                                        type="radio"
                                        value="Existing Artist"
                                        required
                                    />
                                    <div class="artist-status-content">
                                        <div class="artist-status-icon" />
                                        <div class="artist-status-title">
                                            Yes, this artist already has
                                            existing profiles on one or more
                                            digital platforms
                                        </div>
                                        <div class="artist-status-text">
                                            This artist has previously released
                                            music under this artist name and
                                            already has active profiles on one
                                            or more digital platforms.
                                            <br /><br />
                                            To ensure correct delivery and avoid
                                            duplicate artist pages, all
                                            existing artist profile links must
                                            be provided.
                                            <br /><br />
                                            Failure to provide accurate profile
                                            links may result in releases being
                                            delivered to incorrect artist pages,
                                            creation of duplicate profiles,
                                            release delays, metadata corrections
                                            and additional processing time.
                                        </div>
                                    </div>
                                </label>
                            </div>

                            <div class="artist-status-info">
                                <div class="artist-status-info-icon">i</div>
                                <div class="artist-status-info-text">
                                    By selecting "Existing Artist", you confirm
                                    that the artist name provided has been
                                    previously released commercially through a
                                    record label, distributor or self-release on
                                    one or more digital music platforms.
                                    <br /><br />
                                    You acknowledge that providing the
                                    corresponding artist profile links is
                                    mandatory for accurate distribution and
                                    profile matching purposes.
                                </div>
                            </div>
                        </div>

                        <h3>Links</h3>
                        <div class="grid">
                            <div
                                v-for="field in LINK_FIELDS"
                                :key="field.key"
                                class="group"
                            >
                                <label>
                                    {{ field.label }}
                                    <span
                                        v-if="
                                            artist.status ===
                                                'Existing Artist' && field.core
                                        "
                                        class="required-star"
                                        >*</span
                                    >
                                </label>
                                <input
                                    v-model="artist.links[field.key]"
                                    type="url"
                                    :required="
                                        artist.status === 'Existing Artist' &&
                                        field.core
                                    "
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="actions">
                    <button
                        v-if="currentStep > 0"
                        type="button"
                        class="prev"
                        @click="prevStep"
                    >
                        Previous
                    </button>
                    <span v-else />
                    <button
                        type="button"
                        class="next"
                        :class="{ submit: currentStep === 2 }"
                        :disabled="submitting"
                        @click="nextStep"
                    >
                        {{
                            submitting
                                ? isEdit
                                    ? 'Saving...'
                                    : 'Sending...'
                                : currentStep === 2
                                  ? isEdit
                                        ? 'Save changes'
                                        : 'Send Form'
                                  : 'Next'
                        }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
