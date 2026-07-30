<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runway ends, so the bot can answer the question a METAR stops one step short
 * of: "the wind is 350° at 15 kt — which end do I use, and how much of that is
 * across me?"
 *
 * One row per *end*, not per runway, because that is the unit of the answer.
 * A pilot lands on 05 or on 23, never on "05/23", and the two differ in
 * everything that matters here: the headwind reverses sign and the crosswind
 * swaps sides.
 *
 * There is no seeder and no committed snapshot. The table is filled and kept
 * current by notams:import-runways, which runs weekly right after the import
 * that decides which aerodromes exist at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runways', function (Blueprint $table) {
            $table->id();

            // Aerodromes are referenced by ANAC code throughout this project,
            // not by foreign key: the registry is imported wholesale and its
            // rows are replaced, so the stable handle is the code itself.
            $table->string('anac_code')->index();

            // "05", "23", "01L" — as published, including the L/C/R suffix that
            // tells parallel runways apart.
            $table->string('designator');

            // Degrees true. The designator is magnetic and the wind in a METAR
            // is true, so the correction is applied once here, on write, rather
            // than on every reading.
            $table->smallInteger('heading_true');

            // Closed ends are kept, not dropped. That a runway exists and
            // cannot be used is operational information; making it vanish from
            // the list would read as though the aerodrome never had it.
            $table->boolean('is_closed')->default(false);

            // 'madhel' or 'ourairports'. The two sources cover different halves
            // of the country's aerodromes, and when a heading looks wrong this
            // is what says where to go argue about it.
            $table->string('source');

            $table->timestamps();

            $table->unique(['anac_code', 'designator']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runways');
    }
};
