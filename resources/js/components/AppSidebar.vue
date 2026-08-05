<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Activity, Bot, Boxes, Building2, CloudDownload, Database, Fingerprint, Folder, Gauge, Globe, KeyRound, LayoutGrid, Mail as MailIcon, Package, Settings as SettingsIcon, Users, Webhook } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

// Only actual members see exclusively the portal navigation. If the role is
// missing/unknown, we behave like admin/maintainer so no empty nav results.
const isMember = computed(() => page.props.auth.user?.role === 'member');
const isAdmin = computed(() => page.props.auth.user?.role === 'admin');

interface NavSection {
    label: string;
    items: NavItem[];
}

// Grouped into thematic sections (instead of one long flat list).
const navSections = computed<NavSection[]>(() => {
    // Customers see exclusively the portal.
    if (isMember.value) {
        return [{ label: 'Portal', items: [{ title: 'Registries', href: route('portal.registries.index'), icon: Boxes }] }];
    }

    // Operators & maintainers.
    const sections: NavSection[] = [
        {
            label: 'Übersicht',
            items: [
                { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
                { title: 'Status', href: '/admin/status', icon: Activity },
            ],
        },
        {
            label: 'Registry',
            items: [
                { title: 'Pakete', href: '/admin/packages', icon: Package },
                { title: 'Gruppen', href: '/admin/groups', icon: Boxes },
                { title: 'Upstreams', href: '/admin/upstreams', icon: CloudDownload },
                { title: 'Domains', href: '/admin/domains', icon: Globe },
            ],
        },
        {
            label: 'Zugriff',
            items: [
                { title: 'Tokens', href: '/admin/tokens', icon: KeyRound },
                { title: 'Webhooks', href: '/admin/webhooks', icon: Webhook },
            ],
        },
    ];

    // Admins only: security-/infrastructure-critical settings (role:admin routes).
    if (isAdmin.value) {
        sections.push({
            label: 'Verwaltung',
            items: [
                { title: 'Kunden', href: '/admin/organizations', icon: Building2 },
                { title: 'Nutzer', href: '/admin/users', icon: Users },
                { title: 'Robots', href: '/admin/robots', icon: Bot },
                { title: 'OIDC / SSO', href: '/admin/oidc', icon: Fingerprint },
            ],
        });

        sections.push({
            label: 'System',
            items: [
                { title: 'System', href: '/admin/system', icon: SettingsIcon },
                { title: 'E-Mail', href: '/admin/mail', icon: MailIcon },
                { title: 'Storage', href: '/admin/storage', icon: Database },
            ],
        });
    }

    sections.push({
        label: 'Portal',
        items: [{ title: 'Kundenportal', href: route('portal.registries.index'), icon: Package }],
    });

    return sections;
});

const footerNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Projekt-Repository',
            href: 'https://github.com/NoiXdev/kontorfix',
            icon: Folder,
        },
    ];

    // Horizon is its own SPA (not Inertia) → as a real browser link in the footer.
    if (isAdmin.value) {
        items.unshift({
            title: 'Queue (Horizon)',
            href: '/horizon',
            icon: Gauge,
        });
    }

    return items;
});
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
            <NavMain v-for="section in navSections" :key="section.label" :label="section.label" :items="section.items" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
