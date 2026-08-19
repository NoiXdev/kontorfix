<script setup lang="ts">
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { isWithin } from '@/lib/navPath';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    { label: 'Navigation' },
);

const page = usePage<SharedData>();

// Sections start collapsed. Only the one holding the current page opens by itself, so the
// sidebar shows where you are rather than everything at once.
//
// Session storage, not local: a section you opened by hand stays open while you move around,
// and a fresh start returns to the quiet default instead of restoring a layout you may have
// opened weeks ago for one lookup.
const storageKey = computed(() => `sidebar-section:${props.label}`);

// null means "no explicit choice" — follow the active-page rule. A stored value is the
// user's own decision and wins, including closing the section they are currently in.
function storedChoice(): boolean | null {
    if (typeof sessionStorage === 'undefined') {
        return null;
    }
    const raw = sessionStorage.getItem(storageKey.value);

    return raw === null ? null : raw === '1';
}

const choice = ref<boolean | null>(storedChoice());

// A prefix match on segment boundaries, so a detail page such as
// `/admin/packages/01a0…` also counts as being inside the Registry section — while
// `/dashboard-archive` is correctly NOT treated as living under `/dashboard`.
const holdsCurrentPage = computed(() => {
    return props.items.some((item) => isWithin(page.url, item.href));
});

// Recomputed on every navigation. The previous version decided this once during setup, which
// never re-ran because the sidebar lives in a persistent layout — harmless while every section
// defaulted to open, and the central defect the moment they default to closed.
const open = computed(() => choice.value ?? holdsCurrentPage.value);

function setOpen(value: boolean): void {
    choice.value = value;
    try {
        sessionStorage.setItem(storageKey.value, value ? '1' : '0');
    } catch {
        // sessionStorage unavailable (private mode) — the choice just isn't remembered.
    }
}

// When the whole sidebar is collapsed to icons, the section labels disappear and there is
// no trigger to click — so every section must stay expanded there, otherwise its nav
// icons would vanish too. Collapsing only applies to the full-width sidebar.
const { state } = useSidebar();
const sectionOpen = computed(() => state.value === 'collapsed' || open.value);
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <Collapsible :open="sectionOpen" class="group/section" @update:open="setOpen">
            <SidebarGroupLabel as-child>
                <CollapsibleTrigger class="flex w-full items-center justify-between hover:text-sidebar-foreground focus-visible:ring-2">
                    <span>{{ label }}</span>
                    <ChevronRight class="transition-transform duration-200 group-data-[state=open]/section:rotate-90" />
                </CollapsibleTrigger>
            </SidebarGroupLabel>
            <CollapsibleContent>
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in items" :key="item.title">
                        <SidebarMenuButton as-child :is-active="item.href === page.url">
                            <Link :href="item.href">
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </CollapsibleContent>
        </Collapsible>
    </SidebarGroup>
</template>
