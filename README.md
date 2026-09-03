# CompassBot

Bot Slack basato su RAG (Retrieval-Augmented Generation) per il corso di **Gestione dei Progetti Software (GPS)**, sviluppato come progetto di tesi. Gira interamente in locale: nessuna chiamata verso servizi LLM esterni a pagamento, nessun dato che lascia la macchina su cui è installato.

## Cosa fa

Due slash command Slack:

- **`/gps-domanda`** — lo studente fa una domanda in linguaggio naturale sul programma del corso; il bot risponde recuperando i passaggi pertinenti dalle slide del corso (indicizzate come corpus vettoriale) e generando una risposta in italiano tramite un modello LLM locale.
- **`/gps-valida`** — lo studente apre una modale Slack, sceglie il tipo di documento di project management che ha prodotto (WBS, Project Charter, Risk Management Plan, Minuta, ecc. — 17 tipi supportati) e allega il PDF. Il bot confronta il documento con le slide del corso e con un progetto esempio reale fornito dal docente, e restituisce un feedback strutturato: errori strutturali, elementi mancanti, suggerimenti.

## Architettura

```
Slack (slash command / modale)
        │
        ▼
Laravel 13 (PHP 8.4) ── coda (queue:work) per non bloccare la risposta a Slack
        │
        ▼
NeuronAI (framework RAG)
        │
   ┌────┴────┐
   ▼         ▼
Ollama    ChromaDB (self-hosted, vettori)
(LLM +      │
embedding)  ├── collection "gps_slides"           → usata SOLO da /gps-domanda
            └── collection "gps_validation_refs"   → usata SOLO da /gps-valida
```

- **Ollama** gira in locale e fornisce sia il modello di generazione (`llama3`) sia il modello di embedding (`nomic-embed-text`).
- **ChromaDB** gira in locale (server Python via virtualenv, non Docker) e mantiene due collection separate: le slide del corso non vengono mai toccate dalla validazione documenti, e viceversa.
- **ngrok** espone l'endpoint Laravel locale a Slack durante lo sviluppo (in produzione andrebbe sostituito con un dominio pubblico reale).

## Struttura del progetto

```
app/
├── Rag/                        Logica RAG e dominio applicativo
│   ├── GpsQaBot.php                bot di /gps-domanda
│   ├── GpsDocumentValidator.php    bot di /gps-valida (retrieval filtrato per tipo documento)
│   ├── DocumentTypes.php           mappatura dei 17 tipi documento → fonti pertinenti
│   ├── ValidationFeedback.php      schema dell'output strutturato del validatore
│   ├── ChromaFilteredVectorStore.php  vector store con filtro per nome file sorgente
│   ├── CompositeVectorStore.php    unisce slide + progetto esempio in un'unica ricerca
│   ├── TranslateToItalian.php      traduzione post-hoc per garantire risposte in italiano
│   ├── PrivacyRedactor.php         oscura nomi reali/nome progetto prima dell'invio a Slack
│   └── SmalotPdfReader.php         estrazione testo dai PDF caricati
├── Http/Controllers/
│   ├── SlackCommandController.php      riceve gli slash command
│   ├── SlackInteractionController.php  riceve la submission della modale di /gps-valida
│   └── Admin/                          pannello web per caricare/gestire le slide indicizzate
├── Jobs/                        Elaborazione in coda (Ollama può metterci più dei 3s che Slack concede)
│   ├── ProcessGpsQuestionJob.php
│   └── ProcessGpsValidationFileJob.php
├── Services/
│   ├── SlackApi.php             chiamate autenticate alle API Slack (modali, download file, messaggi)
│   └── SlackResponder.php       invio della risposta tramite response_url dello slash command
└── Console/Commands/            comandi CLI per import corpus e test
scripts/                     avvio/arresto dell'intero stack locale
```

## Requisiti

- PHP 8.3+ (sviluppato con 8.4), Composer
- Python 3.12+ (per ChromaDB)
- [Ollama](https://ollama.com) installato in locale
- Un workspace Slack in cui poter creare una app (slash command + bot token)
- [ngrok](https://ngrok.com) (o equivalente) per esporre l'ambiente locale a Slack durante lo sviluppo

## Installazione locale

### 1. Clona e installa le dipendenze PHP

```bash
git clone <url-repo> compass-bot
cd compass-bot
composer install
cp .env.example .env
php artisan key:generate
```

### 2. Configura Ollama

```bash
ollama pull llama3
ollama pull nomic-embed-text
```

Ollama va lasciato in esecuzione in background (`ollama serve`, di solito già gestito dall'app Ollama su macOS).

### 3. Configura ChromaDB (self-hosted, senza Docker)

```bash
python3 -m venv ~/chroma-server/venv
source ~/chroma-server/venv/bin/activate
pip install chromadb
```

Il server va avviato con `chroma run --path ~/chroma-server/data --port 8000` (lo script `scripts/start-local.sh` lo fa già automaticamente).

### 4. Database e coda

```bash
php artisan migrate
```

Il progetto usa SQLite (`DB_CONNECTION=sqlite`) e una coda su database (`QUEUE_CONNECTION=database`) — non serve Redis.

### 5. Configura `.env`

Chiavi da impostare (vedi `.env.example` per l'elenco completo):

| Variabile | Descrizione |
|---|---|
| `SLACK_SIGNING_SECRET` | Firma dell'app Slack, per verificare che le richieste arrivino davvero da Slack |
| `SLACK_BOT_USER_OAUTH_TOKEN` | Bot Token OAuth (`xoxb-...`), con scope `commands`, `chat:write`, `im:write`, `files:read` |
| `OLLAMA_URL` / `OLLAMA_MODEL` / `OLLAMA_EMBEDDING_MODEL` | Endpoint e modelli Ollama |
| `CHROMA_HOST` / `CHROMA_COLLECTION` / `CHROMA_VALIDATION_COLLECTION` | Endpoint ChromaDB e nomi delle due collection |
| `ADMIN_PASSWORD` | Password del pannello `/admin` per caricare le slide |

### 6. Crea l'app Slack

Sul workspace Slack di test, crea una app con:
- due slash command, `/gps-domanda` e `/gps-valida`, entrambi puntati a `https://<url-pubblico>/slack/commands`
- una Interactivity Request URL puntata a `https://<url-pubblico>/slack/interactions` (serve alla modale di `/gps-valida`)
- Bot Token Scopes: `commands`, `chat:write`, `im:write`, `files:read`

### 7. Avvia tutto

```bash
scripts/start-local.sh
```

Lo script avvia (se non già attivi) Ollama, ChromaDB, il server Laravel (porta 8080), il worker della coda e un tunnel ngrok, e stampa l'URL pubblico da usare nella configurazione dell'app Slack. Per fermare tutto: `scripts/stop-local.sh`. Dopo ogni modifica al codice PHP che riguarda la logica del bot, riavviare solo il worker con `scripts/restart-queue.sh` (il worker carica le classi PHP una sola volta all'avvio e non si accorge da solo dei file cambiati).

### 8. Indicizza il corpus

```bash
# slide del corso, usate da /gps-domanda e /gps-valida
php artisan slides:import /percorso/alle/slide

# progetto esempio (documenti di riferimento reali), usato solo da /gps-valida
php artisan validation-refs:import /percorso/al/progetto/esempio
```

In alternativa alle slide si può usare il pannello `/admin/slides` (login con `ADMIN_PASSWORD`) per caricare e indicizzare i PDF da browser.

## Comandi utili

| Comando | Cosa fa |
|---|---|
| `php artisan slides:import <cartella>` | Importa e indicizza in blocco i PDF delle slide |
| `php artisan validation-refs:import <cartella>` | Importa e indicizza i documenti di riferimento del progetto esempio |
| `php artisan test:gps-domanda` | Esegue un set di domande di test contro `/gps-domanda` e stampa le risposte |
| `php artisan test:gps-valida <cartella-pdf-di-prova>` | Verifica il retrieval e valida un campione di PDF per tutti i 17 tipi documento |

## Limiti noti

Il modello LLM locale (8 miliardi di parametri, scelto per restare offline e senza costi) non garantisce un comportamento stabile a parità di input: stesso documento, esecuzioni diverse possono produrre risultati di qualità sensibilmente diversa. Sono stati introdotti alcuni meccanismi di mitigazione (output strutturato via JSON schema, retry automatico, traduzione post-hoc, filtro di privacy sui nomi reali) ma il non determinismo di fondo resta un limite strutturale, non un bug risolvibile. Dettagli, metodologia di test e dati completi nei report di validazione prodotti durante lo sviluppo.

## Autore

Antonio De Lucia — progetto di tesi, Università degli Studi di Salerno.
