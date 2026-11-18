<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { CalendarDays, Camera, Globe, LayoutGrid, Music, Play, Users } from '@lucide/vue';
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
import { calendar, dashboard } from '@/routes';
import songs from '@/routes/songs';
import users from '@/routes/users';
import type { NavItem } from '@/types';

const page = usePage();
const roles = computed(
    () => (page.props.auth as { roles?: string[] } | undefined)?.roles ?? [],
);
const isAdmin = computed(() => roles.value.includes('admin'));

// Spec L10: Calendar and user management are admin-only.
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
    ];

    if (isAdmin.value) {
        items.push(
            {
                title: 'Calendar',
                href: calendar(),
                icon: CalendarDays,
            },
            {
                title: 'Users',
                href: users.index(),
                icon: Users,
            },
        );
    }

    return items;
});

const footerNavItems: NavItem[] = [
    {
        title: 'Website',
        href: 'https://sunsetgathering.com',
        icon: Globe,
    },
    {
        title: 'YouTube',
        href: 'https://www.youtube.com/@SunsetGathering',
        icon: Play,
    },
    {
        title: 'Instagram',
        href: 'https://www.instagram.com/sunset_gathering/',
        icon: Camera,
    },
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
