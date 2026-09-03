<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import FlashToast from '@/components/kontorfix/FlashToast.vue';
import { Button } from '@/components/ui/button';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Database, Mail } from 'lucide-vue-next';

const props = defineProps<{
    settings: {
        registration_enabled: boolean;
        enabled_registry_types: string[];
        shared_package_role: string;
    };
    registryTypes: string[];
    // Both values with their German labels, from App\Enums\SharedPackageRole::options().
    // The two cases are stated once, in PHP, because the gate reads the same enum.
    sharedPackageRoles: { value: string; label: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'System', href: '/admin/system' }];

const form = useForm({
    registration_enabled: props.settings.registration_enabled,
    enabled_registry_types: [...props.settings.enabled_registry_types],
    shared_package_role: props.settings.shared_package_role,
});

function toggleType(type: string, on: boolean) {
    form.enabled_registry_types = on ? [...new Set([...form.enabled_registry_types, type])] : form.enabled_registry_types.filter((t) => t !== type);
}

function save() {
    form.put(route('admin.system.update'), { preserveScroll: true });
}
</script>

<template>
    <Head title="System" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <FlashToast />

            <div>
                <h1 class="text-xl font-semibold">Systemeinstellungen</h1>
                <p class="text-sm text-muted-foreground">Instanzweite Einstellungen für Zugang, E-Mail-Versand und Speicher.</p>
            </div>

            <!-- Registration -->
            <form class="max-w-2xl space-y-4 rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border" @submit.prevent="save">
                <h2 class="text-sm font-medium">Zugang</h2>
                <label class="flex items-start gap-2 text-sm">
                    <Switch v-model="form.registration_enabled" class="mt-1" />
                    <span>
                        Selbst-Registrierung erlauben
                        <span class="block text-xs text-muted-foreground">
                            Aus: Konten werden ausschließlich von Administratoren angelegt. Die öffentliche
                            <code class="font-mono">/register</code>-Seite ist dann gesperrt.
                        </span>
                    </span>
                </label>
                <div class="border-t border-sidebar-border/70 pt-4 dark:border-sidebar-border">
                    <h2 class="text-sm font-medium">Registry-Typen</h2>
                    <p class="mb-3 text-xs text-muted-foreground">
                        Instanzweite Obergrenze: deaktivierte Typen liefern kein Protokoll aus (pull/push aus) und verschwinden aus Anlage-Dialogen.
                        Organisationen können nur innerhalb der hier erlaubten Typen weiter einschränken.
                    </p>
                    <div class="flex flex-wrap gap-4">
                        <label v-for="type in props.registryTypes" :key="type" class="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                class="size-4 rounded border-input"
                                :checked="form.enabled_registry_types.includes(type)"
                                @change="toggleType(type, ($event.target as HTMLInputElement).checked)"
                            />
                            <span class="font-mono">{{ type }}</span>
                        </label>
                    </div>
                </div>

                <div class="border-t border-sidebar-border/70 pt-4 dark:border-sidebar-border">
                    <h2 class="text-sm font-medium">Pakete freigeben</h2>
                    <p class="mb-3 text-xs text-muted-foreground">
                        Wer ein Paket der Betreiber-Organisation für andere Organisationen freigeben darf. Ein freigegebenes („geteiltes“) Paket lässt
                        sich jeder Registry der Instanz zuweisen, nicht nur denen der besitzenden Organisation. Diese Einstellung ändern kann
                        ausschließlich ein Super-Admin — sonst könnte sich jemand die Berechtigung selbst erteilen.
                    </p>
                    <SearchableSelect
                        id="shared_package_role"
                        v-model="form.shared_package_role"
                        class="max-w-md"
                        :options="props.sharedPackageRoles"
                    />
                    <InputError :message="form.errors.shared_package_role" />
                </div>

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
