<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

// Spec S1/S2: first-time password set behind an emailed signed link —
// no current password is asked (the user never had one).
const page = usePage();

defineProps<{
    email: string;
}>();

defineOptions({
    layout: {
        title: 'Set your password',
        description: 'Choose a password for your account — no current password required.',
    },
});
</script>

<template>
    <Head title="Set your password" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Set your password"
            description="This link is personal, expires in 60 minutes and can only be used once."
        />

        <p class="text-sm text-muted-foreground">
            Setting a password for
            <span class="font-medium text-foreground">{{ email }}</span>
        </p>

        <Form
            :action="page.url"
            method="post"
            v-slot="{ errors, processing }"
            class="space-y-4"
        >
            <div class="grid gap-2">
                <Label for="password">New password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    placeholder="New password"
                    required
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    placeholder="Confirm password"
                    required
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="w-full"
                :disabled="processing"
                data-test="set-password-button"
            >
                Set password
            </Button>
        </Form>
    </div>
</template>
