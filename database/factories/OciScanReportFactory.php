<?php

namespace Database\Factories;

use App\Enums\ScanStatus;
use App\Models\OciManifest;
use App\Models\OciScanReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OciScanReport>
 */
class OciScanReportFactory extends Factory
{
    protected $model = OciScanReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'manifest_id' => OciManifest::factory(),
            'scanner_name' => 'Trivy',
            'scanner_version' => '0.50.1',
            'status' => ScanStatus::Ok,
            'scanned_at' => now(),
        ];
    }
}
