<?php

declare(strict_types=1);

namespace App\Rag;

use NeuronAI\RAG\VectorStore\ChromaVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

/**
 * Vector store condiviso (stessa collection) tra GpsQaBot e GpsDocumentValidator:
 * entrambi cercano tra i passaggi delle slide del corso già ingerite.
 *
 * Il backend è un server ChromaDB esterno (self-hosted, vedi config/services.php),
 * raggiunto via HTTP. A differenza del precedente FileVectorStore, non c'è un
 * file locale da inizializzare: la collection viene creata da Chroma stesso al
 * primo addDocuments().
 */
trait HasSlidesVectorStore
{
    protected function vectorStore(): VectorStoreInterface
    {
        return new ChromaVectorStore(
            collection: (string) config('services.chroma.collection'),
            host: (string) config('services.chroma.host'),
            topK: $this->slidesTopK(),
        );
    }

    protected function slidesTopK(): int
    {
        // 10 invece di 6: con un corpus che mescola slide in italiano e in
        // inglese, il match cross-lingua ha score di similarità più bassi
        // di un match nella stessa lingua della domanda — un topK più
        // ampio dà più margine perché il documento giusto rientri comunque
        // tra i risultati anche quando non è il più simile in assoluto.
        return 10;
    }
}
