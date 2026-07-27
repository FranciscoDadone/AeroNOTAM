<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TafIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'aerodromo' => ['required', 'string', 'max:10'],
            'decode' => ['sometimes', 'in:true,false,1,0'],
        ];
    }

    public function indicator(): string
    {
        return strtoupper(trim($this->validated('aerodromo')));
    }

    /**
     * Whether to include the plain-Spanish explanation. Like the METAR
     * endpoint's equivalent this costs nothing — the TAF decoder is offline and
     * deterministic — but it stays available for callers that only want the raw
     * forecast.
     */
    public function wantsDecoding(): bool
    {
        return $this->boolean('decode', true);
    }
}
