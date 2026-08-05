<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Database, Mail } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    settings: {
        registration_enabled: boolean;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'System', href: '/admin/system' }];

const page = usePage<SharedData>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);

const form = useForm({
    registration_enabled: props.settings.registration_enabled,
});

function save() {
    form.put(route('admin.system.update'), { preserveScroll: true });
}
</script>

<template>
    <Head title="System" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <div
                v-if="flashSuccess"
                class="fixed right-4 top-4 z-50 rounded-md border border-verdigris/30 bg-verdigris/15 px-4 py-2 text-sm text-verdigris shadow-lg"
            >
                {{ flashSuccess }}
            </div>

            <div>
                <h1 class="text-xl font-semibold">Systemeinstellungen</h1>
                <p class="text-sm text-muted-foreground">Instanzweite Einstellungen für Zugang, E-Mail-Versand und Speicher.</p>
            </div>

            <!-- Registration -->
            <form class="max-w-2xl space-y-4 rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border" @submit.prevent="save">
                <h2 class="text-sm font-medium">Zugang</h2>
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.registration_enabled" type="checkbox" class="mt-1 size-4 rounded border-input" />
                    <span>
                        Selbst-Registrierung erlauben
                        <span class="block text-xs text-muted-foreground">
                            Aus: Konten werden ausschließlich von Administratoren angelegt. Die öffentliche
                            <code class="font-mono">/register</code>-Seite ist dann gesperrt.
                        </span>
                    </span>
                </label>
                <div>
                    <Button type="submit" :disabled="form.processing">Speichern</Button>
                </div>
            </form>

            <!-- Grouped settings entry points -->
            <div class="grid max-w-2xl gap-4 sm:grid-cols-2">
                <Link
                    :href="route('admin.mail.show')"
                    class="flex items-start gap-3 rounded-xl border border-sidebar-border/70 p-5 transition-colors hover:bg-muted/50 dark:border-sidebar-border"
                >
                    <Mail class="mt-0.5 size-5 text-muted-foreground" />
                    <span>
                        <span class="block font-medium">E-Mail</span>
                        <span class="block text-xs text-muted-foreground">Versand-Backend (Log, SMTP, Postal) und Testmail.</span>
                    </span>
                </Link>
                <Link
                    :href="route('admin.storage.show')"
                    class="flex items-start gap-3 rounded-xl border border-sidebar-border/70 p-5 transition-colors hover:bg-muted/50 dark:border-sidebar-border"
                >
                    <Database class="mt-0.5 size-5 text-muted-foreground" />
                    <span>
                        <span class="block font-medium">Speicher</span>
                        <span class="block text-xs text-muted-foreground">Ablage der Paket-Artefakte (lokal oder S3-kompatibel).</span>
                    </span>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
