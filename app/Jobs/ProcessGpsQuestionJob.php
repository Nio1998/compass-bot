<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Rag\GpsQaBot;
use App\Services\SlackResponder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

/**
 * Esegue la pipeline RAG per /gps-domanda e pubblica la risposta su Slack
 * tramite response_url. Girato in coda perché Ollama può metterci più dei
 * 3 secondi che Slack concede per l'ack immediato dello slash command.
 */
class ProcessGpsQuestionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        private readonly string $question,
        private readonly string $responseUrl,
    ) {
    }

    public function handle(SlackResponder $responder): void
    {
        try {
            $answer = GpsQaBot::make()
                ->chat(new UserMessage($this->question))
                ->getMessage()
                ->getContent() ?? '';

            // La domanda digitata dallo studente non compare mai come messaggio
            // Slack (comportamento standard degli slash command): la ripetiamo
            // qui così resta un riferimento leggibile di cosa è stato chiesto.
            $fallbackText = "Hai chiesto: {$this->question}\n\n{$answer}";
            $blocks = [
                [
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => "*Hai chiesto:*\n{$this->question}"],
                ],
                ['type' => 'divider'],
                [
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => $answer],
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        ['type' => 'mrkdwn', 'text' => '🧭 *CompassBot* · Corso GPS'],
                    ],
                ],
            ];

            $responder->send($this->responseUrl, $fallbackText, 'in_channel', $blocks);
        } catch (Throwable $e) {
            Log::error('ProcessGpsQuestionJob fallito', ['err' => $e->getMessage()]);
            $responder->send(
                $this->responseUrl,
                'Si è verificato un errore mentre elaboravo la tua domanda. Riprova tra qualche minuto.'
            );
        }
    }
}
