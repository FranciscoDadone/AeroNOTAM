<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an aerodrome *is*, as opposed to what is happening at it right now.
 *
 * Everything the bot answered until now — NOTAM, METAR, TAF, viento en pista —
 * is about this afternoon. None of it says how far the aerodrome is from the
 * town, how high it sits, whether there is fuel or who to call. MADHEL has all
 * of that on its per-aerodrome record and the registry import was reading only
 * the label; these columns are where the rest of it lands.
 *
 * All nullable, and that is the point rather than a concession: MADHEL leaves
 * `data` empty for the seventeen aerodromes it delegates to the AIP (Ezeiza,
 * Aeroparque, Córdoba, Rosario, Santa Rosa…), which are exactly the ones people
 * ask about, and publishes fuel for barely one aerodrome in seven. A null here
 * means "MADHEL does not publish it", never "the aerodrome does not have it" —
 * see WhatsappBotService::formatAirportInfo(), which says so out loud.
 *
 * latitude/longitude are deliberately absent: they already arrived with
 * …_add_coordinates_to_airports_table and the ficha formats them from there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airports', function (Blueprint $table) {
            // No unique index, unlike icao_code: MADHEL hands the same IATA
            // code to more than one entry, and an import must not fail over a
            // field nothing is ever looked up by.
            $table->string('iata_code')->nullable();

            // The ICAO FIR — SAEF, SACF, SAVF, SARR, SAMF. Not the same
            // vocabulary as PronareaFirResolver's five short codes, which come
            // from the SMN's own table.
            $table->string('fir')->nullable();

            // "4,5 km al NNE de Santa Rosa": the three fields MADHEL splits
            // that sentence into. direction_reference is a compass point in
            // the Spanish rose (NNE, NNO, SSO, ONO) — with a handful of
            // English leftovers (SW, NNW) and the literal "Lindando" for the
            // thirty-one aerodromes that sit against the town they serve.
            $table->string('city_reference')->nullable();
            $table->decimal('distance_km', 5, 1)->nullable();
            $table->string('direction_reference')->nullable();

            // Metres above mean sea level, as MADHEL publishes it. The ficha
            // prints feet alongside, because that is what aviation flies in.
            $table->smallInteger('elevation_m')->nullable();

            $table->string('state')->nullable();

            // 'NTL' or 'INTL'.
            $table->string('traffic')->nullable();

            // MADHEL's is_granted: this aerodrome's details are published in
            // the AIP rather than here, which is why `data` came back empty.
            // Worth storing rather than inferring from the empty fields, since
            // it is the difference between "nobody published this" and "it is
            // published somewhere we can point at".
            $table->boolean('is_aip_delegated')->default(false);

            // Free prose, printed verbatim: "AVGAS 100 LL", but also "AVGAS
            // 100LL (No disponible)." and a paragraph with a phone number to
            // coordinate 24 hours ahead. Normalising that would lose the part
            // a pilot actually needs.
            $table->text('fuel')->nullable();

            // An array in MADHEL, and more than one number for some
            // aerodromes, so it is stored as one rather than flattened.
            $table->json('telephone')->nullable();

            $table->text('service_schedule')->nullable();

            // Whether the ficha was ever imported for this aerodrome, which no
            // other column can answer: every one of them is legitimately null
            // for some real aerodrome.
            $table->timestamp('details_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('airports', function (Blueprint $table) {
            $table->dropColumn([
                'iata_code', 'fir', 'city_reference', 'distance_km', 'direction_reference',
                'elevation_m', 'state', 'traffic', 'is_aip_delegated', 'fuel', 'telephone',
                'service_schedule', 'details_updated_at',
            ]);
        });
    }
};
