# Kontorfix v3.1 – Token-UX (persönliche Tokens, Tabs, „Erstellen & einsetzen") Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Token-Handhabung übersichtlicher machen: (1) persönliche Zugriffstokens in den Kontoeinstellungen inkl. „globalem" Token, (2) Token-Erstellung direkt auf der Registry-Detailseite, (3) Detailseiten in Tabs gliedern, (4) in den Einrichtungs-Snippets ein „Token erstellen & einsetzen"-Widget, das einen frisch erzeugten Token direkt copy-paste-fertig ins Snippet einfügt.

**Architecture:** `registry_tokens` bekommt eine nullable `user_id` (Besitzer). `RegistryToken::issue()` erhält einen optionalen `?User $owner`. Eine neue Settings-Seite (`settings/tokens`) verwaltet die *eigenen* Tokens des angemeldeten Users (org-scoped, optional auf eine Registry der eigenen Org beschränkt; „global" = ohne Registry). Ein wiederverwendbares Vue-Tabs-Set (auf `radix-vue`) gliedert die Detailseiten. Ein wiederverwendbares `RegistrySetup.vue` rendert die drei Snippets mit `<dein-token>`-Platzhalter und bietet „Token erstellen & einsetzen": der frisch erzeugte Klartext-Token (über den bestehenden `flash.plainTextToken`-Kanal) wird clientseitig in die Snippets substituiert. **Sicherheits-Invariante:** Klartext existiert nur einmalig bei Erstellung — vorhandene Tokens können ihren Wert nie erneut anzeigen; das Widget kann daher nur frisch erstellte Werte einsetzen.

**Tech Stack:** Laravel 12, Inertia v2 + Vue 3 + TS, Tailwind 3, shadcn-vue auf radix-vue, Ziggy, Pest, Pint, Larastan L6, ESLint. DDEV (`ddev exec …`).

**Standing constraints:** Antworten/Copy auf Deutsch; keine Tech-Stack-Begriffe in außenwirksamer Copy (Composer/npm sind Produkt-Domäne, erlaubt). Conventional Commits mit Footer `Co-Authored-By: Claude <noreply@anthropic.com>`. Jeder Subagent verifiziert vor dem Commit `git symbolic-ref --short HEAD` == `main` und committet **lokal** (kein Push). Neue Logik kommt mit Pest-Tests; nach jeder Task Pint + PHPStan + (bei JS) `npm run build`/ESLint grün.

---

## File Structure

**Backend**
- Create `database/migrations/2026_07_09_000000_add_user_id_to_registry_tokens_table.php` — nullable `user_id` FK.
- Modify `app/Models/RegistryToken.php` — `user_id` in `$fillable`, `user()`-Relation, `issue()` um `?User $owner` erweitert.
- Modify `app/Http/Controllers/Portal/TokenController.php` — Besitzer beim Portal-Store setzen.
- Create `app/Http/Controllers/Settings/AccessTokenController.php` — index/store/destroy der eigenen Tokens.
- Create `app/Http/Requests/Settings/StoreAccessTokenRequest.php`.
- Modify `routes/settings.php` — `settings/tokens`-Routen.
- Modify `app/Http/Controllers/Admin/GroupController.php` — `show()` liefert `organization_id` der Gruppe (für den Token-Store auf der Detailseite).

**Frontend**
- Create `resources/js/components/ui/tabs/{Tabs,TabsList,TabsTrigger,TabsContent}.vue` + `index.ts` — Tabs auf radix-vue.
- Create `resources/js/components/kontorfix/RegistrySetup.vue` — Snippets + „Token erstellen & einsetzen".
- Create `resources/js/pages/settings/AccessTokens.vue` — persönliche Tokens.
- Modify `resources/js/layouts/settings/Layout.vue` — Nav-Eintrag „Zugriffstokens".
- Modify `resources/js/pages/admin/groups/Show.vue` — Tabs + Token-Erstellung/-Löschung + RegistrySetup.
- Modify `resources/js/pages/admin/packages/Show.vue` — Tabs.
- Modify `resources/js/pages/portal/Registry.vue` — Tabs + RegistrySetup.

**Tests**
- `tests/Unit/RegistryTokenIssueTest.php`
- `tests/Feature/Settings/AccessTokenTest.php`
- `tests/Feature/Admin/GroupShowPayloadTest.php` (organization_id im Payload)
- Ergänzung E2E: `tests/Feature/PersonalTokenFlowTest.php`

---

### Task 1: `user_id` auf `registry_tokens` + `issue($owner)` + Relation

**Files:** Create migration; Modify `app/Models/RegistryToken.php`; Test `tests/Unit/RegistryTokenIssueTest.php`.

- [ ] **Step 1: Failing test** `tests/Unit/RegistryTokenIssueTest.php`
```php
<?php

use App\Enums\TokenAbility;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('issues an org token without an owner by default', function () {
    $org = Organization::factory()->create();

    [$token, $plain] = RegistryToken::issue($org, 'CI', null);

    expect($token->user_id)->toBeNull();
    expect($token->organization_id)->toBe($org->id);
    expect($plain)->toStartWith('kfx_');
});

it('issues a token owned by a user when an owner is passed', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    [$token] = RegistryToken::issue($org, 'Persönlich', null, TokenAbility::Read, null, $user);

    expect($token->user_id)->toBe($user->id);
    expect($token->user->is($user))->toBeTrue();
});
```

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=RegistryTokenIssueTest` (Spalte/Property `user_id` fehlt).

- [ ] **Step 3: Migration** `database/migrations/2026_07_09_000000_add_user_id_to_registry_tokens_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registry_tokens', function (Blueprint $table) {
            $table->foreignUuid('user_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registry_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
```

- [ ] **Step 4: Modell** `app/Models/RegistryToken.php`:
  - `'user_id'` in `$fillable` direkt nach `'organization_id'` ergänzen.
  - Import `use App\Models\User;` ist nicht nötig (gleicher Namespace). Neue Relation nach `organization()` einfügen:
```php
/**
 * @return BelongsTo<User, $this>
 */
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}
```
  - `issue()`-Signatur + Insert erweitern:
```php
public static function issue(Organization $org, string $name, ?Group $group, TokenAbility $ability = TokenAbility::Read, ?\DateTimeInterface $expiresAt = null, ?User $owner = null): array
{
    $plain = 'kfx_'.Str::random(40);
    $token = static::create([
        'organization_id' => $org->id,
        'user_id' => $owner?->id,
        'group_id' => $group?->id,
        'name' => $name,
        'token_hash' => hash('sha256', $plain),
        'ability' => $ability,
        'expires_at' => $expiresAt,
    ]);

    return [$token, $plain];
}
```

- [ ] **Step 5: Run → PASS** `ddev exec vendor/bin/pest --filter=RegistryTokenIssueTest`.
- [ ] **Step 6:** `ddev exec vendor/bin/pint` + `ddev exec vendor/bin/phpstan analyse`.
- [ ] **Step 7: Commit** `feat: add optional owner (user_id) to registry tokens`.

---

### Task 2: Persönliche Zugriffstokens in den Settings (Backend)

**Files:** Create `app/Http/Controllers/Settings/AccessTokenController.php`, `app/Http/Requests/Settings/StoreAccessTokenRequest.php`; Modify `routes/settings.php`, `app/Http/Controllers/Portal/TokenController.php`; Test `tests/Feature/Settings/AccessTokenTest.php`.

- [ ] **Step 1: Failing test** `tests/Feature/Settings/AccessTokenTest.php`
```php
<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists only the current users own personal tokens', function () {
    $org = Organization::factory()->create();
    $me = User::factory()->create(['organization_id' => $org->id]);
    $other = User::factory()->create(['organization_id' => $org->id]);

    RegistryToken::issue($org, 'meins', null, owner: $me);
    RegistryToken::issue($org, 'fremd', null, owner: $other);

    $this->actingAs($me)->get('/settings/tokens')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/AccessTokens')
            ->has('tokens', 1)
            ->where('tokens.0.name', 'meins'));
});

it('creates a personal global token owned by the user', function () {
    $org = Organization::factory()->create();
    $me = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($me)->post('/settings/tokens', ['name' => 'global', 'ability' => 'read'])
        ->assertRedirect()
        ->assertSessionHas('plainTextToken');

    $token = RegistryToken::firstWhere('name', 'global');
    expect($token->user_id)->toBe($me->id);
    expect($token->group_id)->toBeNull();
    expect($token->organization_id)->toBe($org->id);
});

it('rejects a registry belonging to another organization', function () {
    $me = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $foreignGroup = Group::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->actingAs($me)->post('/settings/tokens', ['name' => 'x', 'group_id' => $foreignGroup->id])
        ->assertSessionHasErrors('group_id');
});

it('forbids deleting a token owned by someone else', function () {
    $org = Organization::factory()->create();
    $me = User::factory()->create(['organization_id' => $org->id]);
    $other = User::factory()->create(['organization_id' => $org->id]);
    [$token] = RegistryToken::issue($org, 'fremd', null, owner: $other);

    $this->actingAs($me)->delete("/settings/tokens/{$token->id}")->assertForbidden();
    expect(RegistryToken::find($token->id))->not->toBeNull();
});

it('deletes an own token', function () {
    $org = Organization::factory()->create();
    $me = User::factory()->create(['organization_id' => $org->id]);
    [$token] = RegistryToken::issue($org, 'meins', null, owner: $me);

    $this->actingAs($me)->delete("/settings/tokens/{$token->id}")->assertRedirect();
    expect(RegistryToken::find($token->id))->toBeNull();
});
```
Falls die `User`-Factory `organization_id` nicht automatisch setzt, ist das explizite Setzen oben ausreichend. `RegistryToken::issue(..., owner: $x)` nutzt den benannten Parameter aus Task 1.

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=AccessTokenTest`.

- [ ] **Step 3: FormRequest** `app/Http/Requests/Settings/StoreAccessTokenRequest.php`
```php
<?php

namespace App\Http\Requests\Settings;

use App\Enums\TokenAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Persönliche Tokens erfordern eine Org-Zugehörigkeit (issue() braucht eine Organization).
        return $this->user()?->organization_id !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'group_id' => [
                'nullable',
                'uuid',
                // Nur Registries der eigenen Org zulassen.
                Rule::exists('groups', 'id')->where('organization_id', $this->user()->organization_id),
            ],
            'ability' => ['nullable', Rule::enum(TokenAbility::class)],
        ];
    }
}
```

- [ ] **Step 4: Controller** `app/Http/Controllers/Settings/AccessTokenController.php`
```php
<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreAccessTokenRequest;
use App\Models\Group;
use App\Models\RegistryToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccessTokenController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/AccessTokens', [
            'tokens' => RegistryToken::query()
                ->where('user_id', $user->id)
                ->with('group:id,name')
                ->latest()->get()
                ->map(fn (RegistryToken $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'ability' => $t->ability->value,
                    'group' => $t->group?->name,
                    'last_used_at' => $t->last_used_at?->diffForHumans(),
                    'expires_at' => $t->expires_at?->toDateString(),
                ]),
            'groups' => $user->organization_id
                ? Group::where('organization_id', $user->organization_id)->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    public function store(StoreAccessTokenRequest $request): RedirectResponse
    {
        $user = $request->user();

        $group = $request->validated('group_id')
            ? Group::where('organization_id', $user->organization_id)->findOrFail($request->validated('group_id'))
            : null;

        [$token, $plain] = RegistryToken::issue(
            $user->organization,
            $request->validated('name'),
            $group,
            $request->enum('ability', TokenAbility::class) ?? TokenAbility::Read,
            null,
            $user,
        );

        return back()->with('plainTextToken', $plain)->with('success', "Token {$token->name} erstellt.");
    }

    public function destroy(Request $request, RegistryToken $token): RedirectResponse
    {
        // Nur eigene persönliche Tokens dürfen aus den Settings gelöscht werden.
        abort_unless($token->user_id === $request->user()->id, 403);
        $token->delete();

        return back()->with('success', 'Token widerrufen.');
    }
}
```

- [ ] **Step 5: Routen** in `routes/settings.php` — Import ergänzen und innerhalb der `auth`-Gruppe hinzufügen:
```php
use App\Http\Controllers\Settings\AccessTokenController;
// …
    Route::get('settings/tokens', [AccessTokenController::class, 'index'])->name('tokens.index');
    Route::post('settings/tokens', [AccessTokenController::class, 'store'])->name('tokens.store');
    Route::delete('settings/tokens/{token}', [AccessTokenController::class, 'destroy'])->name('tokens.destroy');
```
(Namen `tokens.*` sind unpräfixiert frei — Admin nutzt `admin.tokens.*`, Portal `portal.tokens.*`.)

- [ ] **Step 6: Portal-Store Besitzer setzen** in `app/Http/Controllers/Portal/TokenController.php` — den `issue()`-Aufruf um den Owner ergänzen (Portal-Tokens sind persönliche Tokens des Kunden):
```php
[$token, $plain] = RegistryToken::issue(
    $request->user()->organization,
    $request->validated('name'),
    $group,
    $request->enum('ability', TokenAbility::class) ?? TokenAbility::Read,
    null,
    $request->user(),
);
```

- [ ] **Step 7: Run → PASS** `ddev exec vendor/bin/pest --filter=AccessTokenTest`.
- [ ] **Step 8:** Pint + PHPStan grün.
- [ ] **Step 9: Commit** `feat: personal access token management in account settings`.

---

### Task 3: Tabs-UI-Komponente (radix-vue)

**Files:** Create `resources/js/components/ui/tabs/Tabs.vue`, `TabsList.vue`, `TabsTrigger.vue`, `TabsContent.vue`, `index.ts`.

- [ ] **Step 1: `Tabs.vue`**
```vue
<script setup lang="ts">
import { cn } from '@/lib/utils';
import { TabsRoot, type TabsRootEmits, type TabsRootProps, useForwardPropsEmits } from 'radix-vue';
import { computed, type HTMLAttributes } from 'vue';

const props = defineProps<TabsRootProps & { class?: HTMLAttributes['class'] }>();
const emits = defineEmits<TabsRootEmits>();

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;
    return delegated;
});

const forwarded = useForwardPropsEmits(delegatedProps, emits);
</script>

<template>
    <TabsRoot v-bind="forwarded" :class="cn('flex flex-col gap-4', props.class)">
        <slot />
    </TabsRoot>
</template>
```

- [ ] **Step 2: `TabsList.vue`**
```vue
<script setup lang="ts">
import { cn } from '@/lib/utils';
import { TabsList, type TabsListProps } from 'radix-vue';
import { computed, type HTMLAttributes } from 'vue';

const props = defineProps<TabsListProps & { class?: HTMLAttributes['class'] }>();

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;
    return delegated;
});
</script>

<template>
    <TabsList
        v-bind="delegatedProps"
        :class="cn('inline-flex h-10 w-full items-center justify-start gap-1 overflow-x-auto rounded-lg bg-muted p-1 text-muted-foreground', props.class)"
    >
        <slot />
    </TabsList>
</template>
```

- [ ] **Step 3: `TabsTrigger.vue`**
```vue
<script setup lang="ts">
import { cn } from '@/lib/utils';
import { TabsTrigger, type TabsTriggerProps, useForwardProps } from 'radix-vue';
import { computed, type HTMLAttributes } from 'vue';

const props = defineProps<TabsTriggerProps & { class?: HTMLAttributes['class'] }>();

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;
    return delegated;
});

const forwarded = useForwardProps(delegatedProps);
</script>

<template>
    <TabsTrigger
        v-bind="forwarded"
        :class="cn(
            'inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium ring-offset-background transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow',
            props.class,
        )"
    >
        <slot />
    </TabsTrigger>
</template>
```

- [ ] **Step 4: `TabsContent.vue`**
```vue
<script setup lang="ts">
import { cn } from '@/lib/utils';
import { TabsContent, type TabsContentProps } from 'radix-vue';
import { computed, type HTMLAttributes } from 'vue';

const props = defineProps<TabsContentProps & { class?: HTMLAttributes['class'] }>();

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;
    return delegated;
});
</script>

<template>
    <TabsContent
        v-bind="delegatedProps"
        :class="cn('ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2', props.class)"
    >
        <slot />
    </TabsContent>
</template>
```

- [ ] **Step 5: `index.ts`**
```ts
export { default as Tabs } from './Tabs.vue';
export { default as TabsContent } from './TabsContent.vue';
export { default as TabsList } from './TabsList.vue';
export { default as TabsTrigger } from './TabsTrigger.vue';
```

- [ ] **Step 6:** `ddev exec npm run build` grün (Komponenten kompilieren; noch ungenutzt). Falls `useForwardProps`/`useForwardPropsEmits` in dieser radix-vue-Version anders heißen, an vorhandenen ui-Komponenten (z. B. `resources/js/components/ui/dropdown-menu/*`) den korrekten Import-Namen abgleichen.
- [ ] **Step 7: Commit** `feat: add shadcn-vue tabs component`.

---

### Task 4: `RegistrySetup.vue` — Snippets mit „Token erstellen & einsetzen"

**Files:** Create `resources/js/components/kontorfix/RegistrySetup.vue`.

Wiederverwendbares Widget für Registry-/Portal-Einrichtung. Ersetzt die bisher duplizierten `steps`-Blöcke. Substituiert den Platzhalter `<dein-token>` in den Snippets durch einen frisch erstellten Token.

- [ ] **Step 1: Komponente** `resources/js/components/kontorfix/RegistrySetup.vue`
```vue
<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type SharedData } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Copy, Plus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Snippets {
    composer: string;
    auth: string;
    npm: string;
}

interface PersonalToken {
    id: string;
    name: string;
    ability: string;
}

const props = defineProps<{
    snippets: Snippets;
    // Route-Name + Extra-Payload für die Token-Erstellung (kontextabhängig:
    // Settings, Portal oder Admin-Gruppe).
    storeRoute: string;
    storePayload?: Record<string, unknown>;
    // Vorhandene Tokens (nur Referenz — Wert kann nicht erneut angezeigt werden).
    personalTokens?: PersonalToken[];
}>();

const PLACEHOLDER = '<dein-token>';

// In dieser Browser-Session frisch erzeugte Tokens (Klartext verfügbar).
const sessionTokens = ref<{ name: string; value: string }[]>([]);
// Aktuell in die Snippets eingesetzter Wert ('' = Platzhalter beibehalten).
const activeToken = ref('');

const page = usePage<SharedData>();
const plainTextToken = computed(() => page.props.flash?.plainTextToken ?? null);

const showCreate = ref((props.personalTokens?.length ?? 0) === 0);

const form = useForm({
    name: '',
    ability: 'read' as 'read' | 'publish',
    ...(props.storePayload ?? {}),
});

// Erwartet ein Create über dieses Widget: Flash-Token einfangen und einsetzen.
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
    form.transform((data) => ({ ...data, ...(props.storePayload ?? {}) })).post(route(props.storeRoute), {
        preserveScroll: true,
        onSuccess: () => form.reset('name'),
        onError: () => {
            awaitingToken.value = false;
        },
    });
}

const substituted = computed<Snippets>(() => {
    const t = activeToken.value;
    if (!t) {
        return props.snippets;
    }
    const sub = (s: string) => s.split(PLACEHOLDER).join(t);
    return { composer: sub(props.snippets.composer), auth: sub(props.snippets.auth), npm: sub(props.snippets.npm) };
});

const steps = computed(() => [
    { key: 'composer', title: 'Composer einrichten', content: substituted.value.composer },
    { key: 'auth', title: 'Zugang einrichten', content: substituted.value.auth },
    { key: 'npm', title: 'npm einrichten', content: substituted.value.npm },
]);

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

function revoke(id: string) {
    router.delete(route(props.storeRoute.replace(/\.store$/, '.destroy'), id), { preserveScroll: true });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Token-Auswahl / -Erstellung -->
        <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <div class="flex flex-col gap-2">
                <Label>Token für die Snippets</Label>
                <div class="flex flex-wrap items-center gap-2">
                    <select
                        class="flex h-10 min-w-56 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
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
                    <Button variant="outline" size="sm" type="button" @click="showCreate = !showCreate">
                        <Plus class="size-4" />
                        Token erstellen
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    Aus Sicherheitsgründen wird ein Token nur einmal im Klartext angezeigt. Vorhandene Tokens lassen sich
                    daher nicht erneut einsetzen — erstelle ein neues, um es direkt in die Snippets zu übernehmen.
                </p>
            </div>

            <form v-if="showCreate" class="mt-4 grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end" @submit.prevent="createAndInsert">
                <div class="grid gap-1.5">
                    <Label for="setup_token_name">Name</Label>
                    <Input id="setup_token_name" v-model="form.name" placeholder="ci-token" autocomplete="off" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="setup_token_ability">Recht</Label>
                    <select
                        id="setup_token_ability"
                        v-model="form.ability"
                        class="flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    >
                        <option value="read">Lesen</option>
                        <option value="publish">Veröffentlichen</option>
                    </select>
                </div>
                <Button type="submit" :disabled="form.processing || !form.name">Erstellen &amp; einsetzen</Button>
            </form>
        </div>

        <!-- Snippets -->
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
```
Hinweis: `revoke()` bleibt vorerst ungenutzt in diesem Widget (Löschen erfolgt in den jeweiligen Token-Listen). Falls ESLint „unused" bemängelt, `revoke` entfernen.

- [ ] **Step 2:** `ddev exec npm run build` + `ddev exec npm run lint` grün. Bei „unused var" `revoke`/`personalTokens` bereinigen (nur behalten, was verwendet wird).
- [ ] **Step 3: Commit** `feat: reusable registry setup widget with create-and-insert token`.

---

### Task 5: Settings-Seite „Zugriffstokens" + Nav

**Files:** Create `resources/js/pages/settings/AccessTokens.vue`; Modify `resources/js/layouts/settings/Layout.vue`.

- [ ] **Step 1: Vorlage prüfen** — `resources/js/pages/settings/Password.vue` öffnen und dessen Wrapper (`AppLayout` + `SettingsLayout` + `HeadingSmall`) 1:1 als Gerüst übernehmen. Die Seite muss existieren (Inertia `ensure_pages_exist`).

- [ ] **Step 2: Seite** `resources/js/pages/settings/AccessTokens.vue` — Gerüst wie Password.vue, Inhalt:
  - Kopf: `<HeadingSmall title="Zugriffstokens" description="Persönliche Tokens für Composer/npm — global oder auf eine Registry beschränkt." />`
  - Klartext-Callout (nach Erstellung) analog `portal/Registry.vue` (Zeilen 253–270): `plainTextToken` via `usePage().props.flash?.plainTextToken`, „nur einmal sichtbar", Kopieren-Button.
  - Erstellformular (`useForm({ name: '', group_id: '', ability: 'read' })` → `post(route('tokens.store'))`, `preserveScroll`, `onSuccess: () => form.reset('name')`):
    - Feld Name (`Input`) + `InputError`.
    - Feld „Geltungsbereich" (`select` v-model=`form.group_id`): erste Option `value=""` → „Global (alle Registries)"; danach `option` je `props.groups` (`:value="g.id"` `{{ g.name }}`). `group_id: ''` muss serverseitig als „kein Group" ankommen — beim Submit leere Strings zu `null` transformieren: `form.transform((d) => ({ ...d, group_id: d.group_id || null }))`.
    - Feld „Recht" (`select` read/publish).
    - Submit-Button „Token erstellen".
  - Tabelle der eigenen Tokens (`props.tokens`): Spalten Name, Geltungsbereich (`token.group ?? 'Global'`), Recht (`read`→„Lesen", `publish`→„Veröffentlichen"), Zuletzt genutzt (`token.last_used_at ?? 'nie'`), Aktion (Widerrufen → `router.delete(route('tokens.destroy', token.id), { preserveScroll: true, onBefore: () => confirm('Token wirklich widerrufen?') })`).
  - Props typisieren:
```ts
const props = defineProps<{
    tokens: { id: string; name: string; ability: 'read' | 'publish'; group: string | null; last_used_at: string | null; expires_at: string | null }[];
    groups: { id: string; name: string }[];
}>();
```
  - Markup/Styling der Tabelle und des Formulars an `portal/Registry.vue` (Token-Bereich, Zeilen 250–332) anlehnen, damit es konsistent aussieht.

- [ ] **Step 3: Nav** in `resources/js/layouts/settings/Layout.vue` — Eintrag ergänzen (nach „Passkeys"):
```ts
    {
        title: 'Zugriffstokens',
        href: '/settings/tokens',
    },
```

- [ ] **Step 4:** `ddev exec npm run build` + Lint grün.
- [ ] **Step 5: Feature-Test-Ergänzung** in `tests/Feature/Settings/AccessTokenTest.php` — die Inertia-Komponente wird schon in Task 2 geprüft (`->component('settings/AccessTokens')`). Sicherstellen, dass dieser Test jetzt (mit existierender Vue-Datei) weiterhin grün ist: `ddev exec vendor/bin/pest --filter=AccessTokenTest`.
- [ ] **Step 6: Commit** `feat: account settings page for personal access tokens`.

---

### Task 6: Registry-Detailseite → Tabs + Token-Erstellung/-Löschung + RegistrySetup

**Files:** Modify `resources/js/pages/admin/groups/Show.vue`, `app/Http/Controllers/Admin/GroupController.php`; Test `tests/Feature/Admin/GroupShowPayloadTest.php`.

- [ ] **Step 1: Failing test** `tests/Feature/Admin/GroupShowPayloadTest.php`
```php
<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes the group organization id for inline token creation', function () {
    $operatorOrg = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $operatorOrg->id, 'role' => 'admin']);
    $group = Group::factory()->create(['organization_id' => $operatorOrg->id]);

    $this->actingAs($admin)->get(route('admin.groups.show', $group->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('group.organization_id', $operatorOrg->id));
});
```
Falls die `User`-Factory eine andere Rollen-/Operator-Verdrahtung braucht, an vorhandenen Admin-Tests (z. B. `tests/Feature/Admin/…`) orientieren.

- [ ] **Step 2: Run → FAIL** `ddev exec vendor/bin/pest --filter=GroupShowPayloadTest`.

- [ ] **Step 3: Controller** `app/Http/Controllers/Admin/GroupController.php` — im `show()`-`group`-Array `'organization_id' => $group->organization_id,` ergänzen (nach `'organization'`). Der `tokens`-Map zusätzlich `'last_used_at'` beifügen ist optional; Pflicht ist nur `organization_id`.

- [ ] **Step 4: Run → PASS** `ddev exec vendor/bin/pest --filter=GroupShowPayloadTest`; Pint + PHPStan.

- [ ] **Step 5: `Show.vue` — Tabs + Token-Erstellung.** In `resources/js/pages/admin/groups/Show.vue`:
  - Imports ergänzen: `import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';`, `import RegistrySetup from '@/components/kontorfix/RegistrySetup.vue';`, `import { usePage } from '@inertiajs/vue3';` (zusätzlich zu vorhandenem), `import { Plus } from 'lucide-vue-next';`, sowie `Input`, `Label`, `InputError` (`@/components/ui/input`, `@/components/ui/label`, `@/components/InputError.vue`).
  - `GroupInfo`-Interface um `organization_id: string | null;` erweitern.
  - `TokenRow`-Interface um `last_used_at?: string | null;` erweitern (optional).
  - Token-Erstell-Form + Flash-Callout ergänzen (analog `portal/Registry.vue`):
```ts
const pageProps = usePage();
const plainTextToken = computed(() => (pageProps.props.flash as { plainTextToken?: string } | undefined)?.plainTextToken ?? null);
const tokenCalloutDismissed = ref(false);
watch(plainTextToken, (v) => { if (v) tokenCalloutDismissed.value = false; });
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
```
    (`computed`, `watch` aus `vue` importieren; `useForm` ist bereits importiert.)
  - **Template:** Kopfbereich (Zeilen 144–163) unverändert lassen. Die sechs `<section>`-Blöcke (Bearbeiten, Pakete, Domains, Upstreams, Tokens, Einrichtung) in eine Tab-Struktur überführen:
```html
<Tabs default-value="bearbeiten">
    <TabsList>
        <TabsTrigger value="bearbeiten">Bearbeiten</TabsTrigger>
        <TabsTrigger value="pakete">Pakete</TabsTrigger>
        <TabsTrigger value="domains">Domains</TabsTrigger>
        <TabsTrigger value="upstreams">Upstreams</TabsTrigger>
        <TabsTrigger value="tokens">Tokens</TabsTrigger>
        <TabsTrigger value="einrichtung">Einrichtung</TabsTrigger>
    </TabsList>

    <TabsContent value="bearbeiten"><!-- bisherige „Bearbeiten"-section (ohne <h2>) --></TabsContent>
    <TabsContent value="pakete"><!-- bisherige „Pakete"-section --></TabsContent>
    <TabsContent value="domains"><!-- bisherige „Domains"-section --></TabsContent>
    <TabsContent value="upstreams"><!-- bisherige „Upstreams"-section --></TabsContent>
    <TabsContent value="tokens"><!-- Callout + Erstell-Form + erweiterte Tokens-Tabelle (siehe unten) --></TabsContent>
    <TabsContent value="einrichtung">
        <RegistrySetup :snippets="props.setup" store-route="admin.tokens.store"
            :store-payload="{ organization_id: props.group.organization_id, group_id: props.group.id }"
            :personal-tokens="props.tokens" />
    </TabsContent>
</Tabs>
```
    Die vorhandenen `<section>`-Inhalte bleiben inhaltlich erhalten (nur der äußere `<section>`-Wrapper wandert in `TabsContent`; die inneren `<h2>`-Überschriften können entfallen, da die Tab-Reiter beschriften).
  - **Tokens-Tab-Inhalt:** oben der Klartext-Callout (analog `portal/Registry.vue` 253–270 mit `plainTextToken`/`copyToken`), darunter das Erstell-Form (Name + Recht + „Token erstellen"-Button → `submitToken`), darunter die bestehende Tokens-Tabelle — Spalte „Aktionen" ergänzen mit Widerrufen-Button (`destroyToken(token.id)`, `Trash2`). Für „Kopieren" des Klartext-Tokens die vorhandene `copy()`-Funktion wiederverwenden.
  - Die alte separate „Einrichtung"-`section` samt lokaler `steps`-Definition (Zeilen 64–68 + 360–378) entfällt — ersetzt durch `RegistrySetup`. Die lokale `copy`/`copiedKey`-Logik nur behalten, falls noch für den Token-Callout genutzt; sonst entfernen (ESLint no-unused).

- [ ] **Step 6:** `ddev exec npm run build` + Lint grün.
- [ ] **Step 7: Commit** `feat: tabs and inline token management on the registry detail page`.

---

### Task 7: Paket-Detailseite (Admin) → Tabs

**Files:** Modify `resources/js/pages/admin/packages/Show.vue`.

- [ ] **Step 1:** Import ergänzen: `import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';`.
- [ ] **Step 2:** Kopfbereich (Zeilen 84–112, Titel/Badges/Beschreibung/Repo/Sync-Fehler) unverändert lassen. Die drei `<section>`-Blöcke (Installation, Registries, Versionen) in Tabs überführen:
```html
<Tabs default-value="installation">
    <TabsList>
        <TabsTrigger value="installation">Installation</TabsTrigger>
        <TabsTrigger value="registries">Registries</TabsTrigger>
        <TabsTrigger value="versionen">Versionen ({{ props.versions.length }})</TabsTrigger>
    </TabsList>
    <TabsContent value="installation"><!-- Installation-section --></TabsContent>
    <TabsContent value="registries"><!-- Registries-section --></TabsContent>
    <TabsContent value="versionen"><!-- Versionen-section --></TabsContent>
</Tabs>
```
    Inhalte 1:1 erhalten; innere `<h2>` können entfallen.
- [ ] **Step 3:** `ddev exec npm run build` + Lint grün.
- [ ] **Step 4: Commit** `feat: tabs on the package detail page`.

---

### Task 8: Portal-Registry → Tabs + RegistrySetup

**Files:** Modify `resources/js/pages/portal/Registry.vue`.

- [ ] **Step 1:** Imports ergänzen: `Tabs, TabsContent, TabsList, TabsTrigger` aus `@/components/ui/tabs`; `import RegistrySetup from '@/components/kontorfix/RegistrySetup.vue';`.
- [ ] **Step 2:** Kopf (Zeilen 163–166) unverändert. Drei Bereiche in Tabs: „Einrichtung" (RegistrySetup), „Pakete" (bestehender Filter+Tabelle-Block 185–248), „Tokens" (bestehender Token-Block 250–332).
```html
<Tabs default-value="einrichtung">
    <TabsList>
        <TabsTrigger value="einrichtung">Einrichtung</TabsTrigger>
        <TabsTrigger value="pakete">Pakete</TabsTrigger>
        <TabsTrigger value="tokens">Zugriffstokens</TabsTrigger>
    </TabsList>
    <TabsContent value="einrichtung">
        <RegistrySetup :snippets="props.snippets" store-route="portal.tokens.store"
            :store-payload="{ group_id: props.registry.id }" :personal-tokens="props.tokens" />
    </TabsContent>
    <TabsContent value="pakete"><!-- Filter + Paket-Tabelle --></TabsContent>
    <TabsContent value="tokens"><!-- Callout + Token-Form + Tabelle --></TabsContent>
</Tabs>
```
- [ ] **Step 3:** Den alten Snippets-Block (168–183) samt lokaler `steps`-Definition (151–155) entfernen — ersetzt durch RegistrySetup. Die lokale `copy`/`copiedKey`-Logik nur behalten, wenn noch anderweitig genutzt; sonst entfernen. `props.tokens` in RegistrySetup nutzt `ability` als string — Typ `PersonalToken.ability: string` deckt `'read'|'publish'` ab.
- [ ] **Step 4:** `ddev exec npm run build` + Lint grün. Manuell/visuell keine Regression im Portal-Token-Flow (Erstellen weiterhin via portal.tokens.store).
- [ ] **Step 5: Commit** `feat: tabs and create-and-insert setup in the customer portal registry`.

---

### Task 9: E2E-Flow + volle Verifikation

**Files:** Test `tests/Feature/PersonalTokenFlowTest.php`.

- [ ] **Step 1: E2E-Test** `tests/Feature/PersonalTokenFlowTest.php` — persönliches, globales Token erstellen und damit eine private Registry lesen:
```php
<?php

use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a personal global token in settings and it authenticates against a group of the same org', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $group = Group::factory()->create(['organization_id' => $org->id, 'public' => false]);

    // In den Settings erstellen (global, ohne group_id).
    $this->actingAs($user)->post('/settings/tokens', ['name' => 'ci', 'ability' => 'read'])
        ->assertSessionHas('plainTextToken');

    $plain = session('plainTextToken');
    $token = RegistryToken::findByPlainText($plain);

    expect($token)->not->toBeNull();
    expect($token->user_id)->toBe($user->id);
    expect($token->group_id)->toBeNull();
    expect($token->ability)->toBe(TokenAbility::Read);
    // Org-weites Token → für jede Registry der eigenen Org gültig.
    expect($token->organization_id)->toBe($group->organization_id);
});
```
Falls es einen vorhandenen Zugriffs-/ACL-Service (`RegistryAccessService`) gibt, zusätzlich dessen Read-Berechtigung für `$group` mit `$token` prüfen (an bestehenden Token-Auth-Tests orientieren).

- [ ] **Step 2: Run → PASS** `ddev exec vendor/bin/pest --filter=PersonalTokenFlowTest`.
- [ ] **Step 3: Volle Suite** `ddev exec vendor/bin/pest` — **Gesamtzahl melden** (Ausgangsbasis war 383 grün + neue Tests).
- [ ] **Step 4:** `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse`, `ddev exec npm run lint`, `ddev exec npm run build` — alles grün.
- [ ] **Step 5: Commit** `test: end-to-end personal token flow`.

---

## Self-Review

- **Deckt die vier Wünsche:**
  1. *Token-Erstellung direkt bei Gruppen* → Task 6 (Tokens-Tab mit Erstell-Form + Löschen, `admin.tokens.store/destroy`).
  2. *Tabs auf Detailseiten* → Tasks 6 (Registry), 7 (Paket-Admin), 8 (Portal-Registry); Tabs-Komponente Task 3.
  3. *Persönliche Tokens in Settings + globales Token* → Tasks 1/2/5 (`user_id`, `settings/tokens`, „Global"-Geltungsbereich).
  4. *Select + „erstellen & einsetzen" in der Einrichtung* → Task 4 (`RegistrySetup`) in Tasks 6/8 eingebunden.
- **Sicherheits-Invariante gewahrt:** Klartext nur einmalig (bestehender `flash.plainTextToken`); Widget setzt ausschließlich frisch erstellte Werte ein, vorhandene Tokens bleiben wert-verborgen (UI-Hinweis). Persönliche Tokens: Index/Delete strikt auf `user_id === auth()->id`; `group_id` nur aus eigener Org (`Rule::exists … organization_id`). Operator-Invariante unberührt (Admin-Token-Store bleibt operator-gated unter `/admin`).
- **Kein Bruch bestehender Modelle:** `issue()` erweitert additiv (Default `?User $owner = null`), Migration additiv (nullable, `nullOnDelete`). Bestehende Admin-Token-Erstellung (org-scoped, `user_id` = null) bleibt gültig.
- **Review-Gate:** Nach Task 9 adversariales Opus-Review (Fokus: Token-Ownership-Isolation, Cross-Org-Scope, Klartext-Leak über Flash/Props, Route-Namens-Kollisionen) vor dem Push.
- **Verschoben:** Admin-Token-Store-`user_id` (Operator-Tokens bleiben org-Tokens); OCI (Phase 2); pro-Org-Broadcast-Channel.
