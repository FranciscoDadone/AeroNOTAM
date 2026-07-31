<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the AIP publishes for the aerodromes MADHEL delegates to it instead of
 * publishing fuel, telephone and hours itself — `fuel`, `telephone` and
 * `service_schedule` from …_add_madhel_details_to_airports_table, but stay
 * null for exactly those aerodromes, by design (see that migration).
 *
 * Separate columns rather than filling in MADHEL's, on purpose: the ficha's
 * "sin dato publicado en MADHEL" is only honest as long as those columns are
 * MADHEL's own. A second source writing into them would make that sentence a
 * lie about a field the AIP did in fact publish. `notams:import-aip-details`
 * owns this set the same way notams:import-airport-details owns the other.
 *
 * ats_frequency has no MADHEL equivalent to collide with — nobody has ever
 * published tower/approach frequency through this table before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airports', function (Blueprint $table) {
            $table->text('aip_fuel')->nullable();
            $table->json('aip_telephone')->nullable();
            $table->text('aip_service_schedule')->nullable();

            // Free text: designation, call sign and the channel/frequency
            // pairs of the aerodrome's first ATS row (TWR, or TWR/APP where
            // one radio does both) — see AipAdDetails::atsFrequency() for why
            // only the first.
            $table->text('aip_ats_frequency')->nullable();

            // Same role as details_updated_at: whether this aerodrome's AIP
            // ficha was ever imported, which no other column can answer —
            // every one of them is legitimately null when the AIP's PDF
            // simply does not publish that row either.
            $table->timestamp('aip_details_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('airports', function (Blueprint $table) {
            $table->dropColumn([
                'aip_fuel', 'aip_telephone', 'aip_service_schedule',
                'aip_ats_frequency', 'aip_details_updated_at',
            ]);
        });
    }
};
