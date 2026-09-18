<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Chiamate autenticate (Bot Token) verso le API di Slack, per tutto ciò che
 * il response_url degli slash command non può fare: aprire una modale,
 * scaricare un file allegato, mandare un messaggio diretto allo studente.
 */
class SlackApi
{
    private Client $client;

    public function __construct()
    {
        $token = (string) config('services.slack.notifications.bot_user_oauth_token');
        if ($token === '') {
            throw new RuntimeException('SLACK_BOT_USER_OAUTH_TOKEN non configurato.');
        }

        $this->client = new Client([
            'base_uri' => 'https://slack.com/api/',
            'timeout'  => 15,
            'headers'  => ['Authorization' => "Bearer {$token}"],
        ]);
    }

    /** Apre una modale a partire dal trigger_id (valido ~3 secondi, va chiamato subito). */
    public function openModal(string $triggerId, array $view): void
    {
        $this->post('views.open', ['trigger_id' => $triggerId, 'view' => $view]);
    }

    /**
     * Scarica il contenuto di un file Slack (richiede il Bot Token, url_private
     * non è pubblico). Riprova fino a 3 volte: il resolver DNS di questo Mac
     * ha mostrato di fallire in modo intermittente sul primo tentativo verso
     * files.slack.com e riuscire subito dopo — un breve retry assorbe il
     * problema senza bisogno di intervento manuale.
     */
    public function downloadFile(string $urlPrivate, int $maxAttempts = 3): string
    {
        $token = (string) config('services.slack.notifications.bot_user_oauth_token');

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->client->get($urlPrivate, [
                    'headers' => ['Authorization' => "Bearer {$token}"],
                    'base_uri' => null,
                ]);
                return (string) $response->getBody();
            } catch (\Throwable $e) {
                Log::warning('SlackApi: download file fallito, ritento', ['attempt' => $attempt, 'err' => $e->getMessage()]);
                if ($attempt === $maxAttempts) {
                    throw $e;
                }
                sleep(2);
            }
        }

        throw new RuntimeException('downloadFile: irraggiungibile.'); // mai eseguito, soddisfa il return-type
    }

    /** Apre (o recupera) la DM con uno studente e restituisce l'id del canale. */
    public function openDirectMessage(string $userId): string
    {
        $body = $this->post('conversations.open', ['users' => $userId]);
        return (string) ($body['channel']['id'] ?? '');
    }

    public function postMessage(string $channel, string $text, ?array $blocks = null): void
    {
        $payload = ['channel' => $channel, 'text' => $text];
        if ($blocks !== null) {
            $payload['blocks'] = $blocks;
        }
        $this->post('chat.postMessage', $payload);
    }

    /**
     * Stesso retry di downloadFile(): il resolver DNS di questo Mac fallisce
     * in modo intermittente anche su slack.com stesso (non solo su
     * files.slack.com), e si risolve da solo dopo un breve tentativo.
     *
     * @return array<string, mixed>
     */
    private function post(string $method, array $json, int $maxAttempts = 3): array
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->client->post($method, ['json' => $json]);
                break;
            } catch (\Throwable $e) {
                Log::warning('SlackApi: richiesta fallita, ritento', ['method' => $method, 'attempt' => $attempt, 'err' => $e->getMessage()]);
                if ($attempt === $maxAttempts) {
                    throw $e;
                }
                sleep(2);
            }
        }

        $body = json_decode((string) $response->getBody(), true) ?: [];

        if (($body['ok'] ?? false) !== true) {
            Log::error("SlackApi: {$method} fallita", ['response' => $body]);
        }

        return $body;
    }
}
