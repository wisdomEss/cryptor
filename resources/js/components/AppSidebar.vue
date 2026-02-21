<!-- resources/js/layouts/AppSidebar.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { route as ziggyRoute } from 'ziggy-js';
import type { Config } from 'ziggy-js';
import {
    BookOpen,
    Folder,
    LayoutGrid,
    KeyRound,
    Upload,
    Ban,
    PenTool,
    Clock,
    FileCheck,
    ShieldCheck,
    History,
    Globe,
    Signature,
    MessageCircle,
} from 'lucide-vue-next';

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
import { type NavItem } from '@/types';
import AppLogo from './AppLogo.vue';

// Configuration Ziggy
const page = usePage();
const ziggy = page.props.ziggy as Config;

const route = (name: string, params?: any) => {
    return ziggyRoute(name, params, true, ziggy);
};

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
        icon: LayoutGrid,
    },

    // ===== KEYS =====
    {
        title: 'Keys',
        href: '#',
        icon: KeyRound,
        children: [
            {
                title: 'My Keys',
                href: route('keys.index'),
                icon: KeyRound,
            },
            {
                title: 'Create Key',
                href: route('keys.create'),
                icon: Upload,
            },
            // {
            //     title: 'Revoked Keys',
            //     href: route('keys.revoked'),
            //     icon: Ban,
            // },
        ],
    },

    // ===== SIGNATURES =====
    {
        title: 'Signatures',
        href: '#',
        icon: PenTool,
        children: [
            {
                title: 'My Signatures',
                href: route('signatures.index'),
                icon: PenTool,
            },
            {
                title: 'Sign',
                href: route('signatures.create'),
                icon: Signature,
            },
           
        ],
    },

    // ===== VERIFICATION =====
    {
        title: 'Verification',
        href: '#',
        icon: ShieldCheck,
        children: [
            {
                title: 'History',
                href: route('verifications.index'),
                icon: History,
            },
            {
                title: 'Verify File',
                href: route('verifications.create'),
                icon: ShieldCheck,
            },
            
        ],
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Contact Us',
        href: 'mailto:ewisdommoukpe@gmail.com',
        icon: MessageCircle,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
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