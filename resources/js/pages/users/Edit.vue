<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import userRoutes from '@/routes/users';

const props = defineProps<{
    user: {
        id: number;
        name: string;
        email: string;
        role: string;
        is_active: boolean;
    };
}>();

const form = useForm({
    name: props.user.name,
    email: props.user.email,
    password: '',
    password_confirmation: '',
    role: props.user.role,
    is_active: props.user.is_active,
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: userRoutes.index().url },
            // Last crumb renders as plain text (BreadcrumbPage); href unused.
            { title: 'Edit user', href: '#' },
        ],
    },
});

function submit(): void {
    form.put(userRoutes.update({ user: props.user.id }).url);
}
</script>

<template>
    <Head title="Edit user" />

    <div class="max-w-2xl space-y-6">
        <Heading title="Edit user" description="Update the account details or set a new password." />

        <form class="space-y-5" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" v-model="form.name" autocomplete="name" required />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input id="email" v-model="form.email" type="email" autocomplete="email" required />
                <InputError :message="form.errors.email" />
            </div>
            <div class="grid gap-2">
                <Label for="password">New password (optional)</Label>
                <Input id="password" v-model="form.password" type="password" autocomplete="new-password" />
                <InputError :message="form.errors.password" />
            </div>
            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm new password</Label>
                <Input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" />
                <InputError :message="form.errors.password_confirmation" />
            </div>
            <div class="grid gap-2">
                <Label for="role">Role</Label>
                <select
                    id="role"
                    v-model="form.role"
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                >
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
                <InputError :message="form.errors.role" />
            </div>
            <div class="flex items-center gap-2">
                <input
                    id="is_active"
                    v-model="form.is_active"
                    type="checkbox"
                    class="h-4 w-4 rounded border-input"
                />
                <Label for="is_active">Active (can log in)</Label>
            </div>
            <div class="flex gap-3">
                <Button type="submit" :disabled="form.processing">Save changes</Button>
                <Button as-child variant="outline">
                    <Link :href="userRoutes.index().url">Cancel</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
