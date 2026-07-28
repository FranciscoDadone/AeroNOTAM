<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who wants to be told when an aerodrome's weather changes.
 *
 * This is the first thing in the application that outlives a single WhatsApp
 * exchange: until now the bot answered and forgot. A subscription is deliberately
 * short-lived — it expires on its own rather than living until someone remembers
 * to cancel it — because the alert has to reach the user inside Twilio's 24-hour
 * session window, and because the real use is "watch this aerodrome while I plan
 * this flight", not forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metar_subscriptions', function (Blueprint $table) {
            $table->id();

            // Exactly as Twilio addresses it, e.g. "whatsapp:+5491122334455".
            // Storing the provider's own form means we never have to rebuild it
            // to send, and never have to guess how to normalize a phone number.
            $table->string('phone')->index();

            // Both codes are kept: the ANAC indicator is what the rest of the
            // application speaks and what names the aerodrome, the ICAO code is
            // what the SMN indexes observations by. Freezing the pair here means
            // the watcher never has to resolve anything to do its round.
            $table->string('anac_code', 8);
            $table->string('icao_code', 4);

            $table->timestamp('expires_at')->index();

            // The observation this subscription is currently being compared
            // against. Per subscription and not per station on purpose: someone
            // who subscribes at 14:05 is measured from the 14:00 report,
            // regardless of what another subscriber to the same aerodrome has
            // already been told about.
            $table->text('last_raw')->nullable();

            $table->timestamp('last_notified_at')->nullable();

            $table->timestamps();

            // Re-subscribing renews an existing watch rather than stacking a
            // second one, which is what stops a user from being notified twice
            // for the same change.
            $table->unique(['phone', 'anac_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metar_subscriptions');
    }
};
