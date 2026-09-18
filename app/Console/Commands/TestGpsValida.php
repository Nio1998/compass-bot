<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Rag\DocumentSectionChecker;
use App\Rag\DocumentTypes;
use App\Rag\GpsDocumentValidator;
use App\Rag\PrivacyRedactor;
use App\Rag\SmalotPdfReader;
use App\Rag\TranslateToItalian;
use App\Rag\ValidationFeedback;
use Illuminate\Console\Command;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

/**
 * Due parti:
 * 1. Verifica veloce (nessuna generazione LLM) che il retrieval filtrato
 *    funzioni per tutti i 17 tipi di documento — solo controllo dei nomi
 *    file effettivamente recuperati contro la mappatura di DocumentTypes.
 * 2. Validazione completa (con retry) su un campione di documenti di prova
 *    con errori intenzionali, uno per categoria diversa.
 */
class TestGpsValida extends Command
{
    protected $signature = 'test:gps-valida {samples-dir : Cartella con i PDF di prova per la parte 2}';

    protected $description = 'Verifica il retrieval per tutti i tipi documento e valida un campione di PDF di prova';

    /** @var array<string, string> tipo => nome file PDF nella cartella campioni */
    private const SAMPLES = [
        'wbs'                => 'test-wbs.pdf',
        'minuta'             => 'test-minuta.pdf',
        'risk_plan'          => 'test-risk.pdf',
        'business_case'      => 'test-business-case.pdf',
        'agenda'             => 'test-agenda.pdf',
        'sow'                => 'test-sow.pdf',
        'scope_statement'    => 'test-scope-statement.pdf',
        'status_report'      => 'test-status-report.pdf',
        'time_management'    => 'test-time-management.pdf',
        'project_charter'    => 'test-project-charter.pdf',
        'stakeholder_reg'    => 'test-stakeholder-reg.pdf',
        'team_contract'      => 'test-team-contract.pdf',
        'config_mgmt_plan'   => 'test-config-mgmt.pdf',
        'raci'               => 'test-raci.pdf',
        'lesson_learned'     => 'test-lesson-learned.pdf',
        'scrum'              => 'test-scrum.pdf',
        'financial_analysis' => 'test-financial-analysis.pdf',
    ];

    public function handle(): int
    {
        $this->part1RetrievalCheck();
        $this->newLine();
        $this->part2FullValidation();

        return self::SUCCESS;
    }

    private function part1RetrievalCheck(): void
    {
        $this->info('=== PARTE 1: verifica retrieval per tutti i 17 tipi ===');
        $this->newLine();

        foreach (array_keys(DocumentTypes::options()) as $type) {
            $validator = GpsDocumentValidator::make()->forDocumentType($type);
            $embedding = $validator->resolveEmbeddingsProvider()->embedText(DocumentTypes::label($type));
            $docs = $validator->resolveVectorStore()->similaritySearch($embedding);

            $bySource = [];
            foreach ($docs as $d) {
                $bySource[$d->sourceType][$d->sourceName] = true;
            }

            $slideCount = count($bySource['slide'] ?? []);
            $refCount   = count($bySource['validation-ref'] ?? []);
            $expectedSlides = count(DocumentTypes::slideSources($type));
            $expectedRefs   = count(DocumentTypes::referenceSources($type));

            $flag = ($expectedSlides > 0 && $slideCount === 0) || ($expectedRefs > 0 && $refCount === 0) ? ' ⚠️' : '';

            $this->line(sprintf(
                '%-20s slide: %d file (attesi ~%d) | riferimenti: %d file (attesi ~%d)%s',
                $type,
                $slideCount,
                $expectedSlides,
                $refCount,
                $expectedRefs,
                $flag,
            ));
        }
    }

    private function part2FullValidation(): void
    {
        $this->info('=== PARTE 2: validazione completa su documenti campione ===');
        $this->newLine();

        $dir = rtrim((string) $this->argument('samples-dir'), '/');

        foreach (self::SAMPLES as $type => $filename) {
            $path = "{$dir}/{$filename}";
            $this->line('=========================================================');
            $this->info("Tipo: " . DocumentTypes::label($type) . " ({$filename})");

            if (!is_file($path)) {
                $this->error("File non trovato: {$path}");
                continue;
            }

            try {
                $text = SmalotPdfReader::getText($path);
                if (trim($text) === '') {
                    $this->error('Nessun testo estratto dal PDF.');
                    continue;
                }

                // Calcolati UNA VOLTA sul testo originale, prima di anteporre
                // la nota per il modello (altrimenti la nota stessa farebbe
                // "trovare" sezioni in realtà assenti al ricalcolo successivo).
                $originalText = $text;
                $presentSections = DocumentSectionChecker::detectPresent($type, $text);
                $missingSections = DocumentSectionChecker::detectMissing($type, $text);

                // Se nessuna sezione del template del corso viene trovata, il
                // documento probabilmente segue una struttura diversa (visto
                // su un documento reale non scritto per il corso). Forzare
                // comunque la lista "mancanti" ha fatto sì che il modello si
                // limitasse a copiarla invece di analizzare il contenuto —
                // la nota scatta quindi solo con adesione almeno parziale.
                if ($presentSections !== []) {
                    $lines = ['[Controllo automatico via codice, non generato dal modello:'];
                    $lines[] = 'sezioni RILEVATE come presenti nel testo: ' . implode(', ', $presentSections) . '. Non puoi dichiararle mancanti o assenti.';
                    if ($missingSections !== []) {
                        $lines[] = 'sezioni del template del corso NON rilevate nel testo: ' . implode(', ', $missingSections) . '. Non serve che tu le riporti in "elementi mancanti" — verranno aggiunte automaticamente dal sistema — concentrati invece su "errori strutturali" e "suggerimenti" per il resto del documento.';
                    }
                    $text = implode(' ', $lines) . "]\n\n" . $text;
                }

                $start = microtime(true);

                [$feedback, $attempts] = $this->validateWithRetry($type, $text);

                $feedback = $this->filterFabricatedContent($feedback, $originalText);
                $feedback = $this->translateIfNeeded($feedback);
                $feedback = $this->applySectionChecklist($feedback, $missingSections);
                $body = PrivacyRedactor::redact($this->formatFeedback($feedback, DocumentTypes::label($type)));

                $elapsed = round(microtime(true) - $start, 1);

                $allEmpty = $feedback->structuralErrors === [] && $feedback->missingElements === [] && $feedback->suggestions === [];

                $this->line("({$elapsed}s, {$attempts} tentativi" . ($allEmpty ? ' — TUTTO VUOTO ANCHE DOPO RETRY ⚠️' : '') . ')');
                $this->line($body);
            } catch (Throwable $e) {
                $this->error('ERRORE: ' . $e->getMessage());
            }

            $this->newLine();
        }
    }

    /**
     * Stesso retry usato da ProcessGpsValidationFileJob: riprova se il modello
     * torna tutti e tre i campi vuoti, oppure se la chiamata fallisce (es.
     * timeout Ollama su documenti più lunghi).
     *
     * @return array{0: ValidationFeedback, 1: int} feedback e numero di tentativi fatti
     */
    private function validateWithRetry(string $type, string $text, int $maxAttempts = 2): array
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                /** @var ValidationFeedback $feedback */
                $feedback = GpsDocumentValidator::make()
                    ->forDocumentType($type)
                    ->structured(new UserMessage($text), ValidationFeedback::class);
            } catch (Throwable $e) {
                if ($attempt === $maxAttempts) {
                    throw $e;
                }
                continue;
            }

            $allEmpty = $feedback->structuralErrors === []
                && $feedback->missingElements === []
                && $feedback->suggestions === [];

            if (!$allEmpty || $attempt === $maxAttempts) {
                return [$feedback, $attempt];
            }
        }

        return [$feedback, $maxAttempts];
    }

    private function translateIfNeeded(ValidationFeedback $feedback): ValidationFeedback
    {
        $sample = implode(' ', [...$feedback->structuralErrors, ...$feedback->missingElements, ...$feedback->suggestions]);
        if (trim($sample) === '' || TranslateToItalian::looksItalian($sample)) {
            return $feedback;
        }

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

    /**
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

    private function filterFabricatedContent(ValidationFeedback $feedback, string $originalText): ValidationFeedback
    {
        $clean = fn (array $items, int $maxItems) => array_slice(array_values(array_filter(
            $items,
            fn (string $i) => mb_strlen($i) <= 400 && substr_count($i, "\n") < 2 && !$this->isSelfContradictory($i),
        )), 0, $maxItems);

        $missingElements = array_values(array_filter(
            $clean($feedback->missingElements, 8),
            fn (string $i) => !$this->isFalseAbsenceClaim($i, $originalText),
        ));

        return new ValidationFeedback(
            documentType: $feedback->documentType,
            comparisonReasoning: $feedback->comparisonReasoning,
            presentElements: $feedback->presentElements,
            structuralErrors: $clean($feedback->structuralErrors, 6),
            missingElements: $missingElements,
            suggestions: $clean($feedback->suggestions, 3),
        );
    }

    private function isFalseAbsenceClaim(string $item, string $originalText): bool
    {
        $hasAbsenceTrigger = (bool) preg_match(
            '/(non\s+(?:è|sono|viene|vengono|ci\s+sono|risulta(?:no)?)\s+\w*\s*(?:specific\w*|indic\w*|defin\w*|present\w*|chiar\w*|menzion\w*))|(\bmanca\w*\b)|(\bassent\w*\b)/ui',
            $item,
        );

        if (!$hasAbsenceTrigger) {
            return false;
        }

        preg_match_all('/\p{L}{5,}/u', $item, $matches);

        static $stopwords = [
            'specificato', 'specificata', 'specificati', 'specificate', 'specificazione',
            'indicato', 'indicata', 'indicati', 'indicate', 'indicazione', 'indicazioni',
            'definito', 'definita', 'definiti', 'definite', 'definizione',
            'presente', 'presenti', 'chiaramente', 'chiaro', 'chiara',
            'menzionato', 'menzionata', 'menzionati', 'menzionate',
            'mancano', 'manca', 'mancante', 'mancanti', 'assente', 'assenti',
            'potrebbe', 'potrebbero', 'questo', 'questa', 'questi', 'queste',
            'documento', 'attività', 'attivita', 'elemento', 'elementi',
            'progetto', 'sistema', 'sezione', 'sezioni',
        ];

        $candidates = array_diff(array_map(mb_strtolower(...), $matches[0]), $stopwords);

        if ($candidates === []) {
            return false;
        }

        $textLower = mb_strtolower($originalText);
        $hits = 0;

        foreach ($candidates as $word) {
            $stem = mb_strlen($word) >= 6 ? mb_substr($word, 0, 6) : $word;
            if (mb_strpos($textLower, $stem) !== false) {
                $hits++;
            }
        }

        return $hits >= 1;
    }

    /**
     * Scarta un pattern osservato su un documento reale (non uno dei
     * sintetici di test): il modello dichiara un elemento "mancante" e nella
     * stessa frase ammette che è invece indicato/specificato altrove nel
     * documento — es. "Non è specificato chi approva le attività, anche se è
     * indicato che sono state approvate da...". La frase è autocontraddittoria
     * per costruzione: se qualcosa è indicato da qualche parte, per
     * definizione non è un elemento mancante. Non richiede alcun giudizio del
     * modello, solo un riconoscimento testuale di negazione + concessione +
     * conferma nella stessa frase.
     */
    private function isSelfContradictory(string $item): bool
    {
        $hasNegation = (bool) preg_match('/non\s+(?:è|viene|sono|risulta(?:no)?)\s+\w*\s*(?:specificat|indicat)/ui', $item);
        $hasConcession = (bool) preg_match('/\b(anche se|sebbene|nonostante|seppur|pur essendo)\b/ui', $item);
        $hasConfirmation = (bool) preg_match('/\b(indicat\w*|specificat\w*)\b/ui', $item);

        return $hasNegation && $hasConcession && $hasConfirmation;
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
