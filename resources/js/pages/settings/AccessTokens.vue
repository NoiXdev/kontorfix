<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    tokens: { id: string; name: string; ability: 'read' | 'publish'; group: string | null; last_used_at: string | null; expires_at: string | null }[];
    groups: { id: string; name: string }[];
}>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Zugriffstokens',
        href: '/settings/tokens',
    },
];

const page = usePage<SharedData>();
const plainTextToken = computed(() => page.props.flash?.plainTextToken ?? null);

const tokenCalloutDismissed = ref(false);
watch(plainTextToken, (value) => {
    if (value) {
        tokenCalloutDismissed.value = false;
    }
});

const showTokenCallout = computed(() => !!plainTextToken.value && !tokenCalloutDismissed.value);

const tokenCopied = ref(false);

async function copyToken() {
    if (!plainTextToken.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(plainTextToken.value);
        tokenCopied.value = true;
        setTimeout(() => (tokenCopied.value = false), 2000);
    } catch {
        // Clipboard API not available (insecure context) — the token can be selected manually.
        tokenCopied.value = false;
    }
}

const form = useForm({
    name: '',
    group_id: '',
    ability: 'read' as 'read' | 'publish',
});

function submit() {
    form.transform((d) => ({ ...d, group_id: d.group_id || null })).post(route('tokens.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('name'),
    });
}

function abilityLabel(ability: 'read' | 'publish') {
    return ability === 'publish' ? 'Veröffentlichen' : 'Lesen';
}

function destroyToken(id: string) {
    router.delete(route('tokens.destroy', id), {
        preserveScroll: true,
        onBefore: () => confirm('Token wirklich widerrufen?'),
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Zugriffstokens" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Zugriffstokens"
                    description="Persönliche Tokens für Composer/npm — global oder auf eine Registry beschränkt."
                />

                <div v-if="showTokenCallout" class="rounded-xl border border-copper/30 bg-copper/10 p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1 space-y-2">
                            <p class="font-medium text-copper-hi">Neuer Token erstellt</p>
                            <p class="select-all break-all rounded-md border border-copper/20 bg-background/60 px-3 py-2 font-mono text-sm">
                                {{ plainTextToken }}
                            </p>
                            <p class="text-sm text-muted-foreground">Dieser Token wird nur einmal angezeigt. Bewahre ihn sicher auf.</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <Button variant="outline" size="sm" @click="copyToken">
                                <Copy class="size-4" />
                                {{ tokenCopied ? 'Kopiert!' : 'Kopieren' }}
                            </Button>
                            <Button variant="ghost" size="sm" @click="tokenCalloutDismissed = true">Schließen</Button>
                        </div>
                    </div>
                </div>

                <form
                    class="grid gap-4 rounded-xl border border-sidebar-border/70 p-4 sm:grid-cols-[1fr_1fr_auto_auto] sm:items-end dark:border-sidebar-border"
                    @submit.prevent="submit"
                >
                    <div class="grid gap-2">
                        <Label for="token_name">Name</Label>
                        <Input id="token_name" v-model="form.name" placeholder="ci-token" autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="token_group">Geltungsbereich</Label>
                        <select
                            id="token_group"
                            v-model="form.group_id"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        >
                            <option value="">Global (alle Registries)</option>
                            <option v-for="g in props.groups" :key="g.id" :value="g.id">{{ g.name }}</option>
                        </select>
                        <InputError :message="form.errors.group_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="token_ability">Recht</Label>
                        <select
                            id="token_ability"
                            v-model="form.ability"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        >
                            <option value="read">Lesen</option>
                            <option value="publish">Veröffentlichen</option>
                        </select>
                        <InputError :message="form.errors.ability" />
                    </div>

                    <Button type="submit" :disabled="form.processing">
                        <Plus class="size-4" />
                        Token erstellen
                    </Button>
                </form>

                <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                            <tr>
                                <th class="px-4 py-3 font-medium">Name</th>
                                <th class="px-4 py-3 font-medium">Geltungsbereich</th>
                                <th class="px-4 py-3 font-medium">Recht</th>
                                <th class="px-4 py-3 font-medium">Zuletzt genutzt</th>
                                <th class="px-4 py-3 font-medium">Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="token in props.tokens"
                                :key="token.id"
                                class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                            >
                                <td class="px-4 py-3 font-mono">{{ token.name }}</td>
                                <td class="px-4 py-3">{{ token.group ?? 'Global' }}</td>
                                <td class="px-4 py-3">{{ abilityLabel(token.ability) }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ token.last_used_at ?? 'nie' }}</td>
                                <td class="px-4 py-3">
                                    <Button variant="ghost" size="icon" aria-label="Token widerrufen" @click="destroyToken(token.id)">
                                        <Trash2 class="size-4 text-destructive" />
                                    </Button>
                                </td>
                            </tr>
                            <tr v-if="props.tokens.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">Noch keine Tokens erstellt.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
