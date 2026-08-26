<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessGpsQuestionJob;
use App\Jobs\ProcessGpsValidationJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Riceve gli slash command Slack /gps-domanda e /gps-valida.
 *
 * Slack impone una risposta HTTP entro 3 secondi allo slash command, ma la
 * pipeline RAG (Ollama) può metterci molto di più. Quindi qui si fa solo un
 * ack immediato (messaggio "sto elaborando…") e si accoda un Job che, una
 * volta pronta la risposta vera, la pubblica tramite il `response_url`
 * fornito da Slack nel payload.
 */
class SlackCommandController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $command     = (string) $request->input('command', '');
        $text        = trim((string) $request->input('text', ''));
        $responseUrl = (string) $request->input('response_url', '');

        if ($text === '') {
            return $this->ack($this->usageHintFor($command));
        }

        match ($command) {
            '/gps-domanda' => ProcessGpsQuestionJob::dispatch($text, $responseUrl),
            '/gps-valida'  => ProcessGpsValidationJob::dispatch($text, $responseUrl),
            default        => null,
        };

        return $this->ack('Sto elaborando la tua richiesta, un attimo… :hourglass_flowing_sand:');
    }

    private function usageHintFor(string $command): string
    {
        return match ($command) {
            '/gps-domanda' => 'Scrivi una domanda dopo il comando, es: `/gps-domanda Cosa si intende per WBS?`',
            '/gps-valida'  => 'Incolla il testo del documento dopo il comando, es: `/gps-valida <testo della tua WBS>`',
            default        => 'Comando non riconosciuto.',
        };
    }

    private function ack(string $text): JsonResponse
    {
        return response()->json([
            'response_type' => 'ephemeral',
            'text'          => $text,
        ]);
    }
}
