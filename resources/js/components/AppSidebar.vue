<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { CalendarDays, FileMusic, LayoutGrid, Music, User, Users } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { calendar, dashboard, myProfile, releaseForm } from '@/routes';
import songs from '@/routes/songs';
import users from '@/routes/users';
import type { NavItem } from '@/types';

const page = usePage();
const roles = computed(
    () => (page.props.auth as { roles?: string[] } | undefined)?.roles ?? [],
);
const isAdmin = computed(() => roles.value.includes('admin'));

// Spec L10: user management is admin-only (calendar is for everyone, S2).
const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'Songs',
            href: songs.index(),
            icon: Music,
        },
        // Spec S1: form lives inside the app now (no more WordPress).
        {
            title: 'Music Release Form',
            href: releaseForm(),
            icon: FileMusic,
        },
        // Spec S2: calendar is for every user (admin sees everything).
        {
            title: 'Calendar',
            href: calendar(),
            icon: CalendarDays,
        },
        // Spec S6: artist info complete/edit — main menu, right under the
        // form (the user dropdown at the bottom was not intuitive).
        {
            title: 'My profile',
            href: myProfile(),
            icon: User,
        },
    ];

    if (isAdmin.value) {
        items.push({
            title: 'Users',
            href: users.index(),
            icon: Users,
        });
    }

    return items;
});

const footerNavItems: NavItem[] = [
    
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
