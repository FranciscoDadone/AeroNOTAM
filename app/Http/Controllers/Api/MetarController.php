<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MetarIndexRequest;
use App\Http\Resources\MetarResource;
use App\Services\MetarEnricher;
use App\Services\SmnMetarService;
use App\Support\AirportResolver;
use Illuminate\Http\JsonResponse;
use Throwable;

class MetarController extends Controller
{
    public function __construct(
        protected SmnMetarService $smn,
        protected MetarEnricher $enricher,
        protected AirportResolver $airports,
    ) {}

    /**
     * GET /api/v1/metar?aerodromo=EZE[&decode=false]
     *
     * Accepts the same identifiers as the NOTAM endpoint — ANAC code ("EZE")
     * or ICAO code ("SAEZ") — and resolves whichever it gets to the ICAO code
     * the SMN indexes observations by.
     */
    public function index(MetarIndexRequest $request): JsonResponse
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
                'message' => "El aeródromo '{$indicator}' no tiene código OACI, por lo que el SMN no publica METAR para él.",
                'aerodromo' => $indicator,
                'nombre' => $this->airports->nameFor($indicator),
            ], 404);
        }

        try {
            $metars = $this->smn->getMetars($icao);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo obtener la información de METAR del SMN en este momento.',
            ], 502);
        }

        if ($request->wantsDecoding()) {
            $metars = $this->enricher->enrich($metars);
        }

        return response()->json([
            'aerodromo' => $indicator,
            'estacion' => $icao,
            'nombre' => $this->airports->nameFor($indicator),
            'cantidad' => count($metars),
            'metars' => MetarResource::collection($metars)->resolve(),
        ]);
    }
}
