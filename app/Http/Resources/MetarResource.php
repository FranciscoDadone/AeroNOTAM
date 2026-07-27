<?php

namespace App\Http\Resources;

use App\DataObjects\Metar;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Metar $resource
 */
class MetarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'estacion' => $this->resource->station,
            'nombre' => $this->resource->airportName,
            'observado' => $this->resource->observedAt,
            'especial' => $this->resource->isSpeci(),

            // Who actually answered: "smn", or "noaa" when the SMN was
            // unreachable and the same report came through the international
            // exchange instead.
            'fuente' => $this->resource->source,

            // The report verbatim, in the international standard form.
            'metar' => $this->resource->raw,

            // One plain-Spanish line per decoded group, in report order, so a
            // client can render them as a list or join them into a paragraph.
            'explicacion' => $this->resource->explanation,
        ];
    }
}
