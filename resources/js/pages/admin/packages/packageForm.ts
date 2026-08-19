import type { InertiaForm } from '@inertiajs/vue3';
import type { InjectionKey } from 'vue';

export interface PackageFormData {
    type: 'composer' | 'npm' | 'python';
    source_mode: 'publish' | 'git';
    name: string;
    repository_url: string;
    is_private: boolean;
    repository_token: string;
    git_credential_id: string;
    group_ids: string[];
}

// Create.vue owns the Inertia form — it still needs `form.processing` and `form.post(...)`
// for its own submit button — and `provide`s it under this key. Form.vue `inject`s it
// instead of receiving it as a prop, so writing into its fields (`v-model="form.xxx"`) is
// never prop mutation: the repo-wide `vue/no-mutating-props` rule stays at its default
// everywhere, including here.
export const packageFormKey: InjectionKey<InertiaForm<PackageFormData>> = Symbol('package-form');

export interface GroupOption {
    id: string;
    name: string;
    slug: string;
}

export interface GitCredentialOption {
    id: string;
    name: string;
    provider: string;
}

export type SourceModeMap = Record<string, { value: string; label: string }[]>;

export interface ProbeResult {
    ok: boolean;
    error?: string;
    name?: string | null;
    description?: string | null;
    versions: string[];
}

/** The modes a given package type actually allows, per PackageSourceMode::allowedFor() on
 * the server — the single source of truth. */
export function modesFor(sourceModes: SourceModeMap, type: string): { value: string; label: string }[] {
    return sourceModes[type] ?? [];
}

/** Rendered/offered only for a type with more than one allowed mode (today: Python).
 * Composer is always git, npm always publish — offering either a choice would offer one the
 * server refuses. Shared between Form.vue (which renders the selector) and Create.vue (which
 * drops the field from the submitted payload when there is no real choice to make). */
export function canChooseSourceMode(sourceModes: SourceModeMap, type: string): boolean {
    return modesFor(sourceModes, type).length > 1;
}

/** A type whose only/default mode is git (Composer today) is always git-mode, regardless of
 * what `sourceModeValue` happens to hold (its selector is hidden for such a type, so nothing
 * sets it deliberately). Derived from modesFor rather than a hardcoded 'composer' check, so
 * this stays correct if allowedFor() ever changes which type that is. Shared between Form.vue
 * (which drives the git-only fields and the probe action) and Create.vue (which gates
 * submission on it). */
export function isGitMode(sourceModes: SourceModeMap, type: string, sourceModeValue: string): boolean {
    return modesFor(sourceModes, type)[0]?.value === 'git' || sourceModeValue === 'git';
}
