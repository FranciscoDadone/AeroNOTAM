<?php

namespace App\Http\Resources;

use App\DataObjects\Notam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Notam $resource
 */
class NotamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->resource->number,
            'location_code' => $this->resource->locationCode,
            'location_name' => $this->resource->locationName,
            'valid_from' => $this->resource->validFrom,
            'valid_until' => $this->resource->validUntil,
            'permanent' => $this->resource->permanent,
            'text_en' => $this->resource->textEn,
            'text_es' => $this->resource->textEs,
            'decoded_es' => $this->resource->decodedEs,

            // Lets a client tell a model-quality translation apart from the
            // blunter offline dictionary expansion: 'ai', 'dictionary', or null.
            'decoded_by' => $this->resource->decodedBy,
        ];
    }
}
