<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The registry grows from the ~64 airports ANAC's NOTAM selector happens to
 * show to the full MADHEL roster (712 aerodromes and heliports), so that a
 * place like ELP — a real public aerodrome that simply never had an active
 * NOTAM while we were looking — stops being reported as unknown.
 *
 * At that size the table stops being a flat list: a private agricultural strip
 * and Ezeiza are not interchangeable when matching "santa rosa" from a WhatsApp
 * message, so MADHEL's classification is stored alongside the codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('airports', function (Blueprint $table) {
            // 'AD' aerodrome or 'HLP' heliport.
            $table->string('kind')->default('AD');

            // 'publico', 'privado' or 'militar'; null when MADHEL doesn't say.
            $table->string('access')->nullable()->index();

            $table->boolean('is_controlled')->default(false);

            // MADHEL flags closed aerodromes in place rather than removing
            // them, and so do we: a NOTAM about the closure is exactly what a
            // pilot would be asking for.
            $table->boolean('is_closed')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('airports', function (Blueprint $table) {
            $table->dropColumn(['kind', 'access', 'is_controlled', 'is_closed']);
        });
    }
};
