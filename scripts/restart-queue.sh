#!/usr/bin/env bash
# Riavvia SOLO il queue worker. Serve dopo ogni modifica al codice PHP che
# riguarda la logica del bot (prompt, retrieval, traduzione, ecc.): il worker
# è un processo persistente che carica le classi PHP una sola volta all'avvio
# e non si accorge da solo dei file cambiati — a differenza di `php artisan
# serve`, che ricarica il codice a ogni richiesta.
set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUN_DIR="$PROJECT_DIR/.run"
mkdir -p "$RUN_DIR"

echo "== Riavvio queue worker =="
pkill -f "queue:work" 2>/dev/null && echo "fermato il worker precedente" || echo "nessun worker precedente attivo"
sleep 1

cd "$PROJECT_DIR"
nohup php artisan queue:work > "$RUN_DIR/queue.log" 2>&1 &
echo $! > "$RUN_DIR/queue.pid"
disown
sleep 1

if pgrep -f "queue:work" > /dev/null; then
    echo "worker riavviato (pid $(cat "$RUN_DIR/queue.pid"))"
else
    echo "ERRORE: il worker non è ripartito, controlla $RUN_DIR/queue.log"
    exit 1
fi
