<?php

declare(strict_types=1);

namespace App\Rag;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Traduce un chunk di testo in italiano tramite Ollama, se non lo è già.
 *
 * Applicato per-chunk (dopo lo splitting) invece che sull'intero documento,
 * per restare ben dentro la finestra di contesto del modello anche sui PDF
 * più grandi (es. 90 chunk per un singolo file).
 *
 * Motivazione: il retrieval cross-lingua (domanda in italiano, slide in
 * inglese) si è dimostrato debole con nomic-embed-text — portare tutto il
 * corpus alla stessa lingua della domanda elimina il problema alla radice
 * invece di provare a compensarlo lato prompt/retrieval.
 */
class TranslateToItalian
{
    /** @var string[] */
    private const ITALIAN_MARKERS = [' il ', ' la ', ' di ', ' che ', ' per ', ' con ', ' una ', ' sono ', ' del ', ' gli ', ' delle ', ' non ', ' dei ', ' nella ', ' anche ', ' come '];

    /** @var string[] */
    private const ENGLISH_MARKERS = [' the ', ' and ', ' of ', ' to ', ' is ', ' are ', ' for ', ' with ', ' this ', ' that ', ' you ', ' your ', ' will ', ' can '];

    /**
     * Controllo veloce ed economico (nessuna chiamata a Ollama) per decidere
     * se un testo è già in italiano, così evitiamo di tradurre chunk per
     * chunk file che non ne hanno bisogno — su centinaia di chunk il
     * risparmio di tempo è enorme (~15s per chunk tradotto).
     */
    public static function looksItalian(string $sample): bool
    {
        $text = ' ' . mb_strtolower($sample) . ' ';
        $italianHits = 0;
        $englishHits = 0;
        foreach (self::ITALIAN_MARKERS as $marker) {
            $italianHits += substr_count($text, $marker);
        }
        foreach (self::ENGLISH_MARKERS as $marker) {
            $englishHits += substr_count($text, $marker);
        }
        // In parità o dubbio, si presume italiano: costa meno saltare per
        // errore una traduzione che serviva, che tradurre inutilmente.
        return $italianHits >= $englishHits;
    }

    public static function translate(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        $model = (string) config('services.ollama.model');
        $url   = rtrim((string) config('services.ollama.url'), '/') . '/generate';

        $prompt = <<<TXT
            Traduci in italiano il seguente testo estratto da una slide universitaria.
            Mantieni la terminologia tecnica di project management, i numeri, gli
            elenchi puntati e la struttura originale.
            Non aggiungere MAI etichette, premesse o note (es. "Ecco la traduzione:",
            "TESTO:", "Traduzione:"). La tua risposta deve iniziare DIRETTAMENTE con
            la prima parola del testo tradotto, senza nient'altro prima.

            {$text}
            TXT;

        try {
            $client = new Client(['timeout' => 60]);
            $res = $client->post($url, [
                'json' => [
                    'model'   => $model,
                    'prompt'  => $prompt,
                    'stream'  => false,
                    'options' => ['temperature' => 0.1],
                ],
            ]);
            $body = json_decode((string) $res->getBody(), true) ?: [];
            $translated = trim((string) ($body['response'] ?? ''));

            // Rete di sicurezza: rimuove eventuali etichette che il modello
            // ha comunque ripetuto in testa alla risposta nonostante il prompt.
            $translated = preg_replace('/^(TESTO|TEXT|TRADUZIONE|TRANSLATION)\s*:\s*/iu', '', $translated) ?? $translated;
            $translated = trim($translated);

            return $translated !== '' ? $translated : $text;
        } catch (\Throwable $e) {
            Log::warning('Traduzione chunk fallita, mantengo il testo originale', ['err' => $e->getMessage()]);
            return $text;
        }
    }
}
