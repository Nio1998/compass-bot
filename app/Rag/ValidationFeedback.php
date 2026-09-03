<?php

declare(strict_types=1);

namespace App\Rag;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * Output strutturato del validatore. Forzare il modello a riempire questi
 * campi (invece di scrivere prosa libera con istruzioni sul formato) si è
 * dimostrato l'unico modo affidabile per impedirgli di "riscrivere" il
 * documento dello studente invece di limitarsi a commentarlo — il vincolo
 * di schema lo tiene sui binari molto meglio delle sole istruzioni testuali.
 */
class ValidationFeedback
{
    public function __construct(
        #[SchemaProperty(
            description: 'Il tipo di documento (es. WBS, Project Charter, Risk Management Plan).',
            required: true,
        )]
        public string $documentType,

        #[SchemaProperty(
            description: 'Scrivi ESCLUSIVAMENTE IN ITALIANO (mai in inglese). PRIMA di tutto il resto: confronta per iscritto, punto per punto, il documento dello studente con il contesto recuperato (slide + esempio reale). Quanto è lungo/dettagliato il documento dello studente rispetto all\'esempio? Quali sezioni dell\'esempio non hanno un corrispettivo nel documento dello studente? Scrivi questo ragionamento in modo esplicito PRIMA di compilare gli altri campi — i campi successivi devono basarsi su questo ragionamento e devono anch\'essi essere in italiano.',
            required: true,
        )]
        public string $comparisonReasoning,

        /** @var string[] */
        #[SchemaProperty(
            description: 'Elenco letterale delle sezioni/campi/informazioni EFFETTIVAMENTE PRESENTI e compilati nel documento dello studente (es. "Data della riunione", "Sezione Approvazione e Firme", "Elenco partecipanti"). Scrivilo leggendo il documento con attenzione, non a memoria. Questo elenco è un controllo di realtà: più avanti NON puoi elencare in "errori strutturali" o "elementi mancanti" qualcosa che hai già messo qui come presente — sarebbe una contraddizione.',
            required: true,
        )]
        public array $presentElements,

        /** @var string[] */
        #[SchemaProperty(
            description: 'SOLO problemi di QUALITÀ su cose che nel documento CI SONO (compaiono in presentElements) ma sono fatte male: livelli di scomposizione insufficienti, responsabili non assegnati alle attività, stime di tempo/costo mancanti, sezioni troppo brevi o generiche rispetto all\'esempio di riferimento. Per una sezione presente ma scarna, usa SEMPRE un verbo che ammette la presenza (es. "è presente ma troppo sintetico", "c\'è ma manca il dettaglio su...") — VIETATO usare formule come "non fornisce", "manca", "non è presente" per qualcosa che è in presentElements: userebbero un linguaggio di assenza per una cosa che c\'è, contraddicendo presentElements. Un documento breve o scarno ha quasi SEMPRE almeno 2-3 problemi di questo tipo. NON ripetere qui la stessa frase o lo stesso concetto già scritto in missingElements: i due campi devono restare distinti.',
            required: true,
        )]
        public array $structuralErrors,

        /** @var string[] */
        #[SchemaProperty(
            description: 'SOLO sezioni/informazioni presenti nel contesto di riferimento (slide + esempio reale) ma DEL TUTTO ASSENTI dal documento dello studente — non compaiono nemmeno in forma abbozzata, e per questo NON possono comparire in presentElements. Se il documento dello studente è molto più corto o semplice dell\'esempio di riferimento, questo elenco NON dovrebbe essere vuoto. NON includere qui nulla che compaia già in presentElements: se un\'informazione è presente anche solo in parte, è un problema di QUALITÀ (va in structuralErrors), non di assenza. NON ripetere qui la stessa frase o lo stesso concetto già scritto in structuralErrors.',
            required: true,
        )]
        public array $missingElements,

        /** @var string[] */
        #[SchemaProperty(
            description: 'Da 2 a 3 suggerimenti pratici e specifici al contenuto del documento dello studente, non generici.',
            required: true,
        )]
        public array $suggestions,
    ) {
    }
}
