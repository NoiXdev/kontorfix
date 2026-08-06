<script setup lang="ts">
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    { label: 'Navigation' },
);

const page = usePage<SharedData>();

// The open/closed state of each section is remembered per label, so a collapsed section
// stays collapsed across navigation and reloads — keeping the sidebar compact.
const storageKey = computed(() => `sidebar-section:${props.label}`);

function initialOpen(): boolean {
    if (typeof localStorage === 'undefined') {
        return true;
    }
    return localStorage.getItem(storageKey.value) !== '0';
}

const open = ref(initialOpen());

// A section containing the active route always starts open, so the current page is never
// hidden behind a collapsed header.
if (props.items.some((item) => item.href === page.url)) {
    open.value = true;
}

watch(open, (value) => {
    try {
        localStorage.setItem(storageKey.value, value ? '1' : '0');
    } catch {
        // localStorage unavailable (private mode) — the state just isn't persisted.
    }
});

// When the whole sidebar is collapsed to icons, the section labels disappear and there is
// no trigger to click — so every section must stay expanded there, otherwise its nav
// icons would vanish too. Collapsing only applies to the full-width sidebar.
const { state } = useSidebar();
const sectionOpen = computed(() => state.value === 'collapsed' || open.value);
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <Collapsible :open="sectionOpen" class="group/section" @update:open="(value) => (open = value)">
            <SidebarGroupLabel as-child>
                <CollapsibleTrigger
                    class="flex w-full items-center justify-between hover:text-sidebar-foreground focus-visible:ring-2"
                >
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
