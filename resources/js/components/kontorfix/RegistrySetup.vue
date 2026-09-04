<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { type SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/vue3';
import { Check, Copy, Plus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { type ParameterValue, type RouteList } from 'ziggy-js';
import { offersMinting, offersPublishing } from './registrySetup';

interface Snippets {
    composer: string;
    auth: string;
    npm: string;
    pip: string;
    twine: string;
}

interface PersonalToken {
    id: string;
    name: string;
    ability: string;
}

const props = defineProps<{
    snippets: Snippets;
    storeRoute: keyof RouteList;
    // Route parameters for `storeRoute`, for a store route that is addressed rather than
    // global — the portal's token route carries the organization slug in its path.
    storeRouteParams?: ParameterValue;
    storePayload?: Record<string, unknown>;
    personalTokens?: PersonalToken[];
    // Which ecosystems to show setup steps for. Omitted → all.
    types?: string[];
    // Whether to offer minting at all. Omitted → yes, which is the console's case: every
    // caller there is already an admin or maintainer of the organization. The portal passes
    // the shared `portal.may_mint_tokens`, so an operator standing in a customer's portal is
    // not offered a form the server would then refuse — the same reason portal/Registry.vue
    // hides its own token form, and this is the portal's SECOND way to the same POST.
    mayMint?: boolean;
    // Whether to offer the publish ability. Omitted → yes, the console's case again: an
    // admin or maintainer of the organization, which is exactly whom
    // RegistryTokenPolicy::create() allows Publish. The portal passes
    // `portal.may_publish_tokens`, so a plain member is offered "Lesen" alone rather than a
    // choice the server answers 403 to — the refusal PublishTokenEscalationTest already
    // pins.
    mayPublish?: boolean;
}>();

const mayMint = computed(() => offersMinting(props.mayMint));

// The explicit return type keeps `value` as the literal union `form.ability` is typed as,
// rather than the widened `string` a plain object literal infers — SearchableSelect's
// `v-model` needs the two to line up exactly. Same shape as portal/Registry.vue's own
// options list, which gates on the same prop.
const abilityOptions = computed((): { value: 'read' | 'publish'; label: string }[] =>
    offersPublishing(props.mayPublish)
        ? [
              { value: 'read', label: 'Lesen' },
              { value: 'publish', label: 'Veröffentlichen' },
          ]
        : [{ value: 'read', label: 'Lesen' }],
);

const PLACEHOLDER = '<dein-token>';

const sessionTokens = ref<{ name: string; value: string }[]>([]);
const activeToken = ref('');

const page = usePage<SharedData>();
const plainTextToken = computed(() => page.props.flash?.plainTextToken ?? null);

const showCreate = ref((props.personalTokens?.length ?? 0) === 0);

const form = useForm({
    name: '',
    ability: 'read' as 'read' | 'publish',
    ...(props.storePayload ?? {}),
});

const awaitingToken = ref(false);
const pendingName = ref('');

watch(plainTextToken, (value) => {
    if (value && awaitingToken.value) {
        sessionTokens.value.push({ name: pendingName.value, value });
        activeToken.value = value;
        awaitingToken.value = false;
        showCreate.value = false;
    }
});

function createAndInsert() {
    pendingName.value = form.name;
    awaitingToken.value = true;
    form.transform((data) => ({ ...data, ...(props.storePayload ?? {}) })).post(route(props.storeRoute, props.storeRouteParams), {
        preserveScroll: true,
        onSuccess: () => form.reset('name'),
        onError: () => {
            awaitingToken.value = false;
        },
    });
}

const substituted = computed<Snippets>(() => {
    const t = activeToken.value;
    const sub = (s: string) => (t ? s.split(PLACEHOLDER).join(t) : s);
    return {
        composer: sub(props.snippets.composer),
        auth: sub(props.snippets.auth),
        npm: sub(props.snippets.npm),
        pip: sub(props.snippets.pip),
        twine: sub(props.snippets.twine),
    };
});

// Each step belongs to an ecosystem so it can be shown only for the registry's types.
const stepDefs = [
    { key: 'composer', eco: 'composer', title: 'Composer einrichten' },
    { key: 'auth', eco: 'composer', title: 'Composer-Zugang (auth.json)' },
    { key: 'npm', eco: 'npm', title: 'npm einrichten' },
    { key: 'pip', eco: 'python', title: 'pip einrichten' },
    { key: 'twine', eco: 'python', title: 'Veröffentlichen mit twine' },
] as const;

const steps = computed(() => {
    const show = props.types && props.types.length ? props.types : ['composer', 'npm', 'python'];
    return stepDefs.filter((s) => show.includes(s.eco)).map((s) => ({ key: s.key, title: s.title, content: substituted.value[s.key] }));
});

const copiedKey = ref<string | null>(null);

async function copy(text: string, key: string) {
    try {
        await navigator.clipboard.writeText(text);
        copiedKey.value = key;
        setTimeout(() => {
            if (copiedKey.value === key) {
                copiedKey.value = null;
            }
        }, 2000);
    } catch {
        copiedKey.value = null;
    }
}

function selectSession(value: string) {
    activeToken.value = value;
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <div class="flex flex-col gap-2">
                <Label>Token für die Snippets</Label>
                <div class="flex flex-wrap items-center gap-2">
                    <select
                        class="flex h-10 min-w-56 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden"
                        :value="activeToken"
                        @change="selectSession(($event.target as HTMLSelectElement).value)"
                    >
                        <option value="">Platzhalter ({{ PLACEHOLDER }})</option>
                        <optgroup v-if="sessionTokens.length" label="In dieser Sitzung erstellt (einsatzbereit)">
                            <option v-for="t in sessionTokens" :key="t.value" :value="t.value">{{ t.name }}</option>
                        </optgroup>
                        <optgroup v-if="personalTokens && personalTokens.length" label="Vorhandene Tokens (Wert verborgen)">
                            <option v-for="t in personalTokens" :key="t.id" value="" disabled>{{ t.name }} · {{ t.ability }}</option>
                        </optgroup>
                    </select>
                    <Button v-if="mayMint" variant="outline" size="sm" type="button" @click="showCreate = !showCreate">
                        <Plus class="size-4" />
                        Token erstellen
                    </Button>
                </div>
                <!-- Same `v-if` as the button it describes: it ends by telling the reader
                     to create a token, which is advice with nothing to act on once the
                     button above it is hidden. -->
                <p v-if="mayMint" class="text-xs text-muted-foreground">
                    Aus Sicherheitsgründen wird ein Token nur einmal im Klartext angezeigt. Vorhandene Tokens lassen sich daher nicht erneut einsetzen
                    — erstelle ein neues, um es direkt in die Snippets zu übernehmen.
                </p>
            </div>

            <form v-if="mayMint && showCreate" class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end" @submit.prevent="createAndInsert">
                <div class="grid gap-1.5">
                    <Label for="setup_token_name">Name</Label>
                    <Input id="setup_token_name" v-model="form.name" placeholder="ci-token" autocomplete="off" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="setup_token_ability">Recht</Label>
                    <SearchableSelect
                        id="setup_token_ability"
                        v-model="form.ability"
                        class="min-w-40"
                        :options="abilityOptions"
                    />
                </div>
                <Button type="submit" :disabled="form.processing || !form.name">Erstellen &amp; einsetzen</Button>
            </form>
        </div>

        <div class="grid gap-4">
            <div v-for="step in steps" :key="step.key" class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <div class="flex items-center justify-between gap-4 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                    <h3 class="font-medium">{{ step.title }}</h3>
                    <Button variant="outline" size="sm" @click="copy(step.content, step.key)">
                        <component :is="copiedKey === step.key ? Check : Copy" class="size-4" />
                        {{ copiedKey === step.key ? 'Kopiert!' : 'Kopieren' }}
                    </Button>
                </div>
                <pre class="overflow-x-auto px-4 py-3 font-mono text-sm">{{ step.content }}</pre>
            </div>
        </div>
    </div>
</template>
