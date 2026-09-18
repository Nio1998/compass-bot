<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Rag\GpsDocumentValidator;
use App\Rag\SmalotPdfReader;
use App\Rag\TranslateToItalian;
use Illuminate\Console\Command;
use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\Splitter\SentenceTextSplitter;
use Throwable;

/**
 * Importa in blocco i PDF di riferimento (progetto esempio del docente) usati
 * SOLO da /gps-valida per confrontare i documenti degli studenti con esempi
 * reali. Indicizza tramite GpsDocumentValidator, che scrive nella collection
 * dei riferimenti (non in quella delle slide) — /gps-domanda non ne risente.
 *
 * A differenza di slides:import, qui non c'è un record SlideDocument nel
 * database: questi file non passano dal pannello admin, sono un import
 * diretto da cartella.
 */
class ImportValidationRefs extends Command
{
    protected $signature = 'validation-refs:import {path : Cartella con i PDF di riferimento da importare}';

    protected $description = 'Importa e indicizza i PDF di riferimento (progetto esempio) usati da /gps-valida';

    private const SOURCE_TYPE = 'validation-ref';

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');

        $dir = rtrim((string) $this->argument('path'), '/');

        if (!is_dir($dir)) {
            $this->error("Cartella non trovata: {$dir}");
            return self::FAILURE;
        }

        $files = collect(scandir($dir) ?: [])
            ->reject(fn ($f) => in_array($f, ['.', '..'], true))
            ->filter(fn ($f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'pdf')
            ->values();

        if ($files->isEmpty()) {
            $this->warn('Nessun PDF trovato in questa cartella.');
            return self::SUCCESS;
        }

        $this->info("Trovati {$files->count()} PDF di riferimento. Inizio importazione...");
        $this->newLine();

        $bot = GpsDocumentValidator::make();
        $imported = 0;
        $failed = 0;

        foreach ($files as $filename) {
            $sourcePath = "{$dir}/{$filename}";

            try {
                $documents = FileDataLoader::for($sourcePath)
                    ->addReader('pdf', new SmalotPdfReader())
                    ->withSplitter(new SentenceTextSplitter(maxWords: 200, overlapWords: 20, minWords: 20))
                    ->getDocuments();

                if (empty($documents)) {
                    throw new \RuntimeException('Nessun testo estratto dal PDF.');
                }

                $sample = implode(' ', array_map(fn ($d) => $d->content, array_slice($documents, 0, 3)));
                $needsTranslation = !TranslateToItalian::looksItalian($sample);
                if ($needsTranslation) {
                    $this->line("  -> rilevato non italiano, traduzione in corso (" . count($documents) . ' chunk)...');
                }

                foreach ($documents as $i => $document) {
                    if ($needsTranslation) {
                        $document->content = TranslateToItalian::translate($document->content);
                    }
                    $document->sourceType = self::SOURCE_TYPE;
                    $document->sourceName = $filename;
                }

                $bot->addDocuments($documents);

                $this->info("[OK] {$filename} -> " . count($documents) . ' passaggi');
                $imported++;
            } catch (Throwable $e) {
                $this->error("[FALLITO] {$filename}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Importati: {$imported} | Falliti: {$failed}");

        return self::SUCCESS;
    }
}
