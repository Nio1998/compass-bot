<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Rag\GpsQaBot;
use App\Rag\PrivacyRedactor;
use App\Rag\TranslateToItalian;
use App\Services\SlackResponder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

/**
 * Esegue la pipeline RAG per /gps-domanda e pubblica la risposta su Slack
 * tramite response_url. Girato in coda perché Ollama può metterci più dei
 * 3 secondi che Slack concede per l'ack immediato dello slash command.
 */
class ProcessGpsQuestionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        private readonly string $question,
        private readonly string $responseUrl,
    ) {
    }

    public function handle(SlackResponder $responder): void
    {
        try {
            $answer = GpsQaBot::make()
                ->chat(new UserMessage($this->question))
                ->getMessage()
                ->getContent() ?? '';

            // Rete di sicurezza contro il modello che risponde in inglese
            // nonostante l'istruzione esplicita nel prompt — stesso problema
            // già visto e mitigato su /gps-valida, mai portato qui finora.
            if (trim($answer) !== '' && !TranslateToItalian::looksItalian($answer)) {
                $answer = TranslateToItalian::translate($answer, (string) config('services.ollama.model'));
            }

            $answer = PrivacyRedactor::redact($answer);

            // La domanda digitata dallo studente non compare mai come messaggio
            // Slack (comportamento standard degli slash command): la ripetiamo
            // qui così resta un riferimento leggibile di cosa è stato chiesto.
            $fallbackText = "Hai chiesto: {$this->question}\n\n{$answer}";

            // Un blocco "section" di Slack accetta al massimo 3000 caratteri di
            // testo: superato quel limite Slack rifiuta l'intero messaggio con
            // "invalid_blocks" e lo studente non vede mai la risposta (visto in
            // produzione su domande che generano risposte lunghe, es. "come si
            // fa un team contract"). Le risposte lunghe vengono quindi divise
            // su più blocchi invece che in uno solo.
            $blocks = [
                [
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => "*Hai chiesto:*\n" . self::truncateForSlack($this->question)],
                ],
                ['type' => 'divider'],
                ...array_map(
                    fn (string $chunk) => ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $chunk]],
                    self::splitForSlack($answer),
                ),
                [
                    'type' => 'context',
                    'elements' => [
                        ['type' => 'mrkdwn', 'text' => '🧭 *CompassBot* · Corso GPS'],
                    ],
                ],
            ];

            $responder->send($this->responseUrl, $fallbackText, 'in_channel', $blocks);
        } catch (Throwable $e) {
            Log::error('ProcessGpsQuestionJob fallito', ['err' => $e->getMessage()]);
            $responder->send(
                $this->responseUrl,
                'Si è verificato un errore mentre elaboravo la tua domanda. Riprova tra qualche minuto.'
            );
        }
    }

    private static function truncateForSlack(string $text, int $limit = 2900): string
    {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : $text;
    }

    /**
     * Divide un testo lungo in più blocchi entro il limite di Slack (3000
     * caratteri per blocco "section"), tagliando ai confini di paragrafo dove
     * possibile invece che a metà frase.
     *
     * @return string[]
     */
    private static function splitForSlack(string $text, int $limit = 2900): array
    {
        if ($text === '') {
            return ['(nessuna risposta)'];
        }

        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        $paragraphs = explode("\n", $text);
        $current = '';

        foreach ($paragraphs as $paragraph) {
            // Un singolo paragrafo più lungo del limite va spezzato a sua volta
            // in più pezzi, non troncato: altrimenti si perde il resto del testo.
            while (mb_strlen($paragraph) > $limit) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                $chunks[] = mb_substr($paragraph, 0, $limit);
                $paragraph = mb_substr($paragraph, $limit);
            }

            $candidate = $current === '' ? $paragraph : "{$current}\n{$paragraph}";

            if (mb_strlen($candidate) > $limit) {
                $chunks[] = $current;
                $current = $paragraph;
                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
