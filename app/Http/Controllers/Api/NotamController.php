<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotamIndexRequest;
use App\Services\AnacNotamService;
use Illuminate\Http\JsonResponse;
use Throwable;

class NotamController extends Controller
{
    public function __construct(protected AnacNotamService $anac) {}

    /**
     * GET /api/notams?aerodromo=EZE
     */
    public function index(NotamIndexRequest $request): JsonResponse
    {
        $indicator = $this->anac->resolveIndicator($request->indicator());

        try {
            $knownAirports = $this->anac->getKnownAirports();
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo contactar al servicio de NOTAM de ANAC en este momento.',
            ], 502);
        }

        if (! array_key_exists($indicator, $knownAirports)) {
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
                'message' => 'No se pudo obtener la información de ANAC o generar la decodificación por IA en este momento.',
            ], 502);
        }

        return response()->json([
            'aerodromo' => $indicator,
            'nombre' => $knownAirports[$indicator],
            'cantidad' => count($notams),
            'notams' => $notams,
        ]);
    }

    /**
     * GET /api/notams/aerodromos
     *
     * Lists the place indicators ANAC currently reports as having active NOTAMs.
     */
    public function aerodromos(): JsonResponse
    {
        try {
            $validIndicators = $this->anac->getValidIndicators();
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'No se pudo contactar al servicio de NOTAM de ANAC en este momento.',
            ], 502);
        }

        $aerodromos = collect($validIndicators)
            ->map(fn (string $nombre, string $codigo) => ['codigo' => $codigo, 'nombre' => $nombre])
            ->values();

        return response()->json([
            'cantidad' => $aerodromos->count(),
            'aerodromos' => $aerodromos,
        ]);
    }
}
