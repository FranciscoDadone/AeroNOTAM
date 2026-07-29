<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every inbound WhatsApp message, together with what the bot understood and
 * what it answered.
 *
 * The row is written by the webhook and not by the job that builds the reply,
 * so a message nobody ever answered — queue stopped, ANAC down, retries
 * exhausted — still leaves a trace. Those are precisely the rows worth looking
 * at in the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();

            // Provider address form, "whatsapp:+5491122334455", identical to
            // metar_subscriptions.phone so both can be lined up per user.
            $table->string('phone')->index();

            // WhatsApp's display name. The user controls it and can change it
            // at any time, so it labels a row but never keys one.
            $table->string('profile_name')->nullable();

            $table->string('message_sid')->nullable()->index();

            $table->text('body');
            $table->string('button_payload')->nullable();

            // What the bot made of the message. A null anac_code means nothing
            // matched, which is the most useful signal we have for improving
            // the aerodrome matcher.
            $table->string('topic', 16)->nullable()->index();
            $table->string('anac_code', 8)->nullable()->index();
            $table->string('icao_code', 4)->nullable();

            $table->string('status', 16)->default('pending')->index();

            $table->json('reply')->nullable();
            $table->text('error')->nullable();

            // Build plus delivery, i.e. what the user experiences as the wait.
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
