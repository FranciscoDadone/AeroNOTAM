<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ANAC's place selector only lists aerodromes with a currently active NOTAM,
 * so it cannot tell "unknown code" apart from "known aerodrome, nothing active
 * right now". This table is the durable registry that can: seeded from codes
 * observed in that selector, then accrued by the notams:refresh-airports
 * command. It replaces a Cache::forever key that any cache:clear would wipe.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('airports', function (Blueprint $table) {
            $table->id();
            $table->string('anac_code')->unique();
            $table->string('icao_code')->nullable()->unique();
            $table->string('name');

            // ANAC's list mixes real aerodromes with FIR-wide advisory
            // pseudo-codes ("---", "-EF"). Classified once on write, where
            // ctype_alpha() can decide it, rather than re-deriving it in SQL
            // on every read.
            $table->boolean('is_aerodrome')->default(true)->index();

            // Last time ANAC reported this aerodrome as having active NOTAMs.
            // Null means we know it exists but have never seen it active.
            $table->timestamp('last_seen_active_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airports');
    }
};
