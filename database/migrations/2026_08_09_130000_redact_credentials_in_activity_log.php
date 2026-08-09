<?php

use App\Support\CredentialUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes credentials already written into the activity log.
 *
 * Both json columns are scrubbed. The changed values live in `attribute_changes` — the
 * finding named `properties`, which this package version uses for the caller-supplied bag —
 * and a credential can reach either, so neither is assumed clean.
 *
 * `Package` logged `repository_url` verbatim, and that column legitimately carries
 * `https://x-access-token:<PAT>@github.com/…`. The write side redacts from now on, but the
 * rows written before it did still hold the secret in cleartext — and they hold it *after*
 * the operator removes it from the live field, which is exactly the moment the value is
 * supposed to be gone. Rotation is the response to a leak, and this copy survived rotation.
 *
 * Only the userinfo component is replaced, so the audit trail keeps saying which host a
 * repository moved to and when. Applied to every string in every activity's property bag
 * rather than to one column, because the same shape reaches the log from anything that ever
 * logs a URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('activity_log')
            ->select('id', 'attribute_changes', 'properties')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $update = [];

                    foreach (['attribute_changes', 'properties'] as $column) {
                        $decoded = json_decode((string) $row->{$column}, true);
                        if (! is_array($decoded)) {
                            continue;
                        }

                        $redacted = $this->redact($decoded);
                        if ($redacted !== $decoded) {
                            $update[$column] = json_encode($redacted);
                        }
                    }

                    if ($update !== []) {
                        DB::table('activity_log')->where('id', $row->id)->update($update);
                    }
                }
            });
    }

    /**
     * Irreversible on purpose: the point is that the secret is gone.
     */
    public function down(): void {}

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->redact($value);

                continue;
            }

            if (is_string($value) && CredentialUrl::carries($value)) {
                $values[$key] = CredentialUrl::redact($value);
            }
        }

        return $values;
    }
};
