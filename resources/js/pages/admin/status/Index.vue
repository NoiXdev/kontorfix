<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, RefreshCw, XCircle } from 'lucide-vue-next';

interface HealthCheck {
    key: string;
    label: string;
    ok: boolean;
    detail: string;
}

defineProps<{
    checks: HealthCheck[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Status', href: '/admin/status' }];

function refresh() {
    router.reload({ only: ['checks'] });
}
</script>

<template>
    <Head title="Status" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold">Status</h1>
                    <p class="text-sm text-muted-foreground">Betriebsstatus der Registry und angebundener Dienste.</p>
                </div>
                <Button variant="outline" @click="refresh">
                    <RefreshCw class="size-4" />
                    Aktualisieren
                </Button>
            </div>

            <div class="grid gap-3">
                <Card v-for="check in checks" :key="check.key">
                    <CardContent class="flex items-center gap-3 p-4">
                        <component
                            :is="check.ok ? CheckCircle2 : XCircle"
                            :class="cn('size-5 shrink-0', check.ok ? 'text-emerald-500' : 'text-destructive')"
                        />
                        <div class="min-w-0">
                            <div class="font-medium">{{ check.label }}</div>
                            <div class="truncate text-sm text-muted-foreground">{{ check.detail }}</div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
