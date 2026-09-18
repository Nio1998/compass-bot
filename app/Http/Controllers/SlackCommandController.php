<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessGpsQuestionJob;
use App\Rag\DocumentTypes;
use App\Services\SlackApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Riceve gli slash command Slack /gps-domanda e /gps-valida.
 *
 * /gps-domanda: ack immediato ("sto elaborando…") + Job in coda che pubblica
 * la risposta vera tramite il response_url, perché Ollama può metterci più
 * dei 3 secondi che Slack concede per l'ack immediato dello slash command.
 *
 * /gps-valida: apre una modale (tipo di documento + upload PDF) invece di
 * aspettarsi testo incollato — la sottomissione arriva su un endpoint
 * diverso, /slack/interactions (vedi SlackInteractionController).
 */
class SlackCommandController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $command     = (string) $request->input('command', '');
        $text        = trim((string) $request->input('text', ''));
        $responseUrl = (string) $request->input('response_url', '');
        $triggerId   = (string) $request->input('trigger_id', '');

        if ($command === '/gps-valida') {
            $channelId = (string) $request->input('channel_id', '');
            try {
                (new SlackApi())->openModal($triggerId, $this->validationModalView($channelId));
            } catch (Throwable $e) {
                Log::error('Apertura modale /gps-valida fallita', ['err' => $e->getMessage()]);
                return $this->ack('Non sono riuscito ad aprire il modulo di validazione. Riprova tra qualche minuto.');
            }
            return response()->json([], 200);
        }

        if ($text === '') {
            return $this->ack($this->usageHintFor($command));
        }

        match ($command) {
            '/gps-domanda' => ProcessGpsQuestionJob::dispatch($text, $responseUrl),
            default        => null,
        };

        return $this->ack('Sto elaborando la tua richiesta, un attimo… :hourglass_flowing_sand:');
    }

    /** @return array<string, mixed> */
    private function validationModalView(string $channelId): array
    {
        $options = array_map(
            fn (string $value, string $label) => [
                'text'  => ['type' => 'plain_text', 'text' => $label],
                'value' => $value,
            ],
            array_keys(DocumentTypes::options()),
            array_values(DocumentTypes::options()),
        );

        return [
            'type'             => 'modal',
            'callback_id'      => 'gps_valida_submit',
            // Slack ripropone questo valore invariato nella submission — lo usiamo
            // per sapere in quale canale rispondere (la modale di per sé non è
            // legata a un canale).
            'private_metadata' => $channelId,
            'title'       => ['type' => 'plain_text', 'text' => 'Valida documento'],
            'submit'      => ['type' => 'plain_text', 'text' => 'Invia'],
            'close'       => ['type' => 'plain_text', 'text' => 'Annulla'],
            'blocks'      => [
                [
                    'type'     => 'input',
                    'block_id' => 'doc_type_block',
                    'label'    => ['type' => 'plain_text', 'text' => 'Tipo di documento'],
                    'element'  => [
                        'type'        => 'static_select',
                        'action_id'   => 'doc_type_select',
                        'placeholder' => ['type' => 'plain_text', 'text' => 'Scegli il tipo'],
                        'options'     => $options,
                    ],
                ],
                [
                    'type'     => 'input',
                    'block_id' => 'file_block',
                    'label'    => ['type' => 'plain_text', 'text' => 'Documento (PDF)'],
                    'element'  => [
                        'type'      => 'file_input',
                        'action_id' => 'file_upload',
                        'filetypes' => ['pdf'],
                        'max_files' => 1,
                    ],
                ],
            ],
        ];
    }

    private function usageHintFor(string $command): string
    {
        return match ($command) {
            '/gps-domanda' => 'Scrivi una domanda dopo il comando, es: `/gps-domanda Cosa si intende per WBS?`',
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
