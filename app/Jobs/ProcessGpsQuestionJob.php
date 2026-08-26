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

            $responder->send($this->responseUrl, $answer);
        } catch (Throwable $e) {
            Log::error('ProcessGpsQuestionJob fallito', ['err' => $e->getMessage()]);
            $responder->send(
                $this->responseUrl,
                'Si è verificato un errore mentre elaboravo la tua domanda. Riprova tra qualche minuto.'
            );
        }
    }
}
