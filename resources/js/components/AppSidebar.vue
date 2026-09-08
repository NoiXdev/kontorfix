<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import ScopeSwitcher from '@/components/kontorfix/ScopeSwitcher.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Activity,
    BookOpen,
    Bot,
    Boxes,
    Building2,
    CloudDownload,
    Database,
    Fingerprint,
    Folder,
    Gauge,
    GitBranch,
    Globe,
    History,
    KeyRound,
    LayoutGrid,
    Mail as MailIcon,
    Package,
    Recycle,
    ScrollText,
    Settings as SettingsIcon,
    Users,
    Webhook,
} from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

// The navigation follows the server-computed capabilities:
//   - console: any organization admin/maintainer — sees the scoped registry surface.
//   - super:   the global super-admin — additionally sees instance-wide administration.
// Anyone without console access (a plain member) sees only the portal.
//
// Both portal links point at `portal.home` rather than at a /c/{orgSlug} address: the portal
// is addressed by organization now, and the sidebar has no organization in hand — that route
// resolves the signed-in user's own home organization and redirects there.
const canConsole = computed(() => page.props.auth.can?.console ?? false);
const isSuper = computed(() => page.props.auth.can?.super ?? false);
const appVersion = computed(() => page.props.appVersion ?? null);

interface NavSection {
    label: string;
    items: NavItem[];
}

// Grouped into thematic sections (instead of one long flat list).
const navSections = computed<NavSection[]>(() => {
    // Anyone without console access (plain members) sees exclusively the portal.
    if (!canConsole.value) {
        return [{ label: 'Portal', items: [{ title: 'Portal', href: route('portal.home'), icon: Boxes }] }];
    }

    // Organization admins & maintainers.
    const sections: NavSection[] = [
        {
            label: 'Übersicht',
            items: [
                { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
                // Instance health is a super-admin surface.
                ...(isSuper.value ? [{ title: 'Status', href: '/admin/status', icon: Activity }] : []),
            ],
        },
        {
            label: 'Registry',
            items: [
                { title: 'Pakete', href: '/admin/packages', icon: Package },
                { title: 'Gruppen', href: '/admin/groups', icon: Boxes },
                { title: 'Upstreams', href: '/admin/upstreams', icon: CloudDownload },
                { title: 'Domains', href: '/admin/domains', icon: Globe },
                // Org admins see the PUBLISHED policies read-only; the operator manages them.
                { title: 'Retention', href: '/admin/retention-policies', icon: History },
            ],
        },
        {
            label: 'Zugriff',
            items: [
                { title: 'Tokens', href: '/admin/tokens', icon: KeyRound },
                { title: 'Git-Tokens', href: '/admin/git-credentials', icon: GitBranch },
                // Outgoing webhooks are instance-wide config — super-admin only.
                ...(isSuper.value ? [{ title: 'Webhooks', href: '/admin/webhooks', icon: Webhook }] : []),
            ],
        },
    ];

    // Super-admin only: instance-wide, security-/infrastructure-critical administration.
    if (isSuper.value) {
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
                {
                    title: 'System',
                    href: '/admin/system',
                    icon: SettingsIcon,
                    // E-Mail and Storage moved in here as a sub-menu: both are instance-wide
                    // settings pages one step below "System" in the console's own hierarchy,
                    // and flattening them alongside it crowded the section for what is, day
                    // to day, rarely touched configuration.
                    children: [
                        { title: 'E-Mail', href: '/admin/mail', icon: MailIcon },
                        { title: 'Storage', href: '/admin/storage', icon: Database },
                    ],
                },
                { title: 'Speicherbereinigung', href: '/admin/oci/sweeper', icon: Recycle },
                { title: 'Aktivität', href: '/admin/activity', icon: ScrollText },
            ],
        });
    }

    sections.push({
        label: 'Portal',
        items: [{ title: 'Kundenportal', href: route('portal.home'), icon: Package }],
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

    // Horizon and the API browser are their own (non-Inertia) pages → real browser
    // links in the footer. Only reachable while authenticated (the app shell requires
    // a session) and gated server-side. Both surface instance internals, so they are
    // shown to the super-admin only.
    if (isSuper.value) {
        items.unshift(
            {
                title: 'API-Browser',
                href: '/docs/api',
                icon: BookOpen,
            },
            {
                title: 'Queue (Horizon)',
                href: '/horizon',
                icon: Gauge,
            },
        );
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
            <ScopeSwitcher v-if="canConsole" />
            <NavMain v-for="section in navSections" :key="section.label" :label="section.label" :items="section.items" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
            <p
                v-if="appVersion"
                class="px-2 pb-1 text-center text-xs text-muted-foreground group-has-[[data-collapsible=icon]]/sidebar-wrapper:hidden"
            >
                Kontorfix v{{ appVersion }}
            </p>
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
