<script setup lang="ts">
import { severityClass, severityLabel, type Severity } from '@/lib/severity';
import { computed, ref } from 'vue';

export interface ScanFinding {
    vulnerability_id: string;
    severity: Severity;
    severity_label: string;
    package_name: string;
    installed_version: string | null;
    fixed_version: string | null;
    first_seen_at: string;
}

export interface ScanCard {
    status: 'pending' | 'ok' | 'failed';
    status_label: string;
    // Null on the portal card: the scanner's product/version is operator-internal detail,
    // withheld from the customer (ScanCardPresenter's `$forCustomer` mode). Not rendered
    // here either way — kept nullable so the type says what the payload actually carries.
    scanner: string | null;
    // A relative stamp ("vor 3 Tagen"), the same as every other timestamp this page
    // renders (e.g. `pushed_at`) — never the raw `Y-m-d H:i:s` a customer would have to
    // parse themselves.
    scanned_at: string | null;
    stale: boolean;
    error: string | null;
    counts: { critical: number; high: number; medium: number; low: number; unknown: number };
    blocked: boolean;
    blocks_at: string | null;
    findings: ScanFinding[];
}

/**
 * The verdict for one image, rendered identically in the console and in the portal.
 *
 * `scan === null` is NOT CHECKED and says so. Rendering it as "keine Funde" would turn a
 * scanner nobody has run into a clean bill of health — the single most misleading thing
 * this component could do.
 */
const props = defineProps<{ scan: ScanCard | null }>();

const open = ref(false);
const total = computed(() => (props.scan ? Object.values(props.scan.counts).reduce((a, b) => a + b, 0) : 0));
const ordered: Severity[] = ['critical', 'high', 'medium', 'low', 'unknown'];
</script>

<template>
    <div v-if="scan === null" class="text-sm text-muted-foreground">Noch nicht geprüft</div>

    <div v-else class="space-y-2 text-sm">
        <div class="flex flex-wrap items-center gap-2">
            <template v-for="severity in ordered" :key="severity">
                <span v-if="scan.counts[severity] > 0" class="rounded px-1.5 py-0.5 text-xs" :class="severityClass(severity)">
                    {{ scan.counts[severity] }} {{ severityLabel(severity) }}
                </span>
            </template>
            <span v-if="total === 0 && scan.status === 'ok'" class="text-muted-foreground">Keine bekannten Schwachstellen</span>
            <!-- `pending` and `failed` are DIFFERENT statements ("nobody has looked yet" vs.
                 "we looked and it went wrong" — ScanStatus's own docblock) and must not
                 render identically. Before this, `pending` matched none of the branches
                 here and the whole card went blank — an operator who just clicked "Jetzt
                 prüfen" saw an EMPTIER cell than the "Noch nicht geprüft" it replaced. -->
            <span v-if="scan.status === 'pending'" class="text-muted-foreground">{{ scan.status_label }}</span>
            <span v-if="scan.status === 'failed'" class="text-destructive">{{ scan.status_label }}</span>
        </div>

        <p v-if="scan.blocked" class="text-destructive">Dieses Image wird von dieser Registry nicht mehr ausgeliefert.</p>
        <p v-else-if="scan.blocks_at" class="text-amber-700 dark:text-amber-400">Ab {{ scan.blocks_at }} wird dieses Image nicht mehr ausgeliefert.</p>

        <p v-if="scan.stale" class="text-amber-700 dark:text-amber-400">
            Letzte erfolgreiche Prüfung: {{ scan.scanned_at }} — seither ist keine Prüfung mehr gelungen<span v-if="scan.error"
                >: {{ scan.error }}</span
            >.
        </p>

        <button v-if="scan.findings.length > 0" type="button" class="underline" @click="open = !open">
            {{ open ? 'Funde ausblenden' : `${scan.findings.length} Fund(e) anzeigen` }}
        </button>

        <table v-if="open" class="w-full text-left text-xs">
            <thead class="text-muted-foreground">
                <tr>
                    <th class="py-1">Schweregrad</th>
                    <th>Kennung</th>
                    <th>Paket</th>
                    <th>Installiert</th>
                    <th>Behoben in</th>
                    <th>Bekannt seit</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="finding in scan.findings" :key="`${finding.vulnerability_id}-${finding.package_name}`" class="border-t">
                    <td class="py-1">
                        <span class="rounded px-1.5 py-0.5" :class="severityClass(finding.severity)">{{ finding.severity_label }}</span>
                    </td>
                    <td class="font-mono">{{ finding.vulnerability_id }}</td>
                    <td>{{ finding.package_name }}</td>
                    <td>{{ finding.installed_version ?? '—' }}</td>
                    <td>{{ finding.fixed_version ?? 'kein Fix verfügbar' }}</td>
                    <td>{{ finding.first_seen_at }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
