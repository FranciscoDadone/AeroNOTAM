<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each aerodrome is, and how far magnetic north is from true north there.
 *
 * Both exist for one reason: a runway designator is magnetic ("RWY 05" is
 * heading 050° magnetic) but the wind in a METAR is referenced to true north.
 * Comparing them without the correction is comparing two different norths, and
 * in Argentina that is not a rounding error — the declination runs from −10° in
 * Buenos Aires to +11.7° in Ushuaia, crossing zero in the south. Up to 20° of
 * error in the angle is several knots of crosswind at 20 kt.
 *
 * The coordinates cost nothing to collect: MADHEL's list endpoint has carried
 * them all along in the_geom, and the weekly import was simply dropping them.
 *
 * The variation is cached rather than computed per query because it drifts
 * about a tenth of a degree a year — magnetic_variation_at is what lets the
 * importer skip a place it already knows until the value is a year old.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airports', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Degrees, east positive — the sign convention of the WMM itself,
            // so that true = magnetic + variation with no case analysis.
            $table->decimal('magnetic_variation', 5, 2)->nullable();
            $table->timestamp('magnetic_variation_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('airports', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'magnetic_variation', 'magnetic_variation_at']);
        });
    }
};
