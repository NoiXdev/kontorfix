<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import ActivityTimeline from '@/components/kontorfix/ActivityTimeline.vue';
import OrgPortalHint from '@/components/kontorfix/OrgPortalHint.vue';
import PackagePicker from '@/components/kontorfix/PackagePicker.vue';
import RegistrySetup from '@/components/kontorfix/RegistrySetup.vue';
import SharedBadge from '@/components/kontorfix/SharedBadge.vue';
import { SearchableSelect } from '@/components/ui/searchable-select';
import StatusPill from '@/components/kontorfix/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useRegistryTypes } from '@/composables/useRegistryTypes';
import AppLayout from '@/layouts/AppLayout.vue';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { CalendarClock, Copy, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import {
    availabilityLabel,
    availabilityNote,
    availabilityOf,
    endsImmediately,
    expiryConsequence,
    immediateWithdrawalNote,
    type AssignedPackage,
    type NameHolding,
} from './packageAssignment';

interface GroupInfo {
    id: string;
    name: string;
    slug: string;
    public: boolean;
    portal_enabled: boolean;
    // Whether the OWNING organization's customer portal exists at all — see
    // Organization::portal_enabled's docblock. Not the same question as `portal_enabled`
    // above, which only answers whether this registry appears inside that portal once it
    // exists.
    organization_portal_enabled: boolean;
    organization: string | null;
    organization_id: string | null;
    // Supplied by App\Services\Registry\RegistryUrl — the URL form is stated once, in PHP.
    // `url_path` is the path as shown in the header, `url` the canonical URL as it stands,
    // and `url_pattern` that URL with `{registry}` where the slug goes, for the "and this is
    // what it becomes" half of the confirmation. Nothing here is assembled in this file.
    url_path: string;
    url: string;
    url_pattern: string;
}

// The assignment, not just the package: `shared` says whether other tenants receive it
// too, and `available_until`/`in_force` say whether this registry actually serves it right
// now. See `./packageAssignment` for what an expiry does — it is not what it looks like.
type PackageRow = AssignedPackage;

interface DomainRow {
    id: string;
    hostname: string;
}

interface UpstreamRow {
    id: string;
    type: string;
    url: string;
    policy: string;
}

interface TokenRow {
    id: string;
    name: string;
    ability: string;
    last_used_at?: string | null;
}

interface Setup {
    composer: string;
    auth: string;
    npm: string;
    pip: string;
    twine: string;
    // Docker's raw facts, forwarded verbatim to RegistrySetup — see SetupSnippetBuilder.
    // Named here rather than left off: this interface used to omit them, which let the page
    // forward a payload whose Docker half it did not describe at all.
    dockerHost: string;
    dockerRepositoryPrefix: string;
    dockerHasDomain: boolean;
    dockerExample: string | null;
}

interface ActivityRow {
    id: number;
    log_name: string | null;
    event: string | null;
    description: string;
    subject_type: string | null;
    subject_label: string | null;
    causer: string | null;
    changes: Record<string, unknown>;
    created_at: string | null;
    created_at_exact: string | null;
}

const props = defineProps<{
    group: GroupInfo;
    packages: PackageRow[];
    domains: DomainRow[];
    upstreams: UpstreamRow[];
    tokens: TokenRow[];
    setup: Setup;
    // What this organization MAY serve (RegistryTypeService::effectiveFor()), not what is
    // already in the registry. This used to be `[...new Set(packages.map(p => p.type))]`
    // computed in this file, and a registry with no packages therefore showed no setup
    // instructions at all — the state every registry is in on the day it is created.
    types: string[];
    stats: { downloads: number; storage_bytes: number; packages: number };
    activities: ActivityRow[];
    // The application's own calendar day (`YYYY-MM-DD`), from the controller. Not derived
    // from the browser clock: the application runs in UTC and a browser west of it is on
    // the previous day for several hours, so the two would disagree about whether a chosen
    // date has already passed.
    today: string;
    // Whether the current caller may open admin.organizations.show — customer/organization
    // management is super-admin only (see EnsureSuperAdmin). Decided server-side rather than
    // re-derived here: see GroupController::show()'s docblock on the field of the same name.
    can_manage_organization: boolean;
}>();

function formatBytes(bytes: number | null | undefined): string {
    if (!bytes) {
        return '—';
    }
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let i = 0;
    while (value >= 1024 && i < units.length - 1) {
        value /= 1024;
        i++;
    }
    return `${value.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Gruppen', href: route('admin.groups.index') },
    { title: props.group.name, href: route('admin.groups.show', props.group.id) },
];

const form = useForm({
    name: props.group.name,
    slug: props.group.slug,
    public: props.group.public,
    portal_enabled: props.group.portal_enabled,
});

const slugChanged = computed(() => form.slug !== props.group.slug);

// The URL the registry would answer to after the change, taken from the pattern the
// controller supplied — this file substitutes, it never builds a registry URL.
const nextUrl = computed(() => props.group.url_pattern.replace('{registry}', form.slug || '…'));

function save() {
    form.put(route('admin.groups.update', props.group.id), {
        preserveScroll: true,
        // Same shape as the other confirmations in this console (admin/oidc/Index.vue's
        // destroyProvider and the registry delete on the group list): a native confirm in
        // `onBefore`, so a declined dialog cancels the request outright.
        onBefore: () =>
            !slugChanged.value ||
            confirm(
                'Slug der Registry ändern?\n\n' +
                    `Bisher:  ${props.group.url}\n` +
                    `Neu:     ${nextUrl.value}\n\n` +
                    'Die bisherige Adresse antwortet danach nicht mehr. Bestehende Client-Konfigurationen, ' +
                    'die auf sie zeigen (composer.json, .npmrc, pip.conf, CI-Variablen), funktionieren erst ' +
                    'wieder, wenn sie auf die neue Adresse umgestellt sind.',
            ),
    });
}

// --- Package assignment (add existing/quick-created packages to this registry) ---
// Must match PackagePicker.vue's own local `Pkg` — same reasoning as GroupSheet.vue's copy.
const packagesToAdd = ref<{ id: string; name: string; type: 'composer' | 'npm' | 'python' | 'docker'; shared: boolean }[]>([]);

function addPackages() {
    if (packagesToAdd.value.length === 0) {
        return;
    }
    router.post(
        route('admin.groups.packages.store', props.group.id),
        { package_ids: packagesToAdd.value.map((p) => p.id) },
        {
            preserveScroll: true,
            onSuccess: () => {
                packagesToAdd.value = [];
            },
        },
    );
}

function removePackage(packageId: string) {
    router.delete(route('admin.groups.packages.destroy', [props.group.id, packageId]), { preserveScroll: true });
}

const newDomain = ref('');

function addDomain() {
    router.post(
        route('admin.domains.store'),
        { group_id: props.group.id, hostname: newDomain.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                newDomain.value = '';
            },
        },
    );
}

function removeDomain(id: string) {
    router.delete(route('admin.domains.destroy', id), { preserveScroll: true });
}

const newUpstream = ref({
    type: 'composer' as 'composer' | 'npm' | 'python',
    url: '',
    policy: 'proxy' as 'proxy' | 'strict',
    auth_token: '',
    priority: 0,
});

function addUpstream() {
    router.post(
        route('admin.upstreams.store'),
        {
            group_id: props.group.id,
            type: newUpstream.value.type,
            url: newUpstream.value.url,
            policy: newUpstream.value.policy,
            auth_token: newUpstream.value.auth_token || null,
            priority: newUpstream.value.priority,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                newUpstream.value = { type: 'composer', url: '', policy: 'proxy', auth_token: '', priority: 0 };
            },
        },
    );
}

function removeUpstream(id: string) {
    router.delete(route('admin.upstreams.destroy', id), { preserveScroll: true });
}

const pageProps = usePage();
// Attaching a hostname is operator-only server-side (routes/web.php) — a hostname is an
// instance-wide, globally unique claim. Detaching stays with the owning organization.
const canAttachDomain = computed(() => (pageProps.props.auth as { can?: { super?: boolean } } | undefined)?.can?.super === true);

// --- Availability of a single assignment (group_package.available_until) ---
//
// One row at a time: the editor opens on the row it edits, seeded with the day that row
// already carries. `editedUntil` is the raw `YYYY-MM-DD` of the date input; the empty string
// is "no date", which the server is sent as null.
const editingAssignment = ref<string | null>(null);
const editedUntil = ref('');
const savingAssignment = ref(false);

// A date already in the past is allowed, and is the safe way to withdraw a share: delivery
// stops at once while the name stays suppressed against the upstream, which detaching does
// not do. The field has no lower bound, so the consequence is spelled out while the operator
// is still choosing. The comparison lives in `./packageAssignment` and runs against the
// SERVER's day, never the browser's.
const withdrawsImmediately = computed(() => endsImmediately(editedUntil.value, props.today));

// The row whose editor was last submitted. The error bag is page-wide, so without this a
// refusal on one assignment would still be showing in the editor of the next row the
// operator opens, against a package it says nothing about.
const submittedAssignment = ref<string | null>(null);

function editAvailability(pkg: PackageRow) {
    editingAssignment.value = pkg.id;
    editedUntil.value = pkg.available_until ?? '';
}

function cancelAvailability() {
    editingAssignment.value = null;
    editedUntil.value = '';
    // Otherwise reopening the same row after cancelling brings back the refusal it was
    // cancelled out of — stale rather than misleading, but there is nothing to say.
    submittedAssignment.value = null;
}

/**
 * Whether a second row follows this package's main row — the editor, or the consequence
 * note. Stated once because the main row's separator and those two rows' `v-if`s have to
 * agree; when they drifted apart the note rendered below the separator and read as the next
 * package's.
 */
function hasBlockRow(pkg: PackageRow): boolean {
    return editingAssignment.value === pkg.id || noteFor(pkg) !== null;
}

/**
 * What the copy needs about this row: whether the organization already holds the name — the
 * one thing in the text an operator can act on wrongly — and how to name the ecosystem, which
 * the rule is per. The label comes from the PackageType enum through the shared composable,
 * so the console names ecosystems in one place.
 */
// The PackageType enum's own labels, shared to the frontend via Inertia. Not a table here:
// the console names ecosystems in one place.
const { label: registryTypeLabel } = useRegistryTypes();

function holdingFor(pkg: PackageRow): NameHolding {
    return { ownedByRegistryOrg: pkg.owned_by_registry_org, type: pkg.type, typeLabel: registryTypeLabel(pkg.type) };
}

/** This row's consequence note. */
function noteFor(pkg: PackageRow): string | null {
    return availabilityNote(availabilityOf(pkg), holdingFor(pkg));
}

// The guard that refuses a name collision (App\Services\Package\SharedAssignment) keys its
// refusal `package_ids` — the key every other writer of this pivot uses. It is not this
// form's field name, but it is the guard's, and restating the rule under a second key would
// be a second statement of it. The one sentence prepended here is context, not a second
// statement: the guard's own text names the conflict and the remedy but not what was refused,
// which on the attach path is obvious and here is not.
//
// Shown only on the row that was actually submitted; the error bag itself is page-wide.
const assignmentErrors = computed(() => {
    const errors =
        submittedAssignment.value !== null && submittedAssignment.value === editingAssignment.value
            ? (pageProps.props.errors as Record<string, string> | undefined)
            : undefined;

    return {
        available_until: errors?.available_until,
        collision:
            errors?.package_ids === undefined ? undefined : `Die Verfügbarkeit der Zuweisung kann nicht geändert werden: ${errors.package_ids}`,
    };
});

function saveAvailability(packageId: string) {
    savingAssignment.value = true;
    submittedAssignment.value = packageId;
    router.put(
        route('admin.groups.packages.update', [props.group.id, packageId]),
        { available_until: editedUntil.value === '' ? null : editedUntil.value },
        {
            preserveScroll: true,
            onSuccess: () => cancelAvailability(),
            onFinish: () => {
                savingAssignment.value = false;
            },
        },
    );
}

const plainTextToken = computed(() => (pageProps.props.flash as { plainTextToken?: string } | undefined)?.plainTextToken ?? null);
const tokenCalloutDismissed = ref(false);
watch(plainTextToken, (v) => {
    if (v) {
        tokenCalloutDismissed.value = false;
    }
});
const showTokenCallout = computed(() => !!plainTextToken.value && !tokenCalloutDismissed.value);

const tokenForm = useForm({
    organization_id: props.group.organization_id,
    group_id: props.group.id,
    name: '',
    ability: 'read' as 'read' | 'publish',
});

function submitToken() {
    tokenForm.post(route('admin.tokens.store'), { preserveScroll: true, onSuccess: () => tokenForm.reset('name') });
}

function destroyToken(id: string) {
    router.delete(route('admin.tokens.destroy', id), { preserveScroll: true, onBefore: () => confirm('Token wirklich widerrufen?') });
}

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
        tokenCopied.value = false;
    }
}
</script>

<template>
    <Head :title="props.group.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4">
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-semibold">{{ props.group.name }}</h1>
                    <span
                        v-if="props.group.public"
                        class="inline-flex items-center rounded-full border border-verdigris/30 bg-verdigris/15 px-2.5 py-0.5 text-xs font-medium text-verdigris"
                    >
                        Öffentlich
                    </span>
                    <span
                        v-else
                        class="inline-flex items-center rounded-full border border-border bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground"
                    >
                        Privat
                    </span>
                    <button
                        type="button"
                        class="ml-auto text-sm text-muted-foreground underline-offset-4 hover:underline"
                        @click="router.get(route('admin.activity.index'), { subject_type: 'Group', subject_id: props.group.id })"
                    >
                        Aktivität ansehen
                    </button>
                </div>
                <p class="text-sm text-muted-foreground">
                    Diese Gruppe <strong>ist</strong> eine Registry — erreichbar unter <code class="font-mono">{{ props.group.url_path }}</code
                    ><template v-if="props.group.organization"> · Kunde / Org: {{ props.group.organization }}</template>
                </p>
                <p
                    v-if="!props.group.portal_enabled"
                    class="inline-flex w-fit items-center rounded-md border border-border bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                >
                    Portal deaktiviert — reine Paketsammlung, im Kundenportal ausgeblendet
                </p>

                <!-- Registry-level usage stats (rolled up over all packages) -->
                <div class="grid max-w-lg grid-cols-3 gap-3">
                    <div class="rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                        <div class="text-xs text-muted-foreground">Downloads</div>
                        <div class="text-lg font-semibold">{{ props.stats.downloads.toLocaleString('de-DE') }}</div>
                    </div>
                    <div class="rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                        <div class="text-xs text-muted-foreground">Speicher</div>
                        <div class="text-lg font-semibold">{{ formatBytes(props.stats.storage_bytes) }}</div>
                    </div>
                    <div class="rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                        <div class="text-xs text-muted-foreground">Pakete</div>
                        <div class="text-lg font-semibold">{{ props.stats.packages }}</div>
                    </div>
                </div>
            </div>

            <Tabs default-value="bearbeiten">
                <TabsList>
                    <TabsTrigger value="bearbeiten">Bearbeiten</TabsTrigger>
                    <TabsTrigger value="pakete">Pakete</TabsTrigger>
                    <TabsTrigger value="domains">Domains</TabsTrigger>
                    <TabsTrigger value="upstreams">Upstreams</TabsTrigger>
                    <TabsTrigger value="tokens">Tokens</TabsTrigger>
                    <TabsTrigger value="einrichtung">Einrichtung</TabsTrigger>
                    <TabsTrigger value="aktivitaet">Aktivität</TabsTrigger>
                </TabsList>

                <TabsContent value="bearbeiten">
                    <section class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <form class="flex flex-col gap-4" @submit.prevent="save">
                            <div class="flex flex-col gap-1.5">
                                <label for="registry-name" class="text-sm font-medium">Name</label>
                                <input
                                    id="registry-name"
                                    v-model="form.name"
                                    type="text"
                                    class="w-full max-w-md rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:border-ring focus:ring-1 focus:ring-ring focus:outline-hidden"
                                />
                                <p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
                            </div>

                            <label class="flex items-center gap-2 text-sm">
                                <Switch v-model="form.public" />
                                Öffentlich (ohne Token lesbar)
                            </label>

                            <label class="flex items-start gap-2 text-sm">
                                <Switch v-model="form.portal_enabled" class="mt-1" />
                                <span>
                                    Im Kundenportal als Registry anzeigen
                                    <span class="block text-xs text-muted-foreground">
                                        Deaktivieren, wenn die Gruppe nur eine Paketsammlung ist, deren Pakete anderen Registries derselben
                                        Organisation zugewiesen werden.
                                    </span>
                                </span>
                            </label>

                            <OrgPortalHint
                                :portal-enabled="props.group.organization_portal_enabled"
                                :can-manage-organization="props.can_manage_organization"
                                :organization-id="props.group.organization_id"
                            />

                            <div class="flex flex-col gap-1.5">
                                <label for="registry-slug" class="text-sm font-medium">Slug</label>
                                <input
                                    id="registry-slug"
                                    v-model="form.slug"
                                    type="text"
                                    class="w-full max-w-md rounded-md border border-input bg-background px-3 py-2 font-mono text-sm shadow-xs focus:border-ring focus:ring-1 focus:ring-ring focus:outline-hidden"
                                />
                                <p class="font-mono text-xs text-muted-foreground">{{ nextUrl }}</p>
                                <p v-if="slugChanged" class="text-xs text-copper-hi">
                                    Der Slug ist Teil der Registry-Adresse. Nach dem Speichern antwortet
                                    <span class="font-mono">{{ props.group.url }}</span> nicht mehr — bestehende Client-Konfigurationen müssen auf die
                                    neue Adresse umgestellt werden.
                                </p>
                                <p v-else class="text-xs text-muted-foreground">
                                    Der Slug ist der Registry-Endpunkt. Eine Änderung wird vor dem Speichern noch einmal bestätigt.
                                </p>
                                <p v-if="form.errors.slug" class="text-sm text-destructive">{{ form.errors.slug }}</p>
                            </div>

                            <div>
                                <Button type="submit" :disabled="form.processing">Speichern</Button>
                            </div>
                        </form>
                    </section>
                </TabsContent>

                <TabsContent value="pakete">
                    <section class="flex flex-col gap-4">
                        <div class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                            <div>
                                <h2 class="text-sm font-medium">Pakete zuweisen</h2>
                                <p class="text-xs text-muted-foreground">
                                    Vorhandenes Paket suchen oder direkt neu anlegen, dann dieser Registry hinzufügen.
                                </p>
                            </div>
                            <PackagePicker v-model="packagesToAdd" create-button :create-group-id="props.group.id" />
                            <div>
                                <Button type="button" :disabled="packagesToAdd.length === 0" @click="addPackages">
                                    <Plus class="size-4" />
                                    {{ packagesToAdd.length > 0 ? `${packagesToAdd.length} Paket(e) hinzufügen` : 'Hinzufügen' }}
                                </Button>
                            </div>
                        </div>

                        <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                                    <tr>
                                        <th class="px-4 py-3 font-medium">Name</th>
                                        <th class="px-4 py-3 font-medium">Typ</th>
                                        <th class="px-4 py-3 font-medium">Status</th>
                                        <th class="px-4 py-3 font-medium">Verfügbarkeit</th>
                                        <th class="px-4 py-3 font-medium">Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="pkg in props.packages" :key="pkg.id">
                                        <!-- The separator belongs to the LAST row of this package's block, so
                                             the main row gives it up whenever the note or the editor follows it.
                                             Keeping it here would put the red 404 explanation below a separator,
                                             where it reads as belonging to the next package — an outage warning
                                             attributed to the wrong package is worse than none. -->
                                        <tr
                                            :class="
                                                hasBlockRow(pkg) ? '' : 'border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border'
                                            "
                                        >
                                            <td class="px-4 py-3 font-mono">
                                                <!-- Same rule as the row actions below, for the same reason: an
                                                     operator who may not manage this assignment is not permitted on
                                                     the package page either, so a link there answers 403. Offering
                                                     it and refusing it is the defect the hidden actions avoid; the
                                                     name is still shown, because they are entitled to know what
                                                     their registry carries — only not to open it. -->
                                                <div class="flex items-center gap-2">
                                                    <Link v-if="pkg.manageable" :href="route('admin.packages.show', pkg.id)" class="hover:underline">
                                                        {{ pkg.name }}
                                                    </Link>
                                                    <span v-else>{{ pkg.name }}</span>
                                                    <SharedBadge v-if="pkg.shared" />
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">{{ pkg.type }}</td>
                                            <td class="px-4 py-3"><StatusPill :status="pkg.sync_status" /></td>
                                            <td class="px-4 py-3">
                                                <!-- The whole point of this column: an assignment past its date used
                                                     to look exactly like a live one here, while the registry served
                                                     nothing and the customer's build got a 404. -->
                                                <span
                                                    :class="
                                                        cn(
                                                            'inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium',
                                                            pkg.in_force
                                                                ? 'border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border'
                                                                : 'border-destructive/30 bg-destructive/10 text-destructive',
                                                        )
                                                    "
                                                >
                                                    {{ availabilityLabel(availabilityOf(pkg)) }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <!-- Hidden rather than disabled for an assignment this operator may not
                                                     manage: both actions answer 403, and the registry's own admin is not
                                                     the one who decides whether their customer keeps a shared package.
                                                     The label names the OWNING organization, not the operator: the guard
                                                     asks about the package's `organization_id`, and only the sharing gate
                                                     asks about `is_operator`. Identical today, but a label promising
                                                     something the rule does not check is how copy starts drifting. -->
                                                <div v-if="pkg.manageable" class="flex items-center gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        :aria-label="`Verfügbarkeit von ${pkg.name} bearbeiten`"
                                                        @click="editingAssignment === pkg.id ? cancelAvailability() : editAvailability(pkg)"
                                                    >
                                                        <CalendarClock class="size-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label="Paket aus Registry entfernen"
                                                        @click="removePackage(pkg.id)"
                                                    >
                                                        <Trash2 class="size-4 text-destructive" />
                                                    </Button>
                                                </div>
                                                <span v-else class="text-xs text-muted-foreground">Von der Eigentümer-Organisation verwaltet</span>
                                            </td>
                                        </tr>
                                        <!-- What this assignment currently means for the customer. Shown without
                                             opening the editor, because the operator reading a failing build needs
                                             the diagnosis, not a form. -->
                                        <tr
                                            v-if="noteFor(pkg) && editingAssignment !== pkg.id"
                                            class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                        >
                                            <td
                                                colspan="5"
                                                class="px-4 pb-3 text-xs"
                                                :class="pkg.in_force ? 'text-muted-foreground' : 'text-destructive'"
                                            >
                                                {{ noteFor(pkg) }}
                                            </td>
                                        </tr>
                                        <tr
                                            v-if="editingAssignment === pkg.id"
                                            class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                        >
                                            <td colspan="5" class="px-4 pb-4">
                                                <div class="flex flex-col gap-2">
                                                    <Label :for="`available-until-${pkg.id}`">Verfügbar bis (leer = unbefristet)</Label>
                                                    <!-- No `min`: a date already past is legitimate, and is the only
                                                         way to say "stop delivering now, keep the name blocked". -->
                                                    <Input :id="`available-until-${pkg.id}`" v-model="editedUntil" type="date" class="max-w-xs" />
                                                    <p class="max-w-2xl text-xs text-muted-foreground">
                                                        {{ expiryConsequence(holdingFor(pkg)) }}
                                                    </p>
                                                    <p v-if="withdrawsImmediately" class="max-w-2xl text-xs text-copper-hi">
                                                        {{ immediateWithdrawalNote(holdingFor(pkg)) }}
                                                    </p>
                                                    <InputError :message="assignmentErrors.available_until" />
                                                    <InputError :message="assignmentErrors.collision" />
                                                    <div class="flex gap-2">
                                                        <Button size="sm" :disabled="savingAssignment" @click="saveAvailability(pkg.id)">
                                                            Speichern
                                                        </Button>
                                                        <Button variant="outline" size="sm" @click="cancelAvailability">Abbrechen</Button>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                    <tr v-if="props.packages.length === 0">
                                        <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">Noch keine Pakete in dieser Registry.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </TabsContent>

                <TabsContent value="domains">
                    <section class="flex flex-col gap-3">
                        <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <ul v-if="props.domains.length > 0" class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                                <li v-for="domain in props.domains" :key="domain.id" class="flex items-center justify-between gap-4 px-4 py-3">
                                    <span class="font-mono text-sm">{{ domain.hostname }}</span>
                                    <Button variant="ghost" size="icon" aria-label="Domain entfernen" @click="removeDomain(domain.id)">
                                        <Trash2 class="size-4 text-destructive" />
                                    </Button>
                                </li>
                            </ul>
                            <p v-else class="px-4 py-8 text-center text-sm text-muted-foreground">Keine Domains hinterlegt.</p>
                        </div>
                        <form v-if="canAttachDomain" class="flex flex-wrap items-end gap-3" @submit.prevent="addDomain">
                            <div class="flex flex-col gap-1.5">
                                <label for="new-domain" class="text-sm font-medium">Hostname</label>
                                <input
                                    id="new-domain"
                                    v-model="newDomain"
                                    type="text"
                                    placeholder="packages.example.test"
                                    class="w-full max-w-md rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:border-ring focus:ring-1 focus:ring-ring focus:outline-hidden"
                                />
                            </div>
                            <Button type="submit">Hinzufügen</Button>
                        </form>
                        <p v-else class="text-sm text-muted-foreground">
                            Neue Hostnamen werden vom Betreiber der Instanz eingetragen — ein Hostname gilt instanzweit.
                        </p>
                    </section>
                </TabsContent>

                <TabsContent value="upstreams">
                    <section class="flex flex-col gap-3">
                        <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                                    <tr>
                                        <th class="px-4 py-3 font-medium">Typ</th>
                                        <th class="px-4 py-3 font-medium">URL</th>
                                        <th class="px-4 py-3 font-medium">Policy</th>
                                        <th class="px-4 py-3 font-medium">Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="upstream in props.upstreams"
                                        :key="upstream.id"
                                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                    >
                                        <td class="px-4 py-3">{{ upstream.type }}</td>
                                        <td class="px-4 py-3 font-mono text-muted-foreground">{{ upstream.url }}</td>
                                        <td class="px-4 py-3">{{ upstream.policy }}</td>
                                        <td class="px-4 py-3">
                                            <Button variant="ghost" size="icon" aria-label="Upstream entfernen" @click="removeUpstream(upstream.id)">
                                                <Trash2 class="size-4 text-destructive" />
                                            </Button>
                                        </td>
                                    </tr>
                                    <tr v-if="props.upstreams.length === 0">
                                        <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">Keine Upstreams konfiguriert.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <form class="flex flex-wrap items-end gap-3" @submit.prevent="addUpstream">
                            <div class="flex flex-col gap-1.5">
                                <label for="new-upstream-type" class="text-sm font-medium">Typ</label>
                                <SearchableSelect
                                    id="new-upstream-type"
                                    v-model="newUpstream.type"
                                    class="min-w-40"
                                    :options="[
                                        { value: 'composer', label: 'composer' },
                                        { value: 'npm', label: 'npm' },
                                        { value: 'python', label: 'python' },
                                    ]"
                                />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label for="new-upstream-url" class="text-sm font-medium">URL</label>
                                <input
                                    id="new-upstream-url"
                                    v-model="newUpstream.url"
                                    type="text"
                                    placeholder="https://repo.packagist.org"
                                    class="w-full min-w-64 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:border-ring focus:ring-1 focus:ring-ring focus:outline-hidden"
                                />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label for="new-upstream-policy" class="text-sm font-medium">Policy</label>
                                <SearchableSelect
                                    id="new-upstream-policy"
                                    v-model="newUpstream.policy"
                                    class="min-w-40"
                                    :options="[
                                        { value: 'proxy', label: 'proxy' },
                                        { value: 'strict', label: 'strict' },
                                    ]"
                                />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label for="new-upstream-priority" class="text-sm font-medium">Priorität</label>
                                <input
                                    id="new-upstream-priority"
                                    v-model.number="newUpstream.priority"
                                    type="number"
                                    min="0"
                                    class="w-24 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:border-ring focus:ring-1 focus:ring-ring focus:outline-hidden"
                                />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label for="new-upstream-token" class="text-sm font-medium">Auth-Token (optional)</label>
                                <input
                                    id="new-upstream-token"
                                    v-model="newUpstream.auth_token"
                                    type="password"
                                    autocomplete="off"
                                    placeholder="für private Upstreams"
                                    class="w-full min-w-56 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:border-ring focus:ring-1 focus:ring-ring focus:outline-hidden"
                                />
                            </div>
                            <Button type="submit">Hinzufügen</Button>
                        </form>
                    </section>
                </TabsContent>

                <TabsContent value="tokens">
                    <section class="flex flex-col gap-4">
                        <div v-if="showTokenCallout" class="rounded-xl border border-copper/30 bg-copper/10 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1 space-y-2">
                                    <p class="font-medium text-copper-hi">Neuer Token erstellt</p>
                                    <p class="rounded-md border border-copper/20 bg-background/60 px-3 py-2 font-mono text-sm break-all select-all">
                                        {{ plainTextToken }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">Dieser Token wird nur einmal angezeigt. Bewahren Sie ihn sicher auf.</p>
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
                            class="grid gap-4 rounded-xl border border-sidebar-border/70 p-4 sm:grid-cols-[1fr_auto_auto] sm:items-end dark:border-sidebar-border"
                            @submit.prevent="submitToken"
                        >
                            <div class="grid gap-2">
                                <Label for="token_name">Name</Label>
                                <Input id="token_name" v-model="tokenForm.name" placeholder="ci-token" autocomplete="off" />
                                <InputError :message="tokenForm.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="token_ability">Recht</Label>
                                <SearchableSelect
                                    id="token_ability"
                                    v-model="tokenForm.ability"
                                    :options="[
                                        { value: 'read', label: 'Lesen' },
                                        { value: 'publish', label: 'Veröffentlichen' },
                                    ]"
                                />
                                <InputError :message="tokenForm.errors.ability" />
                            </div>

                            <Button type="submit" :disabled="tokenForm.processing">
                                <Plus class="size-4" />
                                Token erstellen
                            </Button>
                        </form>

                        <div class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-sidebar-border/70 bg-muted/50 dark:border-sidebar-border">
                                    <tr>
                                        <th class="px-4 py-3 font-medium">Name</th>
                                        <th class="px-4 py-3 font-medium">Recht</th>
                                        <th class="px-4 py-3 font-medium">Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="token in props.tokens"
                                        :key="token.id"
                                        class="border-b border-sidebar-border/70 last:border-0 dark:border-sidebar-border"
                                    >
                                        <td class="px-4 py-3 font-mono">{{ token.name }}</td>
                                        <td class="px-4 py-3">{{ token.ability }}</td>
                                        <td class="px-4 py-3">
                                            <Button variant="ghost" size="icon" aria-label="Token widerrufen" @click="destroyToken(token.id)">
                                                <Trash2 class="size-4 text-destructive" />
                                            </Button>
                                        </td>
                                    </tr>
                                    <tr v-if="props.tokens.length === 0">
                                        <td colspan="3" class="px-4 py-8 text-center text-muted-foreground">Noch keine Tokens erstellt.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </TabsContent>

                <TabsContent value="einrichtung">
                    <RegistrySetup
                        :snippets="props.setup"
                        :types="props.types"
                        audience="operator"
                        store-route="admin.tokens.store"
                        :store-payload="{ organization_id: props.group.organization_id, group_id: props.group.id }"
                        :personal-tokens="props.tokens"
                    />
                </TabsContent>

                <TabsContent value="aktivitaet">
                    <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <ActivityTimeline :activities="props.activities" compact />
                    </div>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
