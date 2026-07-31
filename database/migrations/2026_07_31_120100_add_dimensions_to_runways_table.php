<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long, how wide, of what, and whether it is lit — the four things a pilot
 * asks about a runway before deciding it is usable at all.
 *
 * Both sources the import already reads carry them and both were being thrown
 * away: MADHEL writes "05/23 1871x30 M - ASPH" and only the designator was
 * being read; OurAirports' runways.csv has length_ft/width_ft/surface/lighted
 * for 225 of the 233 Argentine runways and only the heading was.
 *
 * Repeated on the row of *each* end rather than split into a second table.
 * This table is one row per end for the reason its own migration gives — a
 * pilot lands on 05 or on 23, and the two differ in everything the wind
 * answer cares about — and carrying two duplicated integers is a smaller
 * price than a runways/runway_ends split that every read would then have to
 * join back together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runways', function (Blueprint $table) {
            // Metres, both. MADHEL publishes metres; OurAirports publishes
            // feet and the import converts on write, so a reader never has to
            // ask which source a number came from.
            $table->unsignedSmallInteger('length_m')->nullable();
            $table->unsignedSmallInteger('width_m')->nullable();

            // The one word the ficha prints: "asfalto", "tierra", "hormigón".
            // Normalised on write from each source's own vocabulary (MADHEL's
            // "ASPH"/"Tierra", OurAirports' "ASP"/"GRE"/"CON"), and falling
            // back to whatever was published when it is something neither
            // parser has seen — an unfamiliar surface is still a surface.
            $table->string('surface')->nullable();

            // Null is not false: MADHEL says nothing about lighting for most
            // aerodromes, and "no balizada" would be a claim we cannot make.
            $table->boolean('is_lighted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runways', function (Blueprint $table) {
            $table->dropColumn(['length_m', 'width_m', 'surface', 'is_lighted']);
        });
    }
};
