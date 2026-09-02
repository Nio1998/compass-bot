<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Rag\GpsQaBot;
use Illuminate\Console\Command;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

/**
 * Lancia un set fisso di domande di test contro GpsQaBot e stampa ogni
 * coppia domanda/risposta, per una revisione manuale rapida della qualità
 * su più argomenti in un colpo solo invece che una domanda alla volta.
 */
class TestGpsDomanda extends Command
{
    protected $signature = 'test:gps-domanda';

    protected $description = 'Esegue un set di domande di test contro GpsQaBot e stampa le risposte per revisione manuale';

    /** @var string[] */
    private const QUESTIONS = [
        "Cos'è la Work Breakdown Structure e a cosa serve?",
        "Quali sono i vantaggi dello sviluppo incrementale?",
        'Come si calcola lo Schedule Performance Index (SPI) nell\'Earned Value Management?',
        'Cosa sono i Community Smells e perché sono importanti in un team?',
        'Come funziona la tecnica del Planning Poker in Scrum?',
        'Cosa deve contenere un business case?',
        'Quali sono le differenze tra rischio e problema in un progetto?',
        'Cosa si intende per virtual team e quali sono le sue caratteristiche?',
        'Come si strutturano i ruoli in una retrospettiva di sprint?',
        'Come si crea un nuovo progetto in Microsoft Project?',
        'Cosa sono i milestone in MS Project e come si inseriscono?',
        'Quali sono le dodici regole della Extreme Programming?',
        'Come si gestisce un conflitto in un team di progetto?',
        'Parlami della gestione dei progetti',
        'Come si fa il project status reporting?',
        'Qual è la ricetta della pizza margherita?',
        'Quali sono i migliori ristoranti di Salerno?',
    ];

    public function handle(): int
    {
        $bot = GpsQaBot::make();

        foreach (self::QUESTIONS as $i => $question) {
            $this->line('=========================================================');
            $this->info('[' . ($i + 1) . '/' . count(self::QUESTIONS) . "] D: {$question}");
            $this->newLine();

            try {
                $start = microtime(true);
                $answer = $bot->chat(new UserMessage($question))->getMessage()->getContent() ?? '';
                $elapsed = round(microtime(true) - $start, 1);

                $this->line("R ({$elapsed}s):");
                $this->line($answer);
            } catch (Throwable $e) {
                $this->error('ERRORE: ' . $e->getMessage());
            }

            $this->newLine();
        }

        $this->line('=========================================================');
        $this->info('Test completato: ' . count(self::QUESTIONS) . ' domande.');

        return self::SUCCESS;
    }
}
