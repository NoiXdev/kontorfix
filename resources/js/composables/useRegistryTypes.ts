import { type RegistryTypeMeta, type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Single source of truth for registry-type metadata, shared from the backend
 * PackageType enum via Inertia (`registryTypeMeta`). Use this instead of hardcoding
 * type lists / labels / publish-based checks in components.
 */
export function useRegistryTypes() {
    const page = usePage<SharedData>();

    const meta = computed<RegistryTypeMeta[]>(() => page.props.registryTypeMeta ?? []);
    const byValue = computed<Record<string, RegistryTypeMeta>>(() => Object.fromEntries(meta.value.map((m) => [m.value, m])));

    function isPublishBased(type: string): boolean {
        return byValue.value[type]?.publish_based ?? false;
    }

    function label(type: string): string {
        return byValue.value[type]?.label ?? type;
    }

    /** Select options for the given type values (or all known types when omitted). */
    function options(values?: readonly string[]): { value: string; label: string }[] {
        const list = values ? meta.value.filter((m) => values.includes(m.value)) : meta.value;
        return list.map((m) => ({ value: m.value, label: m.label }));
    }

    return { meta, isPublishBased, label, options };
}
