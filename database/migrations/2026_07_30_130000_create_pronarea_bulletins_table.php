<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per FIR, always overwritten with the last PRONAREA bulletin fetched
 * successfully — never a history, since nothing here ever reads anything but
 * the latest. Same shape and same reason as aeromet_station_observations:
 * what SmnPronareaService falls back to, marked stale, when a fresh fetch
 * fails, kept in a database row rather than the generic cache store so it
 * survives a Cache::flush() aimed at everything else the application caches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pronarea_bulletins', function (Blueprint $table) {
            $table->id();

            $table->string('fir')->unique();
            $table->text('raw');

            // When this row was last confirmed fresh — not created_at/updated_at,
            // so "how old is this reading" stays a plain, explicit read rather
            // than leaning on Eloquent's own timestamp-touching rules.
            $table->timestamp('fetched_at')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pronarea_bulletins');
    }
};
