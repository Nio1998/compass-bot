# CompassBot

A RAG-based (Retrieval-Augmented Generation) Slack bot for the **Software Project Management (GPS)** university course, built as a thesis project. Runs entirely locally: no calls to paid external LLM services, no data leaves the machine it runs on.

## What it does

Two Slack slash commands:

- **`/gps-domanda`** — a student asks a natural-language question about the course material; the bot answers by retrieving the relevant passages from the course slides (indexed as a vector corpus) and generating an answer in Italian with a local LLM.
- **`/gps-valida`** — a student opens a Slack modal, picks the type of project management document they produced (WBS, Project Charter, Risk Management Plan, Meeting Minutes, etc. — 17 supported types) and attaches the PDF. The bot compares the document against the course slides and a real example project provided by the professor, and returns structured feedback: structural errors, missing elements, suggestions.

## Architecture

```
Slack (slash command / modal)
        │
        ▼
Laravel 13 (PHP 8.4) ── queue worker (queue:work) so Slack's response doesn't block
        │
        ▼
NeuronAI (RAG framework)
        │
   ┌────┴────┐
   ▼         ▼
Ollama    ChromaDB (self-hosted, vector store)
(LLM +      │
embedding)  ├── collection "gps_slides"           → used ONLY by /gps-domanda
            └── collection "gps_validation_refs"   → used ONLY by /gps-valida
```

- **Ollama** runs locally and provides both the generation model (`llama3`) and the embedding model (`nomic-embed-text`).
- **ChromaDB** runs locally (a Python server via virtualenv, not Docker) and keeps two separate collections: the course slides are never touched by the document-validation flow, and vice versa.
- **ngrok** exposes the local Laravel endpoint to Slack during development (in production this would be replaced with a real public domain).

## Project structure

```
app/
├── Rag/                        RAG logic and application domain
│   ├── GpsQaBot.php                /gps-domanda bot
│   ├── GpsDocumentValidator.php    /gps-valida bot (retrieval filtered by document type)
│   ├── DocumentTypes.php           mapping of the 17 document types → relevant sources
│   ├── ValidationFeedback.php      schema for the validator's structured output
│   ├── ChromaFilteredVectorStore.php  vector store with a filter on source file name
│   ├── CompositeVectorStore.php    merges slides + example project into a single search
│   ├── TranslateToItalian.php      post-hoc translation to keep answers in Italian
│   ├── PrivacyRedactor.php         redacts real names/project name before sending to Slack
│   └── SmalotPdfReader.php         text extraction from uploaded PDFs
├── Http/Controllers/
│   ├── SlackCommandController.php      receives the slash commands
│   ├── SlackInteractionController.php  receives the /gps-valida modal submission
│   └── Admin/                          web panel to upload/manage indexed slides
├── Jobs/                        Queued processing (Ollama can take longer than the 3s Slack allows)
│   ├── ProcessGpsQuestionJob.php
│   └── ProcessGpsValidationFileJob.php
├── Services/
│   ├── SlackApi.php             authenticated calls to the Slack API (modals, file download, messages)
│   └── SlackResponder.php       sends the reply via the slash command's response_url
└── Console/Commands/            CLI commands for corpus import and testing
scripts/                     start/stop the entire local stack
```

## Requirements

- PHP 8.3+ (built with 8.4), Composer
- Python 3.12+ (for ChromaDB)
- [Ollama](https://ollama.com) installed locally
- A Slack workspace where you can create an app (slash commands + bot token)
- [ngrok](https://ngrok.com) (or equivalent) to expose the local environment to Slack during development

## Local installation

### 1. Clone and install PHP dependencies

```bash
git clone <repo-url> compass-bot
cd compass-bot
composer install
cp .env.example .env
php artisan key:generate
```

### 2. Set up Ollama

```bash
ollama pull llama3
ollama pull nomic-embed-text
```

Ollama needs to stay running in the background (`ollama serve`, usually already handled by the Ollama app on macOS).

### 3. Set up ChromaDB (self-hosted, no Docker)

```bash
python3 -m venv ~/chroma-server/venv
source ~/chroma-server/venv/bin/activate
pip install chromadb
```

The server is started with `chroma run --path ~/chroma-server/data --port 8000` (`scripts/start-local.sh` already does this automatically).

### 4. Database and queue

```bash
php artisan migrate
```

The project uses SQLite (`DB_CONNECTION=sqlite`) and a database-backed queue (`QUEUE_CONNECTION=database`) — no Redis required.

### 5. Configure `.env`

Keys to set (see `.env.example` for the full list):

| Variable | Description |
|---|---|
| `SLACK_SIGNING_SECRET` | Slack app signing secret, used to verify requests really come from Slack |
| `SLACK_BOT_USER_OAUTH_TOKEN` | Bot Token OAuth (`xoxb-...`), with scopes `commands`, `chat:write`, `im:write`, `files:read` |
| `OLLAMA_URL` / `OLLAMA_MODEL` / `OLLAMA_EMBEDDING_MODEL` | Ollama endpoint and models |
| `CHROMA_HOST` / `CHROMA_COLLECTION` / `CHROMA_VALIDATION_COLLECTION` | ChromaDB endpoint and the two collection names |
| `ADMIN_PASSWORD` | Password for the `/admin` panel used to upload slides |

### 6. Create the Slack app

On your test Slack workspace, create an app with:
- two slash commands, `/gps-domanda` and `/gps-valida`, both pointed at `https://<public-url>/slack/commands`
- an Interactivity Request URL pointed at `https://<public-url>/slack/interactions` (needed for the `/gps-valida` modal)
- Bot Token Scopes: `commands`, `chat:write`, `im:write`, `files:read`

### 7. Start everything

```bash
scripts/start-local.sh
```

The script starts (if not already running) Ollama, ChromaDB, the Laravel server (port 8080), the queue worker, and an ngrok tunnel, and prints the public URL to use in the Slack app configuration. To stop everything: `scripts/stop-local.sh`. After any code change that affects bot logic, restart only the queue worker with `scripts/restart-queue.sh` (the worker loads PHP classes once at startup and won't pick up changed files on its own).

### 8. Index the corpus

```bash
# course slides, used by both /gps-domanda and /gps-valida
php artisan slides:import /path/to/slides

# example project (real reference documents), used only by /gps-valida
php artisan validation-refs:import /path/to/example/project
```

Slides can also be uploaded and indexed from the browser via the `/admin/slides` panel (log in with `ADMIN_PASSWORD`).

## Useful commands

| Command | What it does |
|---|---|
| `php artisan slides:import <folder>` | Bulk-imports and indexes the slide PDFs |
| `php artisan validation-refs:import <folder>` | Imports and indexes the example project's reference documents |
| `php artisan test:gps-domanda` | Runs a set of test questions against `/gps-domanda` and prints the answers |
| `php artisan test:gps-valida <sample-pdf-folder>` | Checks retrieval and validates a sample PDF for all 17 document types |

## Known limitations

The local LLM (8 billion parameters, chosen to stay offline and free of cost) does not guarantee stable behavior for identical inputs: the same document, run twice, can produce meaningfully different results. Several mitigations have been introduced (structured output via JSON schema, automatic retries, post-hoc translation, a privacy filter for real names) but the underlying non-determinism remains a structural limitation, not a fixable bug. Full details, test methodology, and data are in the validation reports produced during development.

## Author

Antonio De Lucia — thesis project, University of Salerno.
