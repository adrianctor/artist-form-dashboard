<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import userRoutes from '@/routes/users';

type ManagedUser = {
    id: number;
    name: string;
    email: string;
    role: string;
    is_active: boolean;
    created_at: string | null;
};

type Props = {
    users: {
        data: ManagedUser[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    flash: {
        success?: string | null;
        error?: string | null;
    };
};

const props = defineProps<Props>();
const deleteForm = useForm({});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Users',
                href: userRoutes.index().url,
            },
        ],
    },
});

function toggleActive(user: ManagedUser): void {
    router.put(
        userRoutes.update({ user: user.id }).url,
        { is_active: !user.is_active },
        { preserveScroll: true },
    );
}

function deleteUser(user: ManagedUser): void {
    if (!window.confirm(`Delete ${user.name}?`)) {
        return;
    }

    deleteForm.delete(userRoutes.destroy({ user: user.id }).url);
}

function formatDate(date: string | null): string {
    return date ? new Date(date).toLocaleDateString() : '—';
}
</script>

<template>
    <Head title="Users" />

    <section class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Users</h1>
                <p class="text-sm text-muted-foreground">
                    Manage the accounts that can access the dashboard.
                </p>
            </div>
            <Button as-child>
                <Link :href="userRoutes.create().url">Create user</Link>
            </Button>
        </div>

        <div
            v-if="props.flash.success"
            role="status"
            class="rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-900"
        >
            {{ props.flash.success }}
        </div>
        <div
            v-if="props.flash.error"
            role="alert"
            class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-900"
        >
            {{ props.flash.error }}
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Email</th>
                        <th class="px-4 py-3 font-medium">Role</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Created</th>
                        <th class="px-4 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="user in props.users.data"
                        :key="user.id"
                        class="border-b last:border-0"
                    >
                        <td class="px-4 py-3 font-medium">{{ user.name }}</td>
                        <td class="px-4 py-3">{{ user.email }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded bg-muted px-2 py-0.5 text-xs font-medium">{{ user.role }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded px-2 py-0.5 text-xs font-medium"
                                :class="user.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                            >
                                {{ user.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">{{ formatDate(user.created_at) }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <Button as-child variant="outline" size="sm">
                                    <Link :href="userRoutes.edit(user.id).url">Edit</Link>
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="deleteForm.processing"
                                    @click="toggleActive(user)"
                                >
                                    {{ user.is_active ? 'Deactivate' : 'Activate' }}
                                </Button>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    :disabled="deleteForm.processing"
                                    @click="deleteUser(user)"
                                >
                                    Delete
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="props.users.data.length === 0">
                        <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                            No users found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="props.users.links.length > 3" class="flex flex-wrap gap-2" aria-label="Pagination">
            <Link
                v-for="link in props.users.links"
                :key="link.label"
                :href="link.url ?? '#'"
                :aria-current="link.active ? 'page' : undefined"
                :aria-disabled="!link.url"
                class="rounded border px-3 py-1.5 text-sm"
                :class="{ 'bg-primary text-primary-foreground': link.active, 'pointer-events-none opacity-50': !link.url }"
                v-html="link.label"
            />
        </nav>
    </section>
</template>
