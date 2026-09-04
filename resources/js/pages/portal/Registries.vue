<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Package } from 'lucide-vue-next';
import { registryPackageCount } from './portalPackages';

interface RegistryRow {
    id: string;
    name: string;
    slug: string;
    url: string;
    /** What this registry actually delivers — RegistryAccessService's answer. */
    served_count: number;
    /**
     * How many assignments the registry page will list. It lists lapsed ones too, marked, so
     * this is the larger number whenever something has lapsed and the card has to say which
     * of the two it is showing.
     */
    assigned_count: number;
}

const props = defineProps<{
    // The organization the URL addresses. The portal is addressed by organization, so it
    // is the first segment of every portal link this page builds.
    orgSlug: string;
    registries: RegistryRow[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Registries', href: `/c/${props.orgSlug}/registries` }];
</script>

<template>
    <Head title="Registries" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">Registries</h1>
            </div>

            <div v-if="props.registries.length > 0" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="registry in props.registries"
                    :key="registry.id"
                    :href="route('portal.registries.show', [props.orgSlug, registry.id])"
                    class="block rounded-xl transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                >
                    <Card class="h-full transition-colors hover:border-verdigris/40">
                        <CardHeader>
                            <CardTitle>{{ registry.name }}</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <p class="font-mono text-sm break-all text-muted-foreground">{{ registry.url }}</p>
                            <p class="flex items-center gap-2 text-sm text-muted-foreground">
                                <Package class="size-4" />
                                <!-- The sentence lives in the tested module, not here: it is a
                                     factual claim about what this registry delivers, and it was
                                     the German on this page that nothing could check. -->
                                {{ registryPackageCount(registry.served_count, registry.assigned_count) }}
                            </p>
                        </CardContent>
                    </Card>
                </Link>
            </div>

            <div v-else class="rounded-xl border border-sidebar-border/70 px-4 py-8 text-center text-muted-foreground dark:border-sidebar-border">
                Noch keine Registries verfügbar.
            </div>
        </div>
    </AppLayout>
</template>
