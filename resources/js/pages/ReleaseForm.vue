<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
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
};

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

const currentStep = ref(0);
const submitting = ref(false);
const showSuccess = ref(false);
const locked = ref(true);

// Spec S3: Publishing — Afrodise by default, English "No cuento con esto".
const publishing = ref<'afrodise' | 'none'>('afrodise');

const release = reactive({
    track_title: '',
    cover_artwork_creative_direction: '',
    audio_link: '',
    genre: '',
    country_recording: '',
    audio_style: '' as '' | 'Vocal' | 'Instrumental',
    writers: '',
    explicit_content: '' as '' | 'Yes' | 'No',
    is_cover: '' as '' | 'Yes' | 'No',
    original_song_link: '',
    original_track_credits: '',
});

const artistCount = ref<'' | '1' | '2' | '3' | '4'>('');
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
    };
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
    artist.role = hit.role;
    artist.country = hit.country;
    artist.city = hit.city;
    artist.estate = hit.estate;
    artist.photo = hit.photo;
    artist.bio = hit.bio;
    artist.status = hit.status;
    for (const f of LINK_FIELDS) {
        artist.links[f.key] = hit.links?.[f.key] ?? '';
    }
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
            'writers',
            'explicit_content',
        ];
        for (const key of required) {
            if (String(release[key]).trim() === '') {
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
        ];
        for (const value of required) {
            if (String(value).trim() === '') {
                window.alert('Please complete all required fields.');
                return false;
            }
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
        router.post(
            releaseForm.url(),
            buildPayload(),
            {
                onFinish: () => {
                    submitting.value = false;
                },
            },
        );
    } catch {
        submitting.value = false;
        window.alert('Submission failed.');
    }
}

function buildPayload() {
    return {
        publishing: publishing.value,
        track_title: release.track_title,
        cover_artwork_creative_direction:
            release.cover_artwork_creative_direction,
        audio_link: release.audio_link,
        genre: release.genre,
        country_recording: release.country_recording,
        audio_style: release.audio_style,
        writers: release.writers,
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
            links: Object.fromEntries(
                LINK_FIELDS.map((f) => [f.key, a.links[f.key] || '']).filter(
                    ([, v]) => String(v).trim() !== '',
                ),
            ),
        })),
    };
}

/* ---------- Disclaimer (same content as the original form) ---------- */

onMounted(() => {
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

            <form id="releaseForm" @submit.prevent>
                <input type="checkbox" name="botcheck" style="display: none" />

                <!-- STEP 0: Release Info -->
                <div v-show="currentStep === 0" class="step active">
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

                    <div class="group">
                        <label>Writers *</label>
                        <textarea v-model="release.writers" required />
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
                <div v-show="currentStep === 1" class="step">
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
                <div v-show="currentStep === 2" class="step">
                    <h3>Artists</h3>

                    <!-- Spec S3: Publishing — before the artist count. -->
                    <div class="group">
                        <label>Publishing *</label>
                        <div class="radio-group">
                            <label class="radio-card">
                                <input
                                    v-model="publishing"
                                    type="radio"
                                    value="afrodise"
                                    required
                                />
                                <span>Afrodise</span>
                            </label>
                            <label class="radio-card">
                                <input
                                    v-model="publishing"
                                    type="radio"
                                    value="none"
                                    required
                                />
                                <span>I don't have this</span>
                            </label>
                        </div>
                    </div>

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
                                <label>Real Name *</label>
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
                                <label>Role *</label>
                                <input
                                    v-model="artist.role"
                                    type="text"
                                    required
                                />
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
                            <textarea v-model="artist.bio" required />
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
                                ? 'Sending...'
                                : currentStep === 2
                                  ? 'Send Form'
                                  : 'Next'
                        }}
                    </button>
                </div>
            </form>

            <div v-if="showSuccess" class="success" style="display: block">
                <h2>Submission Sent</h2>
                <p>Your release has been submitted successfully.</p>
            </div>
        </div>
    </div>
</template>
