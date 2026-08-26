<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Rag\GpsDocumentValidator;
use App\Services\SlackResponder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

/**
 * Esegue la pipeline di validazione per /gps-valida e pubblica il feedback
 * su Slack tramite response_url (stesso motivo di ProcessGpsQuestionJob:
 * il modello può metterci più dei 3 secondi concessi da Slack).
 */
class ProcessGpsValidationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        private readonly string $documentText,
        private readonly string $responseUrl,
    ) {
    }

    public function handle(SlackResponder $responder): void
    {
        try {
            $feedback = GpsDocumentValidator::make()
                ->chat(new UserMessage($this->documentText))
                ->getMessage()
                ->getContent() ?? '';

            $responder->send($this->responseUrl, $feedback);
        } catch (Throwable $e) {
            Log::error('ProcessGpsValidationJob fallito', ['err' => $e->getMessage()]);
            $responder->send(
                $this->responseUrl,
                'Si è verificato un errore mentre validavo il documento. Riprova tra qualche minuto.'
            );
        }
    }
}
