<?php

namespace Database\Factories;

use App\Models\WhatsappMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappMessage>
 */
class WhatsappMessageFactory extends Factory
{
    protected $model = WhatsappMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone' => 'whatsapp:+549'.fake()->numerify('##########'),
            'profile_name' => fake()->firstName(),
            'message_sid' => 'SM'.fake()->lexify('????????????'),
            'body' => 'notams ezeiza',
            'topic' => 'notam',
            'anac_code' => 'EZE',
            'icao_code' => 'SAEZ',
            'status' => WhatsappMessage::STATUS_ANSWERED,
            'reply' => ['Sin NOTAM activos para EZEIZA.'],
            'duration_ms' => fake()->numberBetween(400, 9000),
        ];
    }

    public function pending(): self
    {
        return $this->state(fn () => [
            'topic' => null,
            'anac_code' => null,
            'icao_code' => null,
            'status' => WhatsappMessage::STATUS_PENDING,
            'reply' => null,
            'duration_ms' => null,
        ]);
    }

    public function unmatched(): self
    {
        return $this->state(fn () => [
            'body' => 'hola, cómo andás?',
            'anac_code' => null,
            'icao_code' => null,
        ]);
    }
}
