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
import { dockerDomainNote, dockerSetupSnippet, dockerStepTitle, type SetupAudience } from './dockerSetup';
import { noEcosystemMessage, offersMinting, offersPublishing, stepsForEcosystems } from './registrySetup';

interface Snippets {
    composer: string;
    auth: string;
    npm: string;
    pip: string;
    twine: string;
    // Docker's raw facts, not a finished snippet — see SetupSnippetBuilder::for()'s doc
    // comment. Required, not optional: `dockerHost` stopped being nullable when
    // ResolveOciContext gave every registry a working address on the instance host, and an
    // optional field here would let a caller silently forward a payload that no longer
    // carries them.
    dockerHost: string;
    dockerRepositoryPrefix: string;
    dockerHasDomain: boolean;
    dockerExample?: string | null;
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
    // Which ecosystems to show setup steps for — the types the organization MAY serve
    // (RegistryTypeService::effectiveFor()), never the types of the packages already in the
    // registry. Both callers used to derive it from the package list, which meant an empty
    // registry showed no instructions at all; for images that is the normal first state,
    // because nobody pushes a first image into a registry whose address is written nowhere.
    //
    // REQUIRED, for the same reason `audience` below is: with the prop meaning "permitted"
    // rather than "present", `[]` is a definite answer — this organization may serve
    // nothing — and any default substituted for it prints instructions against endpoints
    // that answer 404. See stepsForEcosystems() in registrySetup.ts.
    types: string[];
    // Who is reading. It changes exactly one sentence — the note under the Docker snippet
    // on a registry with no custom domain — and it is REQUIRED rather than defaulted,
    // because the wrong default is not a cosmetic miss: the operator's version names
    // Registry → Domains, a console page a portal account cannot open at all.
    audience: SetupAudience;
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

/**
 * The token stand-in the server writes into every snippet, so that substituting a freshly
 * minted token is a plain string replace.
 *
 * IT IS A CONTRACT, NOT COPY, and that is why it is not a string to reword in passing.
 * `SetupSnippetBuilder` emits this exact literal into composer's auth.json, .npmrc,
 * pip.conf, .netrc and twine's config, and SetupSnippetBuilderTest asserts it there three
 * times across two cases. Changing it on this side alone would redden NOTHING: the
 * substitution below would simply stop matching, and the customer would copy a snippet
 * still carrying the placeholder after minting a token — into their own .npmrc. A
 * rewording is a coordinated change to the builder, this constant and those assertions at
 * once.
 *
 * It is also not a sentence addressed to the reader. It is angle-bracketed metasyntax
 * inside a config file, the same shape as the `<slug>` and `<organisation>` placeholders
 * this console already uses, so it carries no address form (formal or informal) at all.
 */
const PLACEHOLDER = '<token>';

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

/**
 * The five snippets a minted token gets substituted into. Deliberately NOT `Snippets`: the
 * Docker step's fields are raw facts rather than text, they carry no `<token>` at all
 * (`docker login` prompts for the password instead of taking it on a command line that
 * lands in the shell history), and typing this as the whole payload would force four fields
 * to be copied through a map that has nothing to do with them.
 */
type TextSnippetKey = 'composer' | 'auth' | 'npm' | 'pip' | 'twine';

const substituted = computed<Record<TextSnippetKey, string>>(() => {
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
    { key: 'docker', eco: 'docker', title: dockerStepTitle() },
] as const;

interface Step {
    key: string;
    title: string;
    /** The copyable block. Every step has one — there is no address-less state left. */
    content: string;
    /** A sentence under the block, or '' for none. Today only the Docker step sets it, on a
     *  registry with no custom domain: the commands above work as they stand, and this says
     *  what a hostname of its own would change. */
    note: string;
}

const steps = computed<Step[]>(() =>
    stepsForEcosystems(stepDefs, props.types).map((s) => {
        if (s.key === 'docker') {
            return {
                key: s.key,
                title: s.title,
                // Built from the raw facts rather than read out of `substituted`: this
                // block carries no <token> to replace at all — `docker login` prompts
                // for the password instead of taking it on a command line that lands
                // in the shell history.
                content: dockerSetupSnippet(props.snippets.dockerHost, props.snippets.dockerRepositoryPrefix, props.snippets.dockerExample),
                note: props.snippets.dockerHasDomain ? '' : dockerDomainNote(props.audience),
            };
        }

        return { key: s.key, title: s.title, content: substituted.value[s.key], note: '' };
    }),
);

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
                    — erstellen Sie ein neues, um es direkt in die Snippets zu übernehmen.
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
            <!-- The organization may serve nothing: `types` is empty, every registry
                 endpoint answers 404, and there is no instruction here that would work. It
                 says so rather than falling back to a set of ecosystems nobody enabled —
                 see stepsForEcosystems() and noEcosystemMessage() in registrySetup.ts. -->
            <p
                v-if="!steps.length"
                class="rounded-xl border border-sidebar-border/70 px-4 py-3 text-sm text-muted-foreground dark:border-sidebar-border"
            >
                {{ noEcosystemMessage() }}
            </p>
            <div v-for="step in steps" :key="step.key" class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <div class="flex items-center justify-between gap-4 border-b border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border">
                    <h3 class="font-medium">{{ step.title }}</h3>
                    <Button variant="outline" size="sm" @click="copy(step.content, step.key)">
                        <component :is="copiedKey === step.key ? Check : Copy" class="size-4" />
                        {{ copiedKey === step.key ? 'Kopiert!' : 'Kopieren' }}
                    </Button>
                </div>
                <pre class="overflow-x-auto px-4 py-3 font-mono text-sm">{{ step.content }}</pre>
                <!-- Plate 3, rewritten: a registry without its own hostname is no longer a
                     dead end — the commands above address it on the instance host — so this
                     sits UNDER the block as a note rather than replacing it. Its wording
                     differs by audience; see dockerSetup.ts's dockerDomainNote(). -->
                <p v-if="step.note" class="border-t border-sidebar-border/70 px-4 py-3 text-sm text-muted-foreground dark:border-sidebar-border">
                    {{ step.note }}
                </p>
            </div>
        </div>
    </div>
</template>
