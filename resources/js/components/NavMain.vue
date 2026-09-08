<script setup lang="ts">
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { isNavItemActive, sectionHoldsCurrentPage } from '@/lib/navPath';
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
// `/dashboard-archive` is correctly NOT treated as living under `/dashboard`. Also true for
// an item's own sub-menu (e.g. System → E-Mail/Storage): see sectionHoldsCurrentPage().
const holdsCurrentPage = computed(() => sectionHoldsCurrentPage(page.url, props.items));

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
const { state, isMobile } = useSidebar();
const sectionOpen = computed(() => state.value === 'collapsed' || open.value);

// `SidebarMenuSub` hides itself in the icon rail (`group-data-[collapsible=icon]:hidden`),
// so an item's children — e.g. "System" → E-Mail/Storage — would otherwise become
// unreachable there rather than merely losing their label like a plain item does. Standard
// shadcn fix: swap the inline sub-menu for a DropdownMenu, opened from the parent's own
// icon button, that lists the parent page plus its children. Mobile never renders the icon
// rail (the sidebar is a full-width sheet there), so it keeps the inline sub-menu too.
const collapsedToIcons = computed(() => state.value === 'collapsed' && !isMobile.value);
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
                        <!-- Icon rail, item has children: the inline sub-menu below is hidden by
                             `SidebarMenuSub` itself, so its children need another way to stay
                             reachable — a DropdownMenu opened from the parent's own icon button,
                             listing the parent page plus every child. -->
                        <DropdownMenu v-if="item.children?.length && collapsedToIcons">
                            <DropdownMenuTrigger as-child>
                                <SidebarMenuButton :tooltip="item.title" :is-active="isNavItemActive(page.url, item)">
                                    <component :is="item.icon" />
                                    <span>{{ item.title }}</span>
                                </SidebarMenuButton>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent side="right" align="start" :side-offset="4">
                                <DropdownMenuItem as-child>
                                    <Link :href="item.href">
                                        <component :is="item.icon" />
                                        <span>{{ item.title }}</span>
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem v-for="child in item.children" :key="child.title" as-child>
                                    <Link :href="child.href">
                                        <component :is="child.icon" />
                                        <span>{{ child.title }}</span>
                                    </Link>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <!-- Expanded sidebar (or mobile, which never shows the icon rail): the
                             sub-menu unchanged from before. -->
                        <template v-else>
                            <SidebarMenuButton as-child :is-active="isNavItemActive(page.url, item)">
                                <Link :href="item.href">
                                    <component :is="item.icon" />
                                    <span>{{ item.title }}</span>
                                </Link>
                            </SidebarMenuButton>
                            <SidebarMenuSub v-if="item.children?.length">
                                <SidebarMenuSubItem v-for="child in item.children" :key="child.title">
                                    <SidebarMenuSubButton as-child :is-active="child.href === page.url">
                                        <Link :href="child.href">
                                            <component :is="child.icon" />
                                            <span>{{ child.title }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </template>
                    </SidebarMenuItem>
                </SidebarMenu>
            </CollapsibleContent>
        </Collapsible>
    </SidebarGroup>
</template>
