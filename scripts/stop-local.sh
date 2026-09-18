#!/usr/bin/env bash
# Ferma tutti i servizi dello stack locale, per nome di processo — funziona
# indipendentemente da come sono stati avviati (via start-local.sh o a mano),
# a differenza di un approccio basato solo sui PID salvati in .run/.
set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUN_DIR="$PROJECT_DIR/.run"

kill_by_pattern() {
    local name="$1" pattern="$2"
    if pgrep -f "$pattern" > /dev/null 2>&1; then
        pkill -f "$pattern"
        echo "$name fermato"
    else
        echo "$name: non era attivo"
    fi
}

kill_by_pattern "Queue worker" "queue:work"
kill_by_pattern "Laravel (artisan serve)" "artisan serve --port=8080"
kill_by_pattern "ngrok" "ngrok http 8080"
kill_by_pattern "ChromaDB" "chroma run"
kill_by_pattern "Ollama" "ollama serve"

rm -f "$RUN_DIR"/*.pid 2>/dev/null

echo ""
echo "Tutto fermato. Per ripartire domani: scripts/start-local.sh"
