<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ArtistProfileController from '@/actions/App/Http/Controllers/ArtistProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type ArtistProfile = {
    artist_name: string;
    real_name: string;
    role: string;
    country: string;
    city: string;
    estate: string;
    photo: string;
    bio: string;
    status: 'New Artist' | 'Existing Artist';
    links: Record<string, string> | null;
    publishing: 'AFRODISE' | 'COPYRIGHT CONTROL' | 'OTHER' | null;
    publishing_custom: string | null;
    ipi_number: string | null;
} | null;

const props = defineProps<{
    profile: ArtistProfile;
    user: { name: string; email: string };
}>();

const isNew = computed(() => props.profile === null);
const isExisting = computed(() => props.profile?.status === 'Existing Artist');

// Spec S2 (profile-writers-publishing): the artist role is a 3-value select.
const roleOptions = ['Primary', 'Featuring', 'Remix'] as const;

// Spec S6: publishing select + conditional custom company name.
// Reactive so the custom field activates the moment OTHER is picked.
const publishing = ref(props.profile?.publishing ?? '');
const publishingCustom = ref(props.profile?.publishing_custom ?? '');

const publishingOptions = [
    { value: 'AFRODISE', label: 'AFRODISE' },
    { value: 'COPYRIGHT CONTROL', label: 'COPYRIGHT CONTROL' },
    { value: 'OTHER', label: 'OTHER / CUSTOM PUBLISHING' },
] as const;

const platforms = [
    { key: 'spotify', label: 'Spotify Artist Link' },
    { key: 'apple_music', label: 'Apple Music Artist Link' },
    { key: 'beatport', label: 'Beatport Link' },
    { key: 'youtube', label: 'YouTube Channel Link' },
    { key: 'instagram', label: 'Instagram Profile Link' },
    { key: 'facebook', label: 'Facebook Profile Link' },
    { key: 'x', label: 'X Profile Link' },
    { key: 'tiktok', label: 'TikTok Profile Link' },
    { key: 'soundcloud', label: 'Soundcloud Profile Link' },
    { key: 'songkick', label: 'Songkick Link' },
    { key: 'bandsintown', label: 'Bandsintown Link' },
    { key: 'website', label: 'Website Link' },
];
</script>

<template>
    <Head title="My profile" />

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="My profile"
            :description="
                isNew
                    ? 'Complete your artist information to access the dashboard.'
                    : 'Your artist information. Update it anytime.'
            "
        />

        <Form
            v-bind="ArtistProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <input type="hidden" name="_method" value="patch" />

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="artist_name">Artist Name *</Label>
                    <Input
                        id="artist_name"
                        name="artist_name"
                        :default-value="profile?.artist_name"
                        required
                        placeholder="Artist Name"
                    />
                    <InputError class="mt-2" :message="errors.artist_name" />
                </div>

                <div class="grid gap-2">
                    <Label for="real_name">First name and last name *</Label>
                    <Input
                        id="real_name"
                        name="real_name"
                        :default-value="profile?.real_name"
                        required
                        placeholder="First and last name"
                    />
                    <InputError class="mt-2" :message="errors.real_name" />
                </div>

                <div class="grid gap-2">
                    <Label for="role">Rol *</Label>
                    <select
                        id="role"
                        name="role"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        required
                    >
                        <option value="" disabled>Select</option>
                        <option
                            v-for="option in roleOptions"
                            :key="option"
                            :value="option"
                            :selected="profile?.role === option"
                        >
                            {{ option }}
                        </option>
                    </select>
                    <InputError class="mt-2" :message="errors.role" />
                </div>

                <div class="grid gap-2">
                    <Label for="country">Artist Country *</Label>
                    <Input
                        id="country"
                        name="country"
                        :default-value="profile?.country"
                        required
                        placeholder="Country"
                    />
                    <InputError class="mt-2" :message="errors.country" />
                </div>

                <div class="grid gap-2">
                    <Label for="city">Artist City *</Label>
                    <Input
                        id="city"
                        name="city"
                        :default-value="profile?.city"
                        required
                        placeholder="City"
                    />
                    <InputError class="mt-2" :message="errors.city" />
                </div>

                <div class="grid gap-2">
                    <Label for="estate">Artist Estate *</Label>
                    <Input
                        id="estate"
                        name="estate"
                        :default-value="profile?.estate"
                        required
                        placeholder="State / Province"
                    />
                    <InputError class="mt-2" :message="errors.estate" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="photo">
                    Provide a link to a photograph, Google Drive, or Dropbox *
                </Label>
                <Input
                    id="photo"
                    name="photo"
                    type="url"
                    :default-value="profile?.photo"
                    required
                    placeholder="Link URL"
                />
                <InputError class="mt-2" :message="errors.photo" />
            </div>

            <div class="grid gap-2">
                <Label for="bio">Artist Bio *</Label>
                <Textarea
                    id="bio"
                    name="bio"
                    :default-value="profile?.bio"
                    required
                    maxlength="500"
                    placeholder="Tell us about the artist (500 characters max)"
                    class="min-h-[120px]"
                />
                <InputError class="mt-2" :message="errors.bio" />
            </div>

            <!-- Spec S6 (profile-writers-publishing): publishing + IPI. -->
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="publishing">Publishing *</Label>
                    <select
                        id="publishing"
                        v-model="publishing"
                        name="publishing"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        required
                    >
                        <option value="" disabled>Select</option>
                        <option
                            v-for="option in publishingOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <InputError class="mt-2" :message="errors.publishing" />
                </div>

                <div class="grid gap-2">
                    <Label for="ipi_number">IPI Number</Label>
                    <Input
                        id="ipi_number"
                        name="ipi_number"
                        :default-value="profile?.ipi_number"
                        placeholder="Enter IPI Number (if available)"
                    />
                    <InputError class="mt-2" :message="errors.ipi_number" />
                </div>

                <!-- Spec S6: the custom company name activates on OTHER. -->
                <div
                    v-if="profile?.publishing === 'OTHER'"
                    class="grid gap-2 sm:col-span-2"
                >
                    <Label for="publishing_custom"
                        >Write the publishing company name *</Label
                    >
                    <Input
                        id="publishing_custom"
                        v-model="publishingCustom"
                        name="publishing_custom"
                        placeholder="Write the publishing company name"
                    />
                    <InputError
                        class="mt-2"
                        :message="errors.publishing_custom"
                    />
                </div>
            </div>

            <!-- ARTIST PROFILE STATUS (same cards as the release form) -->
            <div class="space-y-4">
                <div>
                    <h4 class="text-lg font-bold">ARTIST PROFILE STATUS</h4>
                    <p class="mt-1 text-sm opacity-90">
                        Have you previously released music on any digital
                        platform under this artist name?
                    </p>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <label class="cursor-pointer">
                        <input
                            type="radio"
                            name="status"
                            value="New Artist"
                            class="peer sr-only"
                            :checked="profile?.status === 'New Artist' || isNew"
                            required
                        />
                        <div
                            class="h-full rounded-xl border border-white/15 bg-[#111] p-6 transition peer-checked:border-2 peer-checked:border-[#39d353]"
                        >
                            <div
                                class="mb-4 flex h-[22px] w-[22px] items-center justify-center rounded-full border-2 border-white/40 peer-checked:border-[#39d353]"
                                :class="{
                                    'bg-[#39d353]':
                                        profile?.status === 'New Artist' ||
                                        isNew,
                                }"
                            >
                                <span
                                    v-if="
                                        profile?.status === 'New Artist' ||
                                        isNew
                                    "
                                    class="text-xs font-bold text-white"
                                    >✓</span
                                >
                            </div>
                            <div class="mb-3 text-base font-bold">
                                No, this is a new artist profile
                            </div>
                            <div class="text-sm leading-relaxed text-[#d1d5db]">
                                This artist does not currently have any official
                                profiles on digital streaming platforms.
                                <br /><br />
                                Profile links are optional and may be left blank.
                                <br /><br />
                                New artist profiles will be automatically
                                created by the platforms upon the release of
                                their first publication.
                            </div>
                        </div>
                    </label>

                    <label class="cursor-pointer">
                        <input
                            type="radio"
                            name="status"
                            value="Existing Artist"
                            class="peer sr-only"
                            :checked="profile?.status === 'Existing Artist'"
                            required
                        />
                        <div
                            class="h-full rounded-xl border border-white/15 bg-[#111] p-6 transition peer-checked:border-2 peer-checked:border-[#39d353]"
                        >
                            <div
                                class="mb-4 flex h-[22px] w-[22px] items-center justify-center rounded-full border-2 border-white/40"
                                :class="{
                                    'border-[#39d353] bg-[#39d353]':
                                        profile?.status === 'Existing Artist',
                                }"
                            >
                                <span
                                    v-if="profile?.status === 'Existing Artist'"
                                    class="text-xs font-bold text-white"
                                    >✓</span
                                >
                            </div>
                            <div class="mb-3 text-base font-bold">
                                Yes, this artist already has existing profiles
                                on one or more digital platforms
                            </div>
                            <div class="text-sm leading-relaxed text-[#d1d5db]">
                                This artist has previously released music under
                                this artist name and already has active profiles
                                on one or more digital platforms.
                                <br /><br />
                                To ensure correct delivery and avoid duplicate
                                artist pages, all existing artist profile links
                                must be provided.
                            </div>
                        </div>
                    </label>
                </div>

                <InputError class="mt-2" :message="errors.status" />
            </div>

            <!-- PLATFORM LINKS -->
            <div class="space-y-4 border-t border-white/10 pt-6">
                <div>
                    <h4 class="text-lg font-bold">Links</h4>
                    <p class="mt-1 text-sm opacity-75">
                        {{
                            isExisting
                                ? 'Required: provide at least one platform link for an existing artist.'
                                : 'Optional for new artists.'
                        }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="platform in platforms"
                        :key="platform.key"
                        class="grid gap-2"
                    >
                        <Label :for="`link_${platform.key}`">
                            {{ platform.label }}
                            <span
                                v-if="
                                    isExisting &&
                                    ['spotify', 'apple_music', 'beatport'].includes(
                                        platform.key,
                                    )
                                "
                                class="ml-1 font-bold text-red-500"
                                >*</span
                            >
                        </Label>
                        <Input
                            :id="`link_${platform.key}`"
                            :name="`links[${platform.key}]`"
                            type="url"
                            :default-value="profile?.links?.[platform.key]"
                        />
                    </div>
                </div>

                <InputError class="mt-2" :message="errors.links" />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="processing">
                    {{ isNew ? 'Complete profile' : 'Save profile' }}
                </Button>
                <span class="text-sm opacity-70">
                    Email: {{ user.email }}
                </span>
            </div>
        </Form>
    </div>
</template>
