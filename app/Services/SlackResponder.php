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
    public function send(string $responseUrl, string $text, string $responseType = 'ephemeral'): void
    {
        try {
            (new Client(['timeout' => 15]))->post($responseUrl, [
                'json' => [
                    'response_type' => $responseType,
                    'text'          => $text,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('SlackResponder: invio a response_url fallito', ['err' => $e->getMessage()]);
        }
    }
}
