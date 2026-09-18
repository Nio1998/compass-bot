<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Invia la risposta finale di un Job asincrono verso il `response_url` che
 * Slack fornisce nel payload di uno slash command. Il response_url è valido
 * 30 minuti e accetta fino a 5 messaggi: è il meccanismo standard di Slack
 * per rispondere dopo l'ack immediato dei 3 secondi.
 * https://api.slack.com/interactivity/handling#message_responses
 */
class SlackResponder
{
    /**
     * @param array<int, array<string, mixed>>|null $blocks Block Kit opzionale;
     *   $text resta comunque il fallback mostrato nelle notifiche e nei client
     *   che non renderizzano i blocchi.
     */
    public function send(string $responseUrl, string $text, string $responseType = 'ephemeral', ?array $blocks = null): void
    {
        $payload = [
            'response_type' => $responseType,
            'text'          => $text,
        ];
        if ($blocks !== null) {
            $payload['blocks'] = $blocks;
        }

        try {
            (new Client(['timeout' => 15]))->post($responseUrl, [
                'json' => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::error('SlackResponder: invio a response_url fallito', ['err' => $e->getMessage()]);
        }
    }
}
