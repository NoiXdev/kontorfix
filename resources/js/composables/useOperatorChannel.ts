import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

export type PackagePayload = {
    id: string;
    name: string;
    type: string;
    sync_status: string;
    error?: string;
};

/**
 * Subscribes to the instance-wide `operator` channel. Its events carry every tenant's
 * package names and raw sync error text, so the server admits super-admins only
 * (routes/channels.php) — while `EnsureOperator` still lets any org admin/maintainer
 * onto the pages that use this. The predicate therefore lives here rather than at the
 * call sites: gating on the wrong capability is how three pages ended up asking for a
 * channel they are not allowed on, each answered with a 403 from `/broadcasting/auth`
 * that nothing was listening for.
 */
export function useOperatorChannel(handlers: { onSynced?: (p: PackagePayload) => void; onFailed?: (p: PackagePayload) => void }) {
    const page = usePage<SharedData>();
    const maySubscribe = page.props.auth?.can?.super ?? false;

    onMounted(() => {
        if (!maySubscribe) {
            return;
        }

        try {
            const ch = window.Echo?.private('operator');
            ch?.listen('.package.synced', (e: PackagePayload) => handlers.onSynced?.(e));
            ch?.listen('.package.sync_failed', (e: PackagePayload) => handlers.onFailed?.(e));
            // Subscription is asynchronous, so a rejected auth never reaches the catch
            // below. Live updates are a convenience; degrade to the rendered snapshot.
            ch?.error?.(() => {
                window.Echo?.leave('operator');
            });
        } catch {
            /* WS not available → silently ignore */
        }
    });

    onBeforeUnmount(() => {
        if (!maySubscribe) {
            return;
        }

        try {
            window.Echo?.leave('operator');
        } catch {
            /* ignore */
        }
    });
}
