<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Rag\DocumentTypes;
use App\Rag\GpsDocumentValidator;
use App\Rag\PrivacyRedactor;
use App\Rag\SmalotPdfReader;
use App\Rag\TranslateToItalian;
use App\Rag\ValidationFeedback;
use App\Services\SlackApi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

/**
 * Scarica il PDF allegato dalla modale di /gps-valida, ne estrae il testo,
 * lo valida con GpsDocumentValidator (filtrato sul tipo di documento scelto
 * dallo studente) e manda il feedback nello stesso canale da cui è stato
 * lanciato il comando (channelId arriva dalla modale via private_metadata,
 * vedi SlackCommandController). Se per qualche motivo non è disponibile,
 * usa un messaggio diretto come fallback.
 */
class ProcessGpsValidationFileJob implements ShouldQueue
{
    use Queueable;

    // Con il timeout Ollama alzato a 180s (vedi GpsDocumentValidator::provider())
    // e fino a 2 tentativi pieni in validateWithRetry(), il caso peggiore è
    // ~360s solo per la validazione: il timeout del job deve starci comodo.
    public int $timeout = 450;
    public int $tries = 1;

    public function __construct(
        private readonly string $userId,
        private readonly string $channelId,
        private readonly string $docType,
        private readonly string $fileUrl,
        private readonly string $fileName,
    ) {
    }

    public function handle(SlackApi $slack): void
    {
        $channel = $this->channelId !== '' ? $this->channelId : $slack->openDirectMessage($this->userId);
        $mention = "<@{$this->userId}> ";

        try {
            $tmpPath = tempnam(sys_get_temp_dir(), 'gps-valida-') . '.pdf';
            file_put_contents($tmpPath, $slack->downloadFile($this->fileUrl));

            $text = SmalotPdfReader::getText($tmpPath);
            @unlink($tmpPath);

            if (trim($text) === '') {
                $slack->postMessage($channel, "{$mention}Non sono riuscito a estrarre testo da \"{$this->fileName}\" — è un PDF scansionato senza testo selezionabile? Prova a caricarne uno con testo vero.");
                return;
            }

            $feedback = $this->validateWithRetry($text);
            $feedback = $this->translateIfNeeded($feedback);

            // Il tipo di documento lo mostriamo da DocumentTypes (quello scelto
            // dallo studente nella dropdown), MAI da $feedback->documentType:
            // quel campo, generato dal modello, si è dimostrato inaffidabile —
            // in un test è arrivato con un intero verbale inventato dentro,
            // nomi di persone compresi, mai comparsi nel documento originale.
            $docTypeLabel = DocumentTypes::label($this->docType);
            $body = PrivacyRedactor::redact($this->formatFeedback($feedback, $docTypeLabel));

            $slack->postMessage($channel, "{$mention}*Documento validato:* {$this->fileName} ({$docTypeLabel})\n\n{$body}");
        } catch (Throwable $e) {
            Log::error('ProcessGpsValidationFileJob fallito', ['err' => $e->getMessage()]);
            $slack->postMessage($channel, "{$mention}Si è verificato un errore mentre validavo il documento. Riprova tra qualche minuto.");
        }
    }

    /**
     * Due motivi distinti per ritentare:
     * 1. Il modello, su questo compito, è non deterministico: a volte torna
     *    tutti e tre i campi vuoti anche su documenti chiaramente carenti
     *    (visto più volte nei test, stesso input, esiti diversi).
     * 2. Su documenti reali più lunghi, la chiamata a Ollama può occasionalmente
     *    andare in timeout (visto in produzione su una minuta reale) — un
     *    secondo tentativo, invece di arrendersi subito, spesso basta.
     * In entrambi i casi un secondo tentativo pieno spesso sblocca la
     * situazione — non è una garanzia, ma riduce parecchio la frequenza del
     * problema. Solo se anche l'ultimo tentativo fallisce, l'eccezione risale
     * al chiamante (che mostra il messaggio di errore generico allo studente).
     */
    private function validateWithRetry(string $text, int $maxAttempts = 2): ValidationFeedback
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                /** @var ValidationFeedback $feedback */
                $feedback = GpsDocumentValidator::make()
                    ->forDocumentType($this->docType)
                    ->structured(new UserMessage($text), ValidationFeedback::class);
            } catch (Throwable $e) {
                if ($attempt === $maxAttempts) {
                    throw $e;
                }
                Log::warning('ProcessGpsValidationFileJob: chiamata fallita, ritento', ['attempt' => $attempt, 'err' => $e->getMessage()]);
                continue;
            }

            $allEmpty = $feedback->structuralErrors === []
                && $feedback->missingElements === []
                && $feedback->suggestions === [];

            if (!$allEmpty || $attempt === $maxAttempts) {
                return $feedback;
            }

            Log::warning('ProcessGpsValidationFileJob: risposta vuota, ritento', ['attempt' => $attempt]);
        }

        return $feedback;
    }

    /**
     * Rete di sicurezza contro il modello che risponde in inglese (visto
     * ripetutamente oggi, anche con istruzioni esplicite nello schema).
     *
     * Traduce SOLO i campi generati dal modello, uno per uno — mai il
     * messaggio finale già assemblato con le nostre etichette fisse
     * ("*Tipo di documento:*" ecc.), altrimenti la traduzione le riscrive
     * insieme al resto e il formato si rompe (visto nei test di oggi).
     */
    private function translateIfNeeded(ValidationFeedback $feedback): ValidationFeedback
    {
        // documentType non viene tradotto: non lo mostriamo mai (vedi handle()).
        $sample = implode(' ', [...$feedback->structuralErrors, ...$feedback->missingElements, ...$feedback->suggestions]);
        if (trim($sample) === '' || TranslateToItalian::looksItalian($sample)) {
            return $feedback;
        }

        $translateEach = fn (array $items) => array_map(fn (string $i) => TranslateToItalian::translate($i), $items);

        return new ValidationFeedback(
            documentType: $feedback->documentType,
            comparisonReasoning: $feedback->comparisonReasoning,
            presentElements: $feedback->presentElements,
            structuralErrors: $translateEach($feedback->structuralErrors),
            missingElements: $translateEach($feedback->missingElements),
            suggestions: $translateEach($feedback->suggestions),
        );
    }

    private function formatFeedback(ValidationFeedback $feedback, string $docTypeLabel): string
    {
        $bullets = fn (array $items) => $items === []
            ? '• Nessuno rilevato'
            : implode("\n", array_map(fn (string $i) => "• {$i}", $items));

        return implode("\n", [
            "*Tipo di documento:* {$docTypeLabel}",
            '*Errori strutturali:*',
            $bullets($feedback->structuralErrors),
            '*Elementi mancanti:*',
            $bullets($feedback->missingElements),
            '*Suggerimenti:*',
            $bullets($feedback->suggestions),
        ]);
    }
}
