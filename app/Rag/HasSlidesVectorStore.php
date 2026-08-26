<?php

declare(strict_types=1);

namespace App\Rag;

use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

/**
 * Vector store condiviso (stesso indice) tra GpsQaBot e GpsDocumentValidator:
 * entrambi cercano tra i passaggi delle slide del corso già ingerite.
 *
 * Crea il file dell'indice se non esiste ancora, così una similarity search
 * prima di qualunque ingestione (nessuna slide ancora caricata) non fallisce
 * per file mancante invece di restituire semplicemente zero risultati.
 */
trait HasSlidesVectorStore
{
    protected function vectorStore(): VectorStoreInterface
    {
        $directory = storage_path('app/rag/slides');
        $storeFile = $directory . '/slides.store';

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        if (!is_file($storeFile)) {
            touch($storeFile);
        }

        return new FileVectorStore(
            directory: $directory,
            topK: $this->slidesTopK(),
            name: 'slides',
        );
    }

    protected function slidesTopK(): int
    {
        return 6;
    }
}
