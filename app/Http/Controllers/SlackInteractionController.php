<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessGpsValidationFileJob;
use App\Services\SlackApi;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Riceve le interazioni Slack (submission di modali, click su elementi
 * interattivi). Oggi gestisce solo la submission della modale di
 * /gps-valida (callback_id "gps_valida_submit").
 *
 * Il payload arriva come campo form "payload" contenente JSON, non come
 * corpo JSON diretto — comportamento standard delle interactions Slack.
 */
class SlackInteractionController extends Controller
{
    public function handle(Request $request): Response
    {
        $payload = json_decode((string) $request->input('payload', '{}'), true) ?: [];

        if (($payload['type'] ?? null) !== 'view_submission') {
            return response('', 200);
        }

        $view = $payload['view'] ?? [];
        if (($view['callback_id'] ?? null) !== 'gps_valida_submit') {
            return response('', 200);
        }

        $userId    = (string) ($payload['user']['id'] ?? '');
        $channelId = (string) ($view['private_metadata'] ?? '');
        $values    = $view['state']['values'] ?? [];

        $docType = (string) ($values['doc_type_block']['doc_type_select']['selected_option']['value'] ?? '');
        $files   = $values['file_block']['file_upload']['files'] ?? [];
        $file    = $files[0] ?? null;

        if ($userId === '' || $docType === '' || !$file) {
            Log::warning('Submission /gps-valida incompleta', ['payload' => $payload]);
            return response()->json([
                'response_action' => 'errors',
                'errors' => ['file_block' => 'Seleziona un tipo di documento e allega un PDF.'],
            ]);
        }

        ProcessGpsValidationFileJob::dispatch(
            userId: $userId,
            channelId: $channelId,
            docType: $docType,
            fileUrl: (string) ($file['url_private'] ?? ''),
            fileName: (string) ($file['name'] ?? 'documento.pdf'),
        );

        // La modale si chiude subito ma senza questo messaggio non c'è nessun
        // segnale visibile finché non arriva il feedback vero (che può metterci
        // decine di secondi) — a differenza di /gps-domanda, che ha l'ack
        // immediato integrato nella risposta allo slash command.
        if ($channelId !== '') {
            try {
                (new SlackApi())->postMessage($channelId, "<@{$userId}> sto validando il documento, un attimo… :hourglass_flowing_sand:");
            } catch (Throwable $e) {
                Log::error('Ack /gps-valida fallito', ['err' => $e->getMessage()]);
            }
        }

        // Corpo vuoto = chiude la modale senza errori.
        return response('', 200);
    }
}
