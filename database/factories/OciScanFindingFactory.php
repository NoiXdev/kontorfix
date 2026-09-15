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
     * `severity_rank` is derived from `severity` here rather than randomised independently,
     * so no test can accidentally build a row the coupling guard forbids.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $severity = VulnerabilitySeverity::High;

        return [
            'report_id' => OciScanReport::factory(),
            'vulnerability_id' => 'CVE-2026-'.$this->faker->unique()->numberBetween(1000, 9999),
            'severity' => $severity,
            'severity_rank' => $severity->rank(),
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
            'severity_rank' => $severity->rank(),
        ]);
    }

    /** A finding first observed `$days` days ago — the grace period's test lever. */
    public function firstSeenDaysAgo(int $days): static
    {
        return $this->state(fn (): array => ['first_seen_at' => now()->subDays($days)]);
    }
}
