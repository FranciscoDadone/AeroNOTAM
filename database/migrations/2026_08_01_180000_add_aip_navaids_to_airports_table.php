<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The aids a pilot tunes on the way in — the VOR and its DME, the NDB, the ILS
 * localiser — off AD 2.19 of the same PDF …_add_aip_details_to_airports_table
 * already reads fuel, telephone, hours and the ATS frequency from.
 *
 * Nothing to collide with, same as aip_ats_frequency: MADHEL has never
 * published a radio navigation aid for any aerodrome, so there is no MADHEL
 * column this could be mistaken for or quietly overwrite.
 *
 * Json rather than a `navaids` table because these are two or three rows per
 * aerodrome that nothing ever queries on their own — no "which aerodromes have
 * an ILS", no join, no lookup by identifier. They exist to be printed under
 * the ficha's own row, and a table would be an index and a model for a list
 * that is only ever read whole.
 *
 * Structured rather than the single free-text line aip_ats_frequency settled
 * for: that one is a parser working around a table it can only read the first
 * row of, whereas this reads every row cleanly, and keeping type, identifier,
 * frequency and hours apart means the message's format can change without
 * re-downloading forty PDFs to reformat a string.
 *
 * No timestamp of its own — aip_details_updated_at already answers the one
 * question a null cannot: whether this aerodrome's AIP ficha was ever read at
 * all, as opposed to read and found to publish no aids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airports', function (Blueprint $table) {
            $table->json('aip_navaids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('airports', function (Blueprint $table) {
            $table->dropColumn('aip_navaids');
        });
    }
};
