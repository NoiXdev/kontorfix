<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Activity, Boxes, CloudDownload, Database, Fingerprint, Folder, Globe, KeyRound, LayoutGrid, Package, Webhook } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

// Nur echte Member sehen ausschließlich die Portal-Navigation. Fehlt/unbekannt die Rolle,
// verhalten wir uns wie admin/maintainer, damit keine leere Nav entsteht.
const isMember = computed(() => page.props.auth.user?.role === 'member');
const isAdmin = computed(() => page.props.auth.user?.role === 'admin');

const mainNavItems = computed<NavItem[]>(() => {
    // Kunden sehen ausschließlich das Portal.
    if (isMember.value) {
        return [{ title: 'Registries', href: route('portal.registries.index'), icon: Boxes }];
    }

    // Betreiber & Maintainer.
    const items: NavItem[] = [
        { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
        { title: 'Pakete', href: '/admin/packages', icon: Package },
        { title: 'Gruppen', href: '/admin/groups', icon: Boxes },
        { title: 'Tokens', href: '/admin/tokens', icon: KeyRound },
        { title: 'Upstreams', href: '/admin/upstreams', icon: CloudDownload },
        { title: 'Domains', href: '/admin/domains', icon: Globe },
        { title: 'Webhooks', href: '/admin/webhooks', icon: Webhook },
        { title: 'Status', href: '/admin/status', icon: Activity },
    ];

    // Nur Admins: sicherheits-/infrastrukturkritische Einstellungen (role:admin-Routen).
    if (isAdmin.value) {
        items.push({ title: 'OIDC / SSO', href: '/admin/oidc', icon: Fingerprint });
        items.push({ title: 'Storage', href: '/admin/storage', icon: Database });
    }

    items.push({ title: 'Portal', href: route('portal.registries.index'), icon: Package });

    return items;
});

const footerNavItems: NavItem[] = [
    {
        title: 'Projekt-Repository',
        href: 'https://github.com/NoiXdev/kontorfix',
        icon: Folder,
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
