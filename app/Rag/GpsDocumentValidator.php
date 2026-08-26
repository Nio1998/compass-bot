<?php

declare(strict_types=1);

namespace App\Rag;

use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\RAG;

/**
 * Validatore documenti di Project Management (WBS, Piano dei Rischi, Project
 * Charter, ecc.). Lo studente incolla il testo del documento con /gps-valida
 * e riceve un feedback strutturato su errori ed elementi mancanti.
 *
 * Usa lo stesso indice vettoriale delle slide di GpsQaBot: il testo incollato
 * dallo studente viene usato come query di retrieval, così il modello valuta
 * il documento alla luce di ciò che le slide del corso insegnano su quel
 * particolare artefatto, invece di basarsi solo sulla conoscenza generica.
 */
class GpsDocumentValidator extends RAG
{
    use HasSlidesVectorStore;

    protected function provider(): AIProviderInterface
    {
        return new Ollama(
            url: (string) config('services.ollama.url'),
            model: (string) config('services.ollama.model'),
        );
    }

    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new OllamaEmbeddingsProvider(
            model: (string) config('services.ollama.embedding_model'),
            url: (string) config('services.ollama.url'),
        );
    }

    // La validazione beneficia di più contesto della semplice Q&A: più passaggi di slide
    // recuperati aiutano a valutare il documento incollato su più criteri contemporaneamente.
    protected function slidesTopK(): int
    {
        return 8;
    }

    public function instructions(): string
    {
        return implode("\n", [
            "Sei un assistente che aiuta gli studenti del corso di Gestione dei Progetti Software (GPS) a validare i documenti di project management che producono per il loro progetto (WBS, Piano dei Rischi, Project Charter, Gantt, ecc.).",
            "Lo studente incolla il testo del proprio documento. Ti vengono forniti anche passaggi pertinenti delle slide del corso come contesto recuperato: usali come criterio di riferimento per capire cosa il docente si aspetta da quel tipo di documento.",
            "",
            "COSA FARE:",
            "1. Individua di che tipo di documento si tratta (WBS, piano dei rischi, charter, ecc.) dal contenuto stesso.",
            "2. Segnala ERRORI STRUTTURALI concreti (es. livelli di scomposizione mancanti in una WBS, rischi senza probabilità/impatto, attività senza responsabile).",
            "3. Segnala ELEMENTI MANCANTI rispetto a quanto atteso per quel tipo di documento secondo le slide del corso.",
            "4. Dai 2-3 SUGGERIMENTI DI MIGLIORAMENTO pratici e specifici al contenuto incollato (non generici).",
            "5. Se il contesto recuperato dalle slide non è pertinente al documento incollato, basati comunque sulle buone pratiche standard di project management e dillo esplicitamente.",
            "6. Se il testo incollato non sembra affatto un documento di project management, dillo chiaramente invece di inventare un'analisi.",
            "",
            "FORMATO OUTPUT (rispondi sempre in italiano, in formato mrkdwn di Slack — *grassetto* con asterischi singoli, elenchi puntati con '•'):",
            "*Tipo di documento rilevato:* ...",
            "*Errori strutturali:*",
            "• ...",
            "*Elementi mancanti:*",
            "• ...",
            "*Suggerimenti:*",
            "• ...",
        ]);
    }
}
