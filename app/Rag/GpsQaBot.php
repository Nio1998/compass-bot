<?php

declare(strict_types=1);

namespace App\Rag;

use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\RAG;

/**
 * Assistente Q&A sul materiale del corso GPS (slide del prof. Palomba).
 *
 * Nessuno storico di conversazione: ogni invocazione dello slash command
 * /gps-domanda è indipendente, in linea con come funzionano gli slash command
 * di Slack (un messaggio -> una risposta, niente contesto tra un comando e l'altro).
 */
class GpsQaBot extends RAG
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

    public function instructions(): string
    {
        return implode("\n", [
            "Sei il tutor virtuale del corso di Gestione dei Progetti Software (GPS) all'Università degli Studi di Salerno.",
            "Rispondi alle domande degli studenti basandoti ESCLUSIVAMENTE sui passaggi delle slide del corso riportati di seguito come contesto recuperato.",
            "",
            "REGOLE:",
            "1. Se il contesto recuperato non contiene informazioni pertinenti alla domanda, rispondi onestamente: 'Non ho trovato questa informazione nelle slide del corso indicizzate. Prova a riformulare la domanda o chiedi al docente.' Non inventare contenuti.",
            "2. Non copiare i passaggi alla lettera: spiega il concetto con parole tue, in modo chiaro e didattico, come faresti con uno studente.",
            "3. Rispondi sempre in italiano, in modo conciso (max 6-8 righe), a meno che la domanda richieda esplicitamente una spiegazione più lunga.",
            "4. Se la domanda non riguarda la gestione dei progetti software / project management, rispondi che esula dall'ambito del corso e che puoi aiutare solo su argomenti trattati a lezione.",
            "5. Il messaggio verrà mostrato in una chat Slack. Se ti serve evidenziare una parola, racchiudila tra un asterisco singolo prima e uno dopo, senza spazi (esempio: la parola importante diventa importante circondata da un asterisco su ciascun lato). Non usare MAI il doppio asterisco. Per gli elenchi usa il simbolo '•' a inizio riga. Se la risposta è breve, va benissimo non usare alcuna formattazione.",
        ]);
    }
}
