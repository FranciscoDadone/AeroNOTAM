<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotamIndexRequest;
use App\Http\Resources\NotamResource;
use App\Models\Airport;
use App\Services\AnacNotamService;
use App\Services\NotamEnricher;
use App\Support\AirportResolver;
use Illuminate\Http\JsonResponse;
use Throwable;

class NotamController extends Controller
{
    public function __construct(
        protected AnacNotamService $anac,
        protected NotamEnricher $enricher,
        protected AirportResolver $airports,
    ) {}

    /**
     * GET /api/notams?aerodromo=EZE[&decode=false]
     *
     * `decode=false` returns the raw scraped NOTAMs without the Spanish
     * decoding, skipping the AI calls entirely — useful for clients that only
     * need the source text and shouldn't pay the latency or the token cost.
     */
    public function index(NotamIndexRequest $request): JsonResponse
    {
        $indicator = $this->airports->resolve($request->indicator());

        // The registry is a local table now, so establishing whether an
        // aerodrome exists no longer depends on ANAC being reachable.
        if (! $this->airports->exists($indicator)) {
            return response()->json([
                'message' => "El indicador '{$indicator}' no es un aeródromo reconocido.",
                'aerodromo' => $indicator,
            ], 404);
        }

        try {
            $notams = $this->anac->getNotams($indicator);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo obtener la información de NOTAM de ANAC en este momento.',
            ], 502);
        }

        // Enrichment never throws — it degrades to the offline dictionary and
        // then to null — so it stays outside the try/catch above deliberately.
        if ($request->wantsDecoding()) {
            $notams = $this->enricher->enrich($notams);
        }

        return response()->json([
            'aerodromo' => $indicator,
            'nombre' => $this->airports->nameFor($indicator),
            'cantidad' => count($notams),
            'notams' => NotamResource::collection($notams)->resolve(),
        ]);
    }

    /**
     * GET /api/notams/aerodromos
     *
     * Every place indicator that can be asked about — ANAC's full MADHEL
     * registry, not just the handful with a NOTAM today. It used to be the
     * latter, scraped live, which meant this endpoint went 502 whenever ANAC
     * was down and quietly hid aerodromes that simply had nothing active.
     */
    public function aerodromos(): JsonResponse
    {
        $aerodromos = Airport::query()
            ->realAerodromes()
            ->orderBy('anac_code')
            ->get()
            ->map(fn (Airport $airport) => [
                'codigo' => $airport->anac_code,
                'nombre' => $airport->name,
                'oaci' => $airport->icao_code,
                'tipo' => $airport->kind,
                'acceso' => $airport->access,
                'controlado' => $airport->is_controlled,
                'cerrado' => $airport->is_closed,
                'notam_activo' => $airport->last_seen_active_at?->isAfter(now()->subDay()) ?? false,
            ]);

        return response()->json([
            'cantidad' => $aerodromos->count(),
            'aerodromos' => $aerodromos,
        ]);
    }
}
