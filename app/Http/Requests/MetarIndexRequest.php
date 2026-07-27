<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MetarIndexRequest extends FormRequest
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
     * Whether to include the plain-Spanish explanation. Unlike the NOTAM
     * endpoint's equivalent this costs nothing — the METAR decoder is offline
     * and deterministic — but it stays available for callers that only want
     * the raw report.
     */
    public function wantsDecoding(): bool
    {
        return $this->boolean('decode', true);
    }
}
