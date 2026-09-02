#!/usr/bin/env bash
# Avvia tutto lo stack di sviluppo locale: Ollama, ChromaDB, Laravel, il
# worker della coda e un tunnel ngrok. Salta i servizi già attivi invece di
# duplicarli. Alla fine stampa l'URL pubblico ngrok da usare per gli Slash
# Command Slack.
set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHROMA_VENV="$HOME/chroma-server/venv"
CHROMA_DATA="$HOME/chroma-server/data"
RUN_DIR="$PROJECT_DIR/.run"
mkdir -p "$RUN_DIR"

is_up() { curl -s -o /dev/null -m 3 "$1"; }

echo "== Ollama =="
if is_up http://localhost:11434/api/tags; then
    echo "già attivo"
else
    nohup ollama serve > "$RUN_DIR/ollama.log" 2>&1 &
    echo $! > "$RUN_DIR/ollama.pid"
    disown
    echo "avviato (pid $(cat "$RUN_DIR/ollama.pid"))"
fi

echo "== ChromaDB =="
if is_up http://localhost:8000/api/v2/heartbeat; then
    echo "già attivo"
else
    nohup "$CHROMA_VENV/bin/chroma" run --path "$CHROMA_DATA" --port 8000 > "$RUN_DIR/chroma.log" 2>&1 &
    echo $! > "$RUN_DIR/chroma.pid"
    disown
    echo "avviato (pid $(cat "$RUN_DIR/chroma.pid"))"
fi

echo "== Laravel (porta 8080) =="
if is_up http://localhost:8080/admin/login; then
    echo "già attivo"
else
    cd "$PROJECT_DIR"
    nohup php artisan serve --port=8080 > "$RUN_DIR/laravel.log" 2>&1 &
    echo $! > "$RUN_DIR/laravel.pid"
    disown
    echo "avviato (pid $(cat "$RUN_DIR/laravel.pid"))"
fi

echo "== Queue worker =="
if pgrep -f "queue:work" > /dev/null; then
    echo "già attivo"
else
    cd "$PROJECT_DIR"
    nohup php artisan queue:work > "$RUN_DIR/queue.log" 2>&1 &
    echo $! > "$RUN_DIR/queue.pid"
    disown
    echo "avviato (pid $(cat "$RUN_DIR/queue.pid"))"
fi

echo "== ngrok (tunnel verso 8080) =="
if curl -s http://localhost:4040/api/tunnels 2>/dev/null | grep -q "public_url"; then
    echo "già attivo"
else
    nohup ngrok http 8080 --log=stdout > "$RUN_DIR/ngrok.log" 2>&1 &
    echo $! > "$RUN_DIR/ngrok.pid"
    disown
    echo "avviato, attendo l'URL pubblico..."
    sleep 4
fi

URL=$(curl -s http://localhost:4040/api/tunnels 2>/dev/null \
    | python3 -c "import json,sys; d=json.load(sys.stdin); print(d['tunnels'][0]['public_url'])" 2>/dev/null \
    || echo "non disponibile — controlla $RUN_DIR/ngrok.log")

echo ""
echo "=================================================="
echo "URL pubblico ngrok : $URL"
echo "Slash command Slack: $URL/slack/commands"
echo "(se è cambiato rispetto a prima, aggiornalo su api.slack.com)"
echo "=================================================="
