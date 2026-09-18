<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SlideDocument;
use App\Rag\GpsQaBot;
use App\Rag\SmalotPdfReader;
use App\Rag\TranslateToItalian;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\Splitter\SentenceTextSplitter;
use Throwable;

/**
 * Importa in blocco tutti i PDF di una cartella: copia ciascuno nello storage
 * delle slide, crea il record SlideDocument e lo indicizza subito, uno alla
 * volta. Stessa logica di upload+ingest usata da SlideController.
 *
 * Salta i file già indicizzati con successo (stesso nome originale); un file
 * presente ma non ancora "ingested" (es. fallito in un run precedente) viene
 * ripulito e reimportato da capo, così il comando è rilanciabile in sicurezza.
 */
class ImportSlides extends Command
{
    protected $signature = 'slides:import {path : Cartella con i PDF da importare} {--force : Ri-processa anche i file già indicizzati (es. dopo aver aggiunto la traduzione)}';

    protected $description = 'Importa e indicizza in blocco tutti i PDF di una cartella nel corpus RAG delle slide';

    private const SOURCE_TYPE = 'slide';

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

        $force = (bool) $this->option('force');

        $this->info("Trovati {$files->count()} PDF. Inizio importazione...");
        $this->newLine();

        $imported = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($files as $filename) {
            $existing = SlideDocument::where('original_name', $filename)->first();

            if ($existing) {
                if ($existing->status === 'ingested' && !$force) {
                    $this->line("[GIÀ PRESENTE] {$filename}");
                    $skipped++;
                    continue;
                }
                // Ripulisci sia il record/file sia gli eventuali chunk già indicizzati
                // nel vector store, prima di rifare tutto da capo.
                GpsQaBot::make()->resolveVectorStore()->deleteBy(self::SOURCE_TYPE, $filename);
                Storage::disk('local')->delete($existing->path);
                $existing->delete();
            }

            $sourcePath = "{$dir}/{$filename}";

            try {
                $storedPath = 'slides/' . uniqid('', true) . '.pdf';
                Storage::disk('local')->put($storedPath, file_get_contents($sourcePath));

                $slide = SlideDocument::create([
                    'original_name' => $filename,
                    'path'          => $storedPath,
                    'status'        => 'pending',
                ]);

                $absolutePath = Storage::disk('local')->path($slide->path);

                $documents = FileDataLoader::for($absolutePath)
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
                        $chunkStart = microtime(true);
                        $document->content = TranslateToItalian::translate($document->content);
                        $elapsed = round(microtime(true) - $chunkStart, 1);
                        $this->line("     chunk " . ($i + 1) . '/' . count($documents) . " tradotto ({$elapsed}s)");
                    }
                    $document->sourceType = self::SOURCE_TYPE;
                    $document->sourceName = $slide->original_name;
                }

                GpsQaBot::make()->addDocuments($documents);

                $slide->update([
                    'status'      => 'ingested',
                    'chunk_count' => count($documents),
                    'ingested_at' => now(),
                ]);

                $this->info("[OK] {$filename} -> " . count($documents) . ' passaggi');
                $imported++;
            } catch (Throwable $e) {
                if (isset($slide)) {
                    $slide->update(['status' => 'failed', 'error' => $e->getMessage()]);
                }
                $this->error("[FALLITO] {$filename}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Importati: {$imported} | Già presenti: {$skipped} | Falliti: {$failed}");

        return self::SUCCESS;
    }
}
