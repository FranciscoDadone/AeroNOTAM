<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Twilio\Rest\Client;
use Twilio\Rest\Content\V1\ContentModels;

/**
 * Registers the content templates that let a WhatsApp message carry buttons,
 * and prints their SIDs.
 *
 * Eight of them: subscribe under a METAR — alongside the runway-wind offer, the
 * one message that carries two actions — that same offer on its own for a
 * reader who is already subscribed, unsubscribe under an alert, "Consultar
 * AEROMET" under a METAR that came back empty, and one follow-up menu per
 * question topic (NOTAM, METAR, TAF, crepúsculo) offering the other three.
 *
 * WhatsApp renders at most three quick replies on a message, which is the whole
 * reason the shape is what it is: the menus are already at three, so a fifth
 * topic could not be added to them and the runway-wind offer went onto the
 * METAR itself, where there was room. PRONAREA has no
 * template of its own: it is not offered as a quick-reply action, by design
 * (see ReplyButton::MENU_OFFERS). There is one menu template per topic
 * rather than a single shared one because a quick-reply template's captions
 * and action ids are fixed when it is registered — the only thing that can
 * vary at send time is the aerodrome, substituted into {{2}} — so a menu
 * that never re-offers the topic it follows needs its own template per
 * topic.
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

    protected $description = 'Create the Twilio content templates that carry the bot\'s buttons and follow-up menus.';

    public function handle(): int
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');

        if (blank($sid) || blank($token)) {
            $this->error('Faltan credenciales de Twilio (TWILIO_ACCOUNT_SID / TWILIO_AUTH_TOKEN).');

            return self::FAILURE;
        }

        $client = new Client($sid, $token);

        $created = [];

        foreach ($this->templates() as $envKey => $template) {
            $actions = array_map(
                fn (array $action) => ContentModels::createQuickReplyAction([
                    'type' => 'QUICK_REPLY',
                    'title' => $action['title'],
                    'id' => $action['id'],
                ]),
                $template['actions'],
            );

            try {
                // The model classes all live inside ContentModels.php, which
                // PSR-4 will only autoload under that one name — hence the
                // factory methods rather than `new QuickReplyAction(...)`.
                $content = $client->content->v1->contents->create(
                    ContentModels::createContentCreateRequest([
                        'friendly_name' => $template['friendly_name'],
                        'language' => 'es',
                        'variables' => ['1' => $template['body_sample'], '2' => $template['sample']],
                        'types' => ContentModels::createTypes([
                            'twilio/quick-reply' => ContentModels::createTwilioQuickReply([
                                'body' => '{{1}}',
                                'actions' => $actions,
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

    /**
     * The templates to register, keyed by the .env variable their SID belongs
     * in. Public so a test can check the action ids here against the grammar
     * WhatsappBotService::BUTTON_ASK expects — nothing at runtime ties the two
     * together otherwise.
     *
     * @return array<string, array{friendly_name: string, body_sample: string, sample: string, actions: array<int, array{title: string, id: string}>}>
     */
    public function templates(): array
    {
        $menuSample = '¿Querés algo más de EZEIZA / MINISTRO PISTARINI?';

        return [
            'TWILIO_CONTENT_SID_METAR' => [
                'friendly_name' => 'notams_metar_suscribir',
                'body_sample' => 'METAR SAEZ 271400Z 18008KT 9999 SCT020 22/14 Q1013',
                'sample' => 'SAEZ',
                'actions' => [
                    // The aerodrome the button acts on is substituted here at
                    // send time and comes back to us verbatim as
                    // ButtonPayload, so the tap needs no guessing about what
                    // the user meant. The twelve is baked into the id rather
                    // than passed at send time, because it is also the
                    // caption on the button — the two must not drift apart.
                    ['title' => '🔔 Avisarme 12 h', 'id' => 'sub:{{2}}:12'],

                    // The wind components ride on the METAR itself rather than
                    // on the follow-up menu, because the menu is already at the
                    // three quick replies WhatsApp will render and because this
                    // is a question about the report just sent, not a change of
                    // subject. Both actions substitute the same {{2}}.
                    ['title' => '🛬 Viento en pista', 'id' => 'pista:{{2}}'],
                ],
            ],
            'TWILIO_CONTENT_SID_PISTA' => [
                'friendly_name' => 'notams_metar_pista',
                'body_sample' => 'METAR SAEZ 271400Z 18008KT 9999 SCT020 22/14 Q1013',
                'sample' => 'SAEZ',
                'actions' => [
                    // The same offer alone, for the METAR of an aerodrome the
                    // reader already watches: the template above cannot be sent
                    // there, because its other button would promise something
                    // that is already true.
                    ['title' => '🛬 Viento en pista', 'id' => 'pista:{{2}}'],
                ],
            ],
            'TWILIO_CONTENT_SID_ALERT' => [
                'friendly_name' => 'notams_metar_baja',
                'body_sample' => 'METAR SAEZ 271400Z 18008KT 9999 SCT020 22/14 Q1013',
                'sample' => 'SAEZ',
                'actions' => [
                    ['title' => '🔕 Dar de baja', 'id' => 'unsub:{{2}}'],
                ],
            ],
            'TWILIO_CONTENT_SID_AEROMET' => [
                'friendly_name' => 'notams_metar_aeromet',
                'body_sample' => 'No hay METAR publicado para *JUNÍN* (SAAJ) en este momento.',
                'sample' => '87548',
                'actions' => [
                    // The WMO/OMM code, not the aerodrome's own — AEROMET
                    // indexes by its own station list, and the tap goes
                    // straight to AerometService with it (BUTTON_AEROMET).
                    ['title' => 'Consultar AEROMET', 'id' => 'aeromet:{{2}}'],
                ],
            ],
            'TWILIO_CONTENT_SID_MENU_NOTAM' => [
                'friendly_name' => 'notams_menu_notam',
                'body_sample' => $menuSample,
                'sample' => 'SAEZ',
                'actions' => [
                    ['title' => '🌦️ METAR', 'id' => 'ask:metar:{{2}}'],
                    ['title' => '🔭 TAF', 'id' => 'ask:taf:{{2}}'],
                    ['title' => '🌅 Salida/Puesta sol', 'id' => 'ask:crepusculo:{{2}}'],
                ],
            ],
            'TWILIO_CONTENT_SID_MENU_METAR' => [
                'friendly_name' => 'notams_menu_metar',
                'body_sample' => $menuSample,
                'sample' => 'SAEZ',
                'actions' => [
                    ['title' => '✈️ NOTAMs', 'id' => 'ask:notam:{{2}}'],
                    ['title' => '🔭 TAF', 'id' => 'ask:taf:{{2}}'],
                    ['title' => '🌅 Salida/Puesta sol', 'id' => 'ask:crepusculo:{{2}}'],
                ],
            ],
            'TWILIO_CONTENT_SID_MENU_TAF' => [
                'friendly_name' => 'notams_menu_taf',
                'body_sample' => $menuSample,
                'sample' => 'SAEZ',
                'actions' => [
                    ['title' => '✈️ NOTAMs', 'id' => 'ask:notam:{{2}}'],
                    ['title' => '🌦️ METAR', 'id' => 'ask:metar:{{2}}'],
                    ['title' => '🌅 Salida/Puesta sol', 'id' => 'ask:crepusculo:{{2}}'],
                ],
            ],
            'TWILIO_CONTENT_SID_MENU_CREPUSCULO' => [
                'friendly_name' => 'notams_menu_crepusculo',
                'body_sample' => $menuSample,
                'sample' => 'SAEZ',
                'actions' => [
                    ['title' => '✈️ NOTAMs', 'id' => 'ask:notam:{{2}}'],
                    ['title' => '🌦️ METAR', 'id' => 'ask:metar:{{2}}'],
                    ['title' => '🔭 TAF', 'id' => 'ask:taf:{{2}}'],
                ],
            ],
        ];
    }
}
