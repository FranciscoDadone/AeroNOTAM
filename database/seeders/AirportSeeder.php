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
            $rows[] = $row + [
                'is_aerodrome' => Airport::isAerodromeCode($row['anac_code']),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Idempotent: re-seeding refreshes names without discarding the
        // last_seen_active_at history the refresh command has accrued.
        //
        // Chunked because SQLite counts every bound value against a single
        // statement limit, and the snapshot is 700-odd rows wide.
        foreach (array_chunk($rows, 200) as $chunk) {
            Airport::upsert(
                $chunk,
                ['anac_code'],
                ['icao_code', 'name', 'is_aerodrome', 'kind', 'access', 'is_controlled', 'is_closed', 'latitude', 'longitude', 'updated_at'],
            );
        }
    }

    /**
     * @return array<int, array{anac_code: string, icao_code: string|null, name: string, kind: string, access: string|null, is_controlled: bool, is_closed: bool, latitude: float|null, longitude: float|null}>
     */
    protected function airports(): array
    {
        return require database_path('seeders/data/airports.php');
    }
}
