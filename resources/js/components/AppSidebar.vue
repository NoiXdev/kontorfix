<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { BookOpen, Boxes, CloudDownload, Folder, Globe, KeyRound, LayoutGrid, Package, Webhook } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

// Nur echte Member sehen ausschließlich die Portal-Navigation. Fehlt/unbekannt die Rolle,
// verhalten wir uns wie admin/maintainer, damit keine leere Nav entsteht.
const isMember = computed(() => page.props.auth.user?.role === 'member');

const mainNavItems = computed<NavItem[]>(() =>
    isMember.value
        ? [
              {
                  title: 'Registries',
                  href: route('portal.registries.index'),
                  icon: Boxes,
              },
          ]
        : [
              {
                  title: 'Dashboard',
                  href: '/dashboard',
                  icon: LayoutGrid,
              },
              {
                  title: 'Pakete',
                  href: '/admin/packages',
                  icon: Package,
              },
              {
                  title: 'Gruppen',
                  href: '/admin/groups',
                  icon: Boxes,
              },
              {
                  title: 'Tokens',
                  href: '/admin/tokens',
                  icon: KeyRound,
              },
              {
                  title: 'Upstreams',
                  href: '/admin/upstreams',
                  icon: CloudDownload,
              },
              {
                  title: 'Domains',
                  href: '/admin/domains',
                  icon: Globe,
              },
              {
                  title: 'Webhooks',
                  href: '/admin/webhooks',
                  icon: Webhook,
              },
              {
                  title: 'Portal',
                  href: route('portal.registries.index'),
                  icon: Package,
              },
          ],
);

const footerNavItems: NavItem[] = [
    {
        title: 'Github Repo',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits',
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
