<?php

declare(strict_types=1);

namespace App\Rag;

use GuzzleHttp\Client;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\ChromaVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

/**
 * Variante di ChromaVectorStore che, se gli si passano dei $sourceNames,
 * filtra la ricerca solo su quei documenti (query Chroma con "where"),
 * invece di cercare su tutta la collection.
 *
 * Usato da GpsDocumentValidator per il retrieval mirato al tipo di documento
 * scelto dallo studente nella modale — es. quando valida una WBS, cerca solo
 * dentro ai file WBS invece che in tutti i 192 chunk della collection dei
 * riferimenti.
 *
 * addDocument/addDocuments/deleteBy sono delegati a una ChromaVectorStore
 * normale (nessuna logica diversa serve per scrivere/cancellare).
 */
class ChromaFilteredVectorStore implements VectorStoreInterface
{
    private ChromaVectorStore $writer;
    private Client $client;
    private string $collection;
    private string $host;
    private string $tenant;
    private string $database;
    private int $topK;

    /** @param string[] $sourceNames Vuoto = nessun filtro, cerca su tutta la collection. */
    public function __construct(
        string $collection,
        string $host,
        int $topK,
        private readonly array $sourceNames = [],
        string $tenant = 'default_tenant',
        string $database = 'default_database',
    ) {
        $this->collection = $collection;
        $this->host = rtrim($host, '/');
        $this->tenant = $tenant;
        $this->database = $database;
        $this->topK = $topK;

        $this->writer = new ChromaVectorStore(
            collection: $collection,
            host: $host,
            tenant: $tenant,
            database: $database,
            topK: $topK,
        );
        $this->client = new Client(['timeout' => 30]);
    }

    public function similaritySearch(array $embedding): iterable
    {
        $collectionId = $this->resolveCollectionId();

        $body = [
            'query_embeddings' => [$embedding],
            'n_results'        => $this->topK,
            'include'          => ['documents', 'metadatas', 'distances'],
        ];
        if ($this->sourceNames !== []) {
            $body['where'] = ['sourceName' => ['$in' => $this->sourceNames]];
        }

        $url = "{$this->host}/api/v2/tenants/{$this->tenant}/databases/{$this->database}/collections/{$collectionId}/query";
        $response = json_decode((string) $this->client->post($url, ['json' => $body])->getBody(), true) ?: [];

        $size = count($response['ids'][0] ?? []);
        $documents = [];
        for ($i = 0; $i < $size; $i++) {
            $document = new Document();
            $document->id = $response['ids'][0][$i] ?? uniqid();
            $document->content = $response['documents'][0][$i];
            $document->sourceType = $response['metadatas'][0][$i]['sourceType'] ?? null;
            $document->sourceName = $response['metadatas'][0][$i]['sourceName'] ?? null;
            $document->score = VectorSimilarity::similarityFromDistance($response['distances'][0][$i] ?? 0.0);
            $documents[] = $document;
        }

        return $documents;
    }

    private function resolveCollectionId(): string
    {
        $url = "{$this->host}/api/v2/tenants/{$this->tenant}/databases/{$this->database}/collections";
        $response = json_decode((string) $this->client->post($url, [
            'json' => ['name' => $this->collection, 'get_or_create' => true],
        ])->getBody(), true) ?: [];

        return (string) $response['id'];
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        $this->writer->addDocument($document);
        return $this;
    }

    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->writer->addDocuments($documents);
        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        $this->writer->deleteBy($sourceType, $sourceName);
        return $this;
    }
}
