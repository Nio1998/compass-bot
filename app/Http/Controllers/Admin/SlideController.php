<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SlideDocument;
use App\Rag\GpsQaBot;
use App\Rag\SmalotPdfReader;
use App\Rag\TranslateToItalian;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\Splitter\SentenceTextSplitter;
use Throwable;

/**
 * Mini pannello per caricare le slide del prof. Palomba (PDF) e indicizzarle
 * nel vector store usato da GpsQaBot / GpsDocumentValidator. L'upload e
 * l'ingestione sono due passaggi separati: prima si carica il file, poi si
 * avvia manualmente l'indicizzazione (bottone "Ingest"), così l'admin può
 * controllare quando far partire l'operazione (embeddings + scrittura su
 * disco possono richiedere qualche secondo per file).
 */
class SlideController extends Controller
{
    private const SOURCE_TYPE = 'slide';

    public function index(): View
    {
        return view('admin.slides.index', [
            'slides' => SlideDocument::latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
        ]);

        $file = $request->file('pdf');
        $storedPath = $file->store('slides', 'local');

        SlideDocument::create([
            'original_name' => $file->getClientOriginalName(),
            'path'          => $storedPath,
            'status'        => 'pending',
        ]);

        return back()->with('status', "Caricato \"{$file->getClientOriginalName()}\". Ora puoi avviare l'ingestione.");
    }

    public function ingest(SlideDocument $slide): RedirectResponse
    {
        try {
            $absolutePath = Storage::disk('local')->path($slide->path);

            $documents = FileDataLoader::for($absolutePath)
                ->addReader('pdf', new SmalotPdfReader())
                ->withSplitter(new SentenceTextSplitter(maxWords: 200, overlapWords: 20, minWords: 20))
                ->getDocuments();

            $sample = implode(' ', array_map(fn ($d) => $d->content, array_slice($documents, 0, 3)));
            $needsTranslation = !TranslateToItalian::looksItalian($sample);

            foreach ($documents as $document) {
                if ($needsTranslation) {
                    $document->content = TranslateToItalian::translate($document->content);
                }
                $document->sourceType = self::SOURCE_TYPE;
                $document->sourceName = $slide->original_name;
            }

            $bot = GpsQaBot::make();
            // Rimuove eventuali chunk di una precedente ingestione della stessa slide prima di riscrivere.
            $bot->resolveVectorStore()->deleteBy(self::SOURCE_TYPE, $slide->original_name);
            $bot->addDocuments($documents);

            $slide->update([
                'status'      => 'ingested',
                'chunk_count' => count($documents),
                'error'       => null,
                'ingested_at' => now(),
            ]);

            return back()->with('status', "\"{$slide->original_name}\" indicizzata: " . count($documents) . ' passaggi.');
        } catch (Throwable $e) {
            $slide->update(['status' => 'failed', 'error' => $e->getMessage()]);

            return back()->withErrors(['ingest' => "Ingestione fallita per \"{$slide->original_name}\": {$e->getMessage()}"]);
        }
    }

    public function destroy(SlideDocument $slide): RedirectResponse
    {
        GpsQaBot::make()->resolveVectorStore()->deleteBy(self::SOURCE_TYPE, $slide->original_name);
        Storage::disk('local')->delete($slide->path);
        $slide->delete();

        return back()->with('status', 'Slide rimossa.');
    }
}
