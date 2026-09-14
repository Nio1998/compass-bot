<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Rag\DocumentSectionChecker;
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

            // Calcolati UNA VOLTA sul testo originale, prima di anteporre la
            // nota per il modello (altrimenti la nota stessa, contenendo i
            // nomi delle sezioni, farebbe "trovare" sezioni in realtà assenti
            // al ricalcolo successivo).
            $presentSections = DocumentSectionChecker::detectPresent($this->docType, $text);
            $missingSections = DocumentSectionChecker::detectMissing($this->docType, $text);
            $augmentedText = $this->withDetectedSections($text, $presentSections, $missingSections);

            $feedback = $this->validateWithRetry($augmentedText);
            $feedback = $this->filterFabricatedContent($feedback);
            $feedback = $this->translateIfNeeded($feedback);
            $feedback = $this->applySectionChecklist($feedback, $missingSections);

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
     * Antepone al testo del documento l'esito di un controllo deterministico
     * (nessun LLM) sulla presenza delle sezioni standard del template del
     * corso — per i tipi con un template a sezioni fisse note (vedi
     * DocumentSectionChecker). Il modello si è dimostrato inaffidabile nel
     * giudicare da solo se una sezione è presente (l'ha negato più volte su
     * sezioni chiaramente presenti); qui glielo diciamo noi, via codice, come
     * fatto già accertato che non può contraddire.
     */
    /**
     * @param string[] $present
     * @param string[] $missing
     */
    private function withDetectedSections(string $text, array $present, array $missing): string
    {
        // Se nessuna sezione del template del corso viene trovata, il
        // documento probabilmente segue una struttura diversa (non
        // necessariamente sbagliata — visto su un documento reale non
        // scritto per il corso). In quel caso segnalare TUTTE le sezioni
        // come "mancanti" ha fatto sì che il modello si limitasse a copiare
        // la lista invece di analizzare il contenuto reale del documento
        // (regressione osservata in test manuale). La nota sulle sezioni
        // mancanti scatta quindi solo quando c'è un'adesione almeno parziale
        // al template — lì il controllo aiuta davvero, senza sostituirsi al
        // giudizio del modello sul resto del contenuto.
        if ($present === []) {
            return $text;
        }

        $lines = ['[Controllo automatico via codice, non generato dal modello:'];
        $lines[] = 'sezioni RILEVATE come presenti nel testo: ' . implode(', ', $present) . '. Non puoi dichiararle mancanti o assenti.';
        if ($missing !== []) {
            $lines[] = 'sezioni del template del corso NON rilevate nel testo: ' . implode(', ', $missing) . '. Non serve che tu le riporti in "elementi mancanti" — verranno aggiunte automaticamente dal sistema — concentrati invece su "errori strutturali" e "suggerimenti" per il resto del documento.';
        }
        $note = implode(' ', $lines) . "]\n\n";

        return $note . $text;
    }

    /**
     * Aggiunge in coda a "elementi mancanti" le sezioni del template che il
     * controllo deterministico ha verificato assenti (vedi DocumentSectionChecker),
     * al posto di affidarsi al modello per riportarle: si è visto che tende a
     * duplicare la stessa cosa già scritta in "errori strutturali" invece di
     * limitarsi al nome della sezione mancante (osservato in un test reale).
     *
     * @param string[] $missingSections
     */
    private function applySectionChecklist(ValidationFeedback $feedback, array $missingSections): ValidationFeedback
    {
        if ($missingSections === []) {
            return $feedback;
        }

        $missingElements = $feedback->missingElements;
        foreach ($missingSections as $section) {
            $alreadyMentioned = false;
            foreach ($missingElements as $existing) {
                if (mb_stripos($existing, $section) !== false) {
                    $alreadyMentioned = true;
                    break;
                }
            }
            if (!$alreadyMentioned) {
                $missingElements[] = $section;
            }
        }

        return new ValidationFeedback(
            documentType: $feedback->documentType,
            comparisonReasoning: $feedback->comparisonReasoning,
            presentElements: $feedback->presentElements,
            structuralErrors: $feedback->structuralErrors,
            missingElements: $missingElements,
            suggestions: $feedback->suggestions,
        );
    }

    /**
     * Scarta elementi anomalmente lunghi/multi-riga E limita il numero
     * massimo di elementi per campo: due difese distinte per lo stesso
     * problema. La prima non basta da sola — visto in produzione un caso in
     * cui il modello ha "spezzato" un intero verbale fittizio (data, ora,
     * agenda, elenco partecipanti con nomi mai comparsi nel documento) in
     * tanti elementi brevi separati dell'array, ognuno troppo corto per
     * essere scartato dal solo controllo di lunghezza. Lo schema chiede 2-3
     * suggerimenti "pratici e specifici": un numero molto più alto è già di
     * per sé un segnale che qualcosa si è "sfilacciato" oltre la critica
     * vera e propria, quindi si tronca invece di fidarsi del conteggio dato
     * dal modello.
     */
    private function filterFabricatedContent(ValidationFeedback $feedback): ValidationFeedback
    {
        $clean = fn (array $items, int $maxItems) => array_slice(array_values(array_filter(
            $items,
            fn (string $i) => mb_strlen($i) <= 400 && substr_count($i, "\n") < 2,
        )), 0, $maxItems);

        return new ValidationFeedback(
            documentType: $feedback->documentType,
            comparisonReasoning: $feedback->comparisonReasoning,
            presentElements: $feedback->presentElements,
            structuralErrors: $clean($feedback->structuralErrors, 6),
            missingElements: $clean($feedback->missingElements, 8),
            suggestions: $clean($feedback->suggestions, 3),
        );
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

        // Stesso modello del validatore (services.ollama.validation_model), non
        // quello generico: nel test sui 17 tipi, la traduzione su llama3:8b ha
        // fatto trapelare nell'output il prompt di traduzione stesso (il
        // modello piccolo tende a ripetere le istruzioni invece di seguirle
        // soltanto) — un modello più capace per tradurre riduce anche questo.
        $validationModel = (string) config('services.ollama.validation_model');
        $translateEach = fn (array $items) => array_map(fn (string $i) => TranslateToItalian::translate($i, $validationModel), $items);

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
