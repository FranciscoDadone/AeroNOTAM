<?php

namespace App\Jobs;

use App\Services\WhatsappNotamBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;
use Twilio\Rest\Client;

class ProcessWhatsappNotamMessage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $from,
        protected string $body,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WhatsappNotamBotService $bot): void
    {
        try {
            $messages = $bot->reply($this->body);
        } catch (Throwable $e) {
            report($e);

            $messages = ['Tuve un problema procesando tu consulta. Probá de nuevo en unos minutos.'];
        }

        $client = new Client(
            config('services.twilio.sid'),
            config('services.twilio.token'),
        );

        foreach ($messages as $message) {
            $client->messages->create($this->from, [
                'from' => config('services.twilio.whatsapp_from'),
                'body' => $message,
            ]);
        }
    }
}
