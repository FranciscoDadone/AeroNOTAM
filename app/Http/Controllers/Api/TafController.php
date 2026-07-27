<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TafIndexRequest;
use App\Http\Resources\TafResource;
use App\Services\TafEnricher;
use App\Services\TafService;
use App\Support\AirportResolver;
use Illuminate\Http\JsonResponse;
use Throwable;

class TafController extends Controller
{
    public function __construct(
        protected TafService $tafs,
        protected TafEnricher $enricher,
        protected AirportResolver $airports,
    ) {}

    /**
     * GET /api/v1/taf?aerodromo=EZE[&decode=false]
     *
     * Accepts the same identifiers as the NOTAM and METAR endpoints — ANAC code
     * ("EZE") or ICAO code ("SAEZ") — and resolves whichever it gets to the
     * ICAO code the SMN indexes forecasts by.
     */
    public function index(TafIndexRequest $request): JsonResponse
    {
        $indicator = $this->airports->resolve($request->indicator());

        if (! $this->airports->exists($indicator)) {
            return response()->json([
                'message' => "El indicador '{$indicator}' no es un aeródromo reconocido.",
                'aerodromo' => $indicator,
            ], 404);
        }

        $icao = $this->airports->icaoFor($indicator);

        // Plenty of ANAC aerodromes have no ICAO code, and the SMN has no way
        // to look one up without it. That is a permanent property of the
        // aerodrome, not a transient failure, so it is a 404 rather than a 502.
        if ($icao === null) {
            return response()->json([
                'message' => "El aeródromo '{$indicator}' no tiene código OACI, por lo que el SMN no publica TAF para él.",
                'aerodromo' => $indicator,
                'nombre' => $this->airports->nameFor($indicator),
            ], 404);
        }

        try {
            $tafs = $this->tafs->getTafs($icao);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo obtener el pronóstico TAF en este momento.',
            ], 502);
        }

        if ($request->wantsDecoding()) {
            $tafs = $this->enricher->enrich($tafs);
        }

        return response()->json([
            'aerodromo' => $indicator,
            'estacion' => $icao,
            'nombre' => $this->airports->nameFor($indicator),
            'cantidad' => count($tafs),
            'tafs' => TafResource::collection($tafs)->resolve(),
        ]);
    }
}
