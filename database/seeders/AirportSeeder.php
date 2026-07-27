<?php

namespace Database\Seeders;

use App\Models\Airport;
use Illuminate\Database\Seeder;

class AirportSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [];

        foreach ($this->airports() as $row) {
            $rows[] = [
                'anac_code' => $row['anac_code'],
                'icao_code' => $row['icao_code'],
                'name' => $row['name'],
                'is_aerodrome' => Airport::isAerodromeCode($row['anac_code']),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Idempotent: re-seeding refreshes names without discarding the
        // last_seen_active_at history the refresh command has accrued.
        Airport::upsert($rows, ['anac_code'], ['icao_code', 'name', 'is_aerodrome', 'updated_at']);
    }

    /**
     * @return array<int, array{anac_code: string, icao_code: string|null, name: string}>
     */
    protected function airports(): array
    {
        return require database_path('seeders/data/airports.php');
    }
}
