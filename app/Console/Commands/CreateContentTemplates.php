<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Twilio\Rest\Client;
use Twilio\Rest\Content\V1\ContentModels;

/**
 * Registers the two content templates that let a WhatsApp message carry a
 * button, and prints their SIDs.
 *
 * Run once per Twilio account, by hand — the templates are account-level
 * resources with no natural key to upsert against, so re-running this creates
 * duplicates rather than updating anything. That is why it is a command you
 * invoke rather than something the scheduler does.
 *
 * The templates are never submitted for WhatsApp approval, and do not need to
 * be: approval is what buys the right to message someone out of the blue, and
 * both of these only ever go out inside the 24-hour window the user's own
 * message opened. That is also why each body is the bare "{{1}}" — WhatsApp
 * rejects a *submitted* template whose body starts or ends with a variable, and
 * being free of that rule keeps the rendered message byte-identical to the
 * plain-text one the bot sends when no template is registered.
 */
class CreateContentTemplates extends Command
{
    protected $signature = 'whatsapp:content-templates';

    protected $description = 'Create the Twilio content templates that carry the subscribe/unsubscribe buttons.';

    public function handle(): int
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');

        if (blank($sid) || blank($token)) {
            $this->error('Faltan credenciales de Twilio (TWILIO_ACCOUNT_SID / TWILIO_AUTH_TOKEN).');

            return self::FAILURE;
        }

        $client = new Client($sid, $token);

        $templates = [
            'TWILIO_CONTENT_SID_METAR' => [
                'friendly_name' => 'notams_metar_suscribir',
                'title' => '🔔 Avisarme 12 h',
                // The aerodrome the button acts on is substituted here at send
                // time and comes back to us verbatim as ButtonPayload, so the
                // tap needs no guessing about what the user meant.
                'id' => 'sub:{{2}}:12',
                'sample' => 'SAEZ',
            ],
            'TWILIO_CONTENT_SID_ALERT' => [
                'friendly_name' => 'notams_metar_baja',
                'title' => '🔕 Dar de baja',
                'id' => 'unsub:{{2}}',
                'sample' => 'SAEZ',
            ],
        ];

        $created = [];

        foreach ($templates as $envKey => $template) {
            try {
                // The model classes all live inside ContentModels.php, which
                // PSR-4 will only autoload under that one name — hence the
                // factory methods rather than `new QuickReplyAction(...)`.
                $content = $client->content->v1->contents->create(
                    ContentModels::createContentCreateRequest([
                        'friendly_name' => $template['friendly_name'],
                        'language' => 'es',
                        'variables' => ['1' => 'METAR SAEZ 271400Z 18008KT 9999 SCT020 22/14 Q1013', '2' => $template['sample']],
                        'types' => ContentModels::createTypes([
                            'twilio/quick-reply' => ContentModels::createTwilioQuickReply([
                                'body' => '{{1}}',
                                'actions' => [
                                    ContentModels::createQuickReplyAction([
                                        'type' => 'QUICK_REPLY',
                                        'title' => $template['title'],
                                        'id' => $template['id'],
                                    ]),
                                ],
                            ]),
                        ]),
                    ])
                );
            } catch (Throwable $e) {
                report($e);

                $this->error("No se pudo crear la plantilla {$template['friendly_name']}: {$e->getMessage()}");

                return self::FAILURE;
            }

            $created[$envKey] = $content->sid;
            $this->info("{$template['friendly_name']}: {$content->sid}");
        }

        $this->newLine();
        $this->line('Pegá esto en tu .env:');
        $this->newLine();

        foreach ($created as $envKey => $contentSid) {
            $this->line("{$envKey}={$contentSid}");
        }

        return self::SUCCESS;
    }
}
