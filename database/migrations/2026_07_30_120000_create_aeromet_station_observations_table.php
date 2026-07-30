<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per AEROMET station, always overwritten with the last observation
 * fetched successfully — never a history, since nothing here ever reads
 * anything but the latest.
 *
 * This is what AerometService falls back to, station by station, when a
 * round of the bulk fetch (see SmnAerometSource) does not come back with
 * that station in it — whether that is a genuine SMN-wide outage or just
 * that one station "erroneo"'d out of an otherwise fine response, which
 * cannot be told apart from the response alone. A database row survives a
 * cache flush and is inspectable directly, unlike the generic cache store
 * the rest of this fallback used to share with everything else the
 * application caches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aeromet_station_observations', function (Blueprint $table) {
            $table->id();

            $table->string('wmo_code')->unique();
            $table->string('airport_name');
            $table->string('issued_at');
            $table->text('raw');
            $table->text('phenomenon_note')->nullable();

            // When this row was last confirmed fresh — not created_at/updated_at,
            // so "how old is this reading" stays a plain, explicit read rather
            // than leaning on Eloquent's own timestamp-touching rules.
            $table->timestamp('fetched_at')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aeromet_station_observations');
    }
};
