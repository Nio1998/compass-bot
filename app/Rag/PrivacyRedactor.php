<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * Oscura dalle risposte dei bot nomi reali di persone (docenti, ricercatori)
 * che possono comparire nel corpus indicizzato (slide del corso, progetto
 * esempio) e finire nell'output per contaminazione del contesto recuperato
 * — un problema osservato più volte nei test, non risolvibile in modo
 * affidabile solo con istruzioni nel prompt (il modello le ha ignorate più
 * volte in questa stessa sessione). Applicato come ultimo passo, subito
 * prima dell'invio a Slack, così non dipende dal comportamento del modello.
 */
class PrivacyRedactor
{
    /** @var string[] Nomi completi, sostituiti per primi (case-insensitive). */
    private const FULL_NAMES = [
        'Filomena Ferrucci',
        'Fabio Palomba',
        'Alessandra Parziale',
        'Saverio Napolitano',
    ];

    /**
     * Cognomi soli, sostituiti dopo i nomi completi. Case-sensitive (richiede
     * l'iniziale maiuscola) per evitare falsi positivi su parole comuni
     * italiane che coincidono con un cognome (es. "parziale" come aggettivo).
     *
     * @var string[]
     */
    private const SURNAMES = [
        'Ferrucci',
        'Palomba',
        'Parziale',
        'Napolitano',
    ];

    /**
     * Nome del progetto esempio del docente. Stesso trattamento case-sensitive
     * dei cognomi: "Esistere" è anche un verbo italiano comune ("esistere"),
     * quindi va oscurato solo se scritto con l'iniziale maiuscola (riferimento
     * al progetto), non nel normale uso grammaticale minuscolo.
     *
     * @var string[]
     */
    private const PROJECT_NAMES = [
        'Esistere',
    ];

    public static function redact(string $text): string
    {
        $result = $text;

        foreach (self::FULL_NAMES as $name) {
            $result = preg_replace('/\b' . preg_quote($name, '/') . '\b/ui', '[nome omesso]', $result) ?? $result;
        }

        foreach (self::SURNAMES as $surname) {
            $result = preg_replace('/\b' . preg_quote($surname, '/') . '\b/u', '[nome omesso]', $result) ?? $result;
        }

        foreach (self::PROJECT_NAMES as $projectName) {
            $result = preg_replace('/\b' . preg_quote($projectName, '/') . '\b/u', '[progetto omesso]', $result) ?? $result;
        }

        return $result;
    }
}
