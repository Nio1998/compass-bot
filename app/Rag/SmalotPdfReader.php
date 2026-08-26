<?php

declare(strict_types=1);

namespace App\Rag;

use NeuronAI\RAG\DataLoader\ReaderInterface;
use Smalot\PdfParser\Parser;

/**
 * Estrae il testo da un PDF usando smalot/pdfparser (puro PHP).
 *
 * Il reader "PdfReader" incluso in neuron-core/neuron-ai richiede il binario
 * di sistema `pdftotext` (poppler-utils), non disponibile su questa macchina.
 * smalot/pdfparser evita quella dipendenza esterna.
 */
class SmalotPdfReader implements ReaderInterface
{
    public static function getText(string $filePath, array $options = []): string
    {
        $document = (new Parser())->parseFile($filePath);

        return $document->getText();
    }
}
