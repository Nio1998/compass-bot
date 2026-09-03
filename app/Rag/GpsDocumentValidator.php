<?php

declare(strict_types=1);

namespace App\Rag;

use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

/**
 * Validatore documenti di Project Management (WBS, Piano dei Rischi, Project
 * Charter, ecc.). Lo studente sceglie il tipo di documento e allega il PDF
 * tramite la modale Slack di /gps-valida, e riceve un feedback strutturato
 * su errori ed elementi mancanti.
 *
 * A differenza di GpsQaBot, cerca in DUE collection combinate (vedi
 * CompositeVectorStore): le slide teoriche del corso (condivise, sola
 * lettura qui) e una collection separata di documenti di riferimento reali
 * (un progetto esempio fornito dal docente) — così il feedback può basarsi
 * anche su come è fatto davvero un buon WBS/Project Charter/ecc., non solo
 * sulla teoria. Le slide di GpsQaBot restano intatte: questa collection in
 * più non le tocca in alcun modo, /gps-domanda non ne risente.
 *
 * Se lo studente indica il tipo di documento (dropdown nella modale), il
 * retrieval viene filtrato solo sui file pertinenti a quel tipo (vedi
 * DocumentTypes), invece di cercare su tutta la collection — più preciso e
 * meno soggetto al problema di retrieval "diluito" visto su GpsQaBot quando
 * il corpus è grande.
 */
class GpsDocumentValidator extends RAG
{
    private ?string $docType = null;

    /** Imposta il tipo di documento scelto dallo studente, per un retrieval mirato. */
    public function forDocumentType(?string $docType): self
    {
        $this->docType = $docType;
        return $this;
    }

    protected function provider(): AIProviderInterface
    {
        // Il timeout HTTP di default (60s) basta per domande brevi, ma qui il
        // prompt include un documento reale intero più il contesto recuperato
        // (slide + progetto esempio): su questa macchina llama3:8b può
        // impiegare più di 60s a produrre l'output strutturato, causando un
        // timeout con 0 byte ricevuti prima ancora di iniziare a rispondere.
        return new Ollama(
            url: (string) config('services.ollama.url'),
            model: (string) config('services.ollama.model'),
            parameters: ['options' => ['temperature' => 0.1]],
            httpClient: new GuzzleHttpClient(timeout: 180.0),
        );
    }

    protected function embeddings(): EmbeddingsProviderInterface
    {
        return new OllamaEmbeddingsProvider(
            model: (string) config('services.ollama.embedding_model'),
            url: (string) config('services.ollama.url'),
        );
    }

    // La validazione beneficia di più contesto della semplice Q&A: più passaggi
    // recuperati aiutano a valutare il documento incollato su più criteri contemporaneamente.
    protected function slidesTopK(): int
    {
        return 14;
    }

    protected function vectorStore(): VectorStoreInterface
    {
        $slideSources     = $this->docType !== null ? DocumentTypes::slideSources($this->docType) : [];
        $referenceSources = $this->docType !== null ? DocumentTypes::referenceSources($this->docType) : [];

        $slides = new ChromaFilteredVectorStore(
            collection: (string) config('services.chroma.collection'),
            host: (string) config('services.chroma.host'),
            topK: $this->slidesTopK(),
            sourceNames: $slideSources,
        );

        $references = new ChromaFilteredVectorStore(
            collection: (string) config('services.chroma.validation_collection'),
            host: (string) config('services.chroma.host'),
            topK: $this->slidesTopK(),
            sourceNames: $referenceSources,
        );

        return new CompositeVectorStore($slides, $references);
    }

    public function instructions(): string
    {
        $docTypeLine = $this->docType !== null
            ? "Lo studente ha indicato che il documento è di tipo: **" . DocumentTypes::label($this->docType) . "**. Fidati di questa indicazione, non serve indovinare il tipo dal contenuto."
            : "Individua di che tipo di documento si tratta (WBS, piano dei rischi, charter, ecc.) dal contenuto stesso.";

        return implode("\n", [
            "Il tuo UNICO compito è fare la REVISIONE CRITICA di un documento che ti viene fornito, NON riscriverlo, NON completarlo, NON produrne una versione migliorata o più dettagliata. Sei un revisore, non un autore.",
            "",
            "REGOLA ASSOLUTA SUL FORMATO, SENZA ECCEZIONI: la tua risposta DEVE avere ESATTAMENTE queste 4 righe/sezioni, in italiano, e NULLA ALTRO PRIMA O DOPO (niente introduzioni tipo 'Certo, ecco...', niente premesse, niente conclusioni fuori formato):",
            "*Tipo di documento:* <tipo>",
            "*Errori strutturali:*",
            "• <punto> (oppure 'Nessuno rilevato')",
            "*Elementi mancanti:*",
            "• <punto> (oppure 'Nessuno rilevato')",
            "*Suggerimenti:*",
            "• <punto>",
            "",
            "REGOLA ASSOLUTA SULLA LINGUA: rispondi SEMPRE e SOLO in italiano, anche se il documento o il contesto recuperato sono in inglese.",
            "",
            "REGOLA ASSOLUTA SULLE FONTI: puoi citare SOLO fatti, criteri e nomi che compaiono LETTERALMENTE nel contesto recuperato (slide del corso + progetto esempio). Non aggiungere criteri 'da manuale' non presenti nel contesto.",
            "",
            "REGOLA ASSOLUTA SUL GROUNDING: prima di scrivere qualcosa in 'errori strutturali' o 'elementi mancanti', rileggi il testo del documento parola per parola. Se un campo o una sezione è presente anche solo parzialmente compilato (es. una data scritta, un elenco firmatari, un campo 'Oggetto' valorizzato), NON puoi dichiararlo mancante o assente: sarebbe una contraddizione con quanto hai già osservato in presentElements. Puoi commentarne la qualità o la completezza (es. 'la sezione discussione tratta un solo punto, poco dettagliato'), ma mai la sua assenza se è presente.",
            "",
            "REGOLA ASSOLUTA SU 'ERRORI STRUTTURALI' vs 'ELEMENTI MANCANTI': sono due categorie diverse, non intercambiabili. 'Errori strutturali' = qualcosa CHE C'È ma è fatto male (usa verbi come 'è presente ma...', 'c'è ma manca...'). 'Elementi mancanti' = qualcosa che NON C'È PROPRIO, nemmeno abbozzato. Non scrivere mai la stessa frase in entrambi i campi, e non usare parole come 'manca'/'non fornisce'/'non è presente' per descrivere qualcosa che hai già segnato come presente.",
            "",
            "Sei un assistente del corso di Gestione dei Progetti Software (GPS). Il documento allegato dallo studente ti viene fornito come testo estratto da un PDF. Ti vengono forniti anche passaggi di contesto recuperato: una parte dalle slide teoriche del corso, una parte da un progetto esempio reale — usali SOLO come metro di paragone per giudicare il documento dello studente, mai come contenuto da copiare o imitare nella tua risposta.",
            "",
            "COSA VALUTARE:",
            "1. {$docTypeLine}",
            "2. ERRORI STRUTTURALI concreti nel documento dello studente (es. livelli di scomposizione mancanti in una WBS, rischi senza probabilità/impatto, attività senza responsabile).",
            "3. ELEMENTI MANCANTI nel documento dello studente rispetto a quanto atteso secondo il contesto recuperato.",
            "4. 2-3 SUGGERIMENTI pratici e specifici al contenuto del documento dello studente (non generici, non 'da manuale').",
            "5. Se il contesto recuperato non è pertinente, basati sulle buone pratiche standard e dillo esplicitamente nei suggerimenti.",
            "6. Se il testo non sembra affatto un documento di project management, scrivilo in '*Tipo di documento:*' e lascia le altre sezioni con 'Non applicabile'.",
            "",
            "ESEMPIO DI ERRORE GRAVE DA NON RIPETERE MAI:",
            "Documento dello studente: una WBS con solo 4 righe, senza sotto-attività né responsabili.",
            "Risposta SBAGLIATA (non fare mai così): elencare una nuova WBS più dettagliata con sotto-punti 1.1, 1.2, 2.1, ecc. — SBAGLIATO perché non è una revisione, è una riscrittura: hai inventato contenuto e ignorato il formato richiesto.",
            "Risposta CORRETTA: '*Tipo di documento:* WBS\\n*Errori strutturali:*\\n• Manca la scomposizione in sotto-attività: ogni voce di primo livello dovrebbe essere ulteriormente suddivisa\\n*Elementi mancanti:*\\n• Nessun responsabile assegnato alle attività\\n• Nessuna stima di durata per singola attività\\n*Suggerimenti:*\\n• Scomponi ogni fase in attività più piccole seguendo la regola delle 80 ore\\n• Assegna un responsabile a ciascuna attività' — CORRETTO: commenta il documento dato, non ne scrive uno nuovo.",
            "",
            "ESEMPIO DI ERRORE GRAVE DA NON RIPETERE MAI (contraddizione col documento):",
            "Documento dello studente: un verbale di riunione con un campo 'Data' compilato e una sezione 'Approvazione e Firme' presente.",
            "Risposta SBAGLIATA (non fare mai così): scrivere in errori strutturali 'Il verbale non è datato' oppure in elementi mancanti 'Manca la sezione di approvazione e firma' — SBAGLIATO perché quei campi sono letteralmente nel documento: è un'allucinazione, non un giudizio.",
            "Risposta CORRETTA: se data e firme sono presenti, non vanno segnalate come mancanti. Semmai si commenta la qualità di ciò che c'è già (es. 'la sezione discussione tratta un solo punto, poco dettagliato') — MAI la sua assenza se il campo è presente.",
        ]);
    }
}
