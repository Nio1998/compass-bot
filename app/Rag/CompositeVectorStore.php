<?php

declare(strict_types=1);

namespace App\Rag;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

/**
 * Combina due vector store in lettura, per GpsDocumentValidator: cerca sia
 * nella collection delle slide teoriche del corso (condivisa con GpsQaBot,
 * sola lettura qui) sia in quella dei documenti di riferimento reali
 * (progetto esempio), unendo e riordinando i risultati per punteggio.
 *
 * Le slide restano gestite solo tramite GpsQaBot/il pannello admin: qui
 * addDocuments/deleteBy operano SOLO sulla collection dei riferimenti, che è
 * quella pensata per crescere nel tempo tramite import dedicati.
 */
class CompositeVectorStore implements VectorStoreInterface
{
    public function __construct(
        private readonly VectorStoreInterface $slides,
        private readonly VectorStoreInterface $references,
    ) {
    }

    public function similaritySearch(array $embedding): iterable
    {
        $results = [
            ...$this->slides->similaritySearch($embedding),
            ...$this->references->similaritySearch($embedding),
        ];

        usort($results, fn (Document $a, Document $b) => $b->score <=> $a->score);

        return $results;
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        $this->references->addDocument($document);
        return $this;
    }

    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->references->addDocuments($documents);
        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        $this->references->deleteBy($sourceType, $sourceName);
        return $this;
    }
}
