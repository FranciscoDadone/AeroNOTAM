<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class NotamIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
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
     * Whether to run the (slow, paid) Spanish decoding. Defaults to true so
     * the endpoint's existing behavior is unchanged for current callers.
     */
    public function wantsDecoding(): bool
    {
        return $this->boolean('decode', true);
    }
}
