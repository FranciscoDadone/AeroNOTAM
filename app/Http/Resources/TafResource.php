<?php

namespace App\Http\Resources;

use App\DataObjects\Taf;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Taf $resource
 */
class TafResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'estacion' => $this->resource->station,
            'nombre' => $this->resource->airportName,
            'emitido' => $this->resource->issuedAt,

            // An amendment replaces the forecast that was in force, and a
            // cancellation withdraws it without replacing it. Both change what
            // the text means, so neither is left for the reader to spot.
            'enmendado' => $this->resource->isAmended(),
            'cancelado' => $this->resource->isCancelled(),

            // Who actually answered: "smn", or "noaa" when the SMN was
            // unreachable and the same forecast came through the international
            // exchange instead.
            'fuente' => $this->resource->source,

            // The forecast verbatim, in the international standard form.
            'taf' => $this->resource->raw,

            // One plain-Spanish line per decoded group, in report order, so a
            // client can render them as a list or join them into a paragraph.
            'explicacion' => $this->resource->explanation,
        ];
    }
}
