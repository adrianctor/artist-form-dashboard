<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import userRoutes from '@/routes/users';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'user',
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: userRoutes.index().url },
            { title: 'Create user', href: userRoutes.create().url },
        ],
    },
});

function submit(): void {
    form.post(userRoutes.store().url);
}
</script>

<template>
    <Head title="Create user" />

    <div class="max-w-2xl space-y-6">
        <Heading title="Create user" description="Add a new dashboard account." />

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
                <Label for="password">Password</Label>
                <Input id="password" v-model="form.password" type="password" autocomplete="new-password" required />
                <InputError :message="form.errors.password" />
            </div>
            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <Input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required />
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
            <div class="flex gap-3">
                <Button type="submit" :disabled="form.processing">Create user</Button>
                <Button as-child variant="outline">
                    <Link :href="userRoutes.index().url">Cancel</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
