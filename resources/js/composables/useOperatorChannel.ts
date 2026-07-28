import { onBeforeUnmount, onMounted } from 'vue';

export type PackagePayload = {
    id: string;
    name: string;
    type: string;
    sync_status: string;
    error?: string;
};

export function useOperatorChannel(handlers: {
    onSynced?: (p: PackagePayload) => void;
    onFailed?: (p: PackagePayload) => void;
}) {
    onMounted(() => {
        try {
            const ch = window.Echo?.private('operator');
            ch?.listen('.package.synced', (e: PackagePayload) => handlers.onSynced?.(e));
            ch?.listen('.package.sync_failed', (e: PackagePayload) => handlers.onFailed?.(e));
        } catch {
            /* WS not available → silently ignore */
        }
    });

    onBeforeUnmount(() => {
        try {
            window.Echo?.leave('operator');
        } catch {
            /* ignore */
        }
    });
}
