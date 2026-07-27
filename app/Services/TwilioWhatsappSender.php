<?php

namespace App\Services;

use App\Contracts\WhatsappSender;
use RuntimeException;
use Twilio\Rest\Client;

class TwilioWhatsappSender implements WhatsappSender
{
    protected ?Client $client = null;

    public function send(string $to, string $body): void
    {
        $this->client()->messages->create($to, [
            'from' => config('services.twilio.whatsapp_from'),
            'body' => $body,
        ]);
    }

    public function indicateTyping(string $inboundMessageId): void
    {
        $this->client()->messaging->v2->typingIndicator->create('whatsapp', $inboundMessageId);
    }

    /**
     * Built lazily so that merely resolving this class — which the container
     * does for anything type-hinting WhatsappSender — doesn't require Twilio
     * credentials to be present.
     */
    protected function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');

        if (blank($sid) || blank($token)) {
            throw new RuntimeException('Twilio credentials are not configured (TWILIO_ACCOUNT_SID / TWILIO_AUTH_TOKEN).');
        }

        return $this->client = new Client($sid, $token);
    }
}
