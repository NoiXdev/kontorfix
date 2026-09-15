<?php

namespace Database\Factories;

use App\Enums\VulnerabilitySeverity;
use App\Models\OciScanFinding;
use App\Models\OciScanReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OciScanFinding>
 */
class OciScanFindingFactory extends Factory
{
    protected $model = OciScanFinding::class;

    /**
     * `severity_rank` is not set here — `OciScanFinding::booted()` derives it from
     * `severity` on save, so this factory never has to (and never could) get it wrong.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_id' => OciScanReport::factory(),
            'vulnerability_id' => 'CVE-2026-'.$this->faker->unique()->numberBetween(1000, 9999),
            'severity' => VulnerabilitySeverity::High,
            'package_name' => 'openssl',
            'installed_version' => '3.0.1',
            'fixed_version' => '3.0.2',
            'first_seen_at' => now(),
        ];
    }

    public function severity(VulnerabilitySeverity $severity): static
    {
        return $this->state(fn (): array => [
            'severity' => $severity,
        ]);
    }

    /** A finding first observed `$days` days ago — the grace period's test lever. */
    public function firstSeenDaysAgo(int $days): static
    {
        return $this->state(fn (): array => ['first_seen_at' => now()->subDays($days)]);
    }
}
