<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>CompassBot — Slide del corso</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --cb-bg: #0d0b1f;
            --cb-card: #15132e;
            --cb-border: #2a2650;
            --cb-purple: #a855f7;
            --cb-blue: #38bdf8;
            --cb-text: #f5f3ff;
            --cb-muted: #9490c2;
            --cb-input-bg: #1c1a3a;
            --cb-ok: #34d399;
            --cb-ok-bg: rgba(52, 211, 153, 0.12);
            --cb-warn: #fbbf24;
            --cb-warn-bg: rgba(251, 191, 36, 0.12);
            --cb-error: #f87171;
            --cb-error-bg: rgba(248, 113, 113, 0.12);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(50rem 26rem at 8% -10%, rgba(168, 85, 247, 0.14) 0%, transparent 55%),
                radial-gradient(40rem 24rem at 100% 0%, rgba(56, 189, 248, 0.1) 0%, transparent 55%),
                var(--cb-bg);
            color: var(--cb-text);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            padding: 3rem 1.5rem;
        }
        .wrap { max-width: 920px; margin: 0 auto; }
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2.5rem;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .brand { display: flex; align-items: center; gap: 1rem; }
        .brand .tagline {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--cb-muted);
            padding-left: 1rem;
            border-left: 1.5px solid var(--cb-border);
        }
        .logout-form button {
            background: var(--cb-card);
            border: 1.5px solid var(--cb-border);
            border-radius: 999px;
            color: var(--cb-text);
            font-family: inherit;
            font-weight: 600;
            font-size: 0.85rem;
            padding: 0.55rem 1.2rem;
            cursor: pointer;
            transition: border-color 0.15s ease, background 0.15s ease;
        }
        .logout-form button:hover { border-color: var(--cb-purple); background: #1b1840; }

        .flash {
            background: var(--cb-ok-bg);
            border-left: 4px solid var(--cb-ok);
            color: var(--cb-ok);
            padding: 0.9rem 1.2rem;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
        }
        .flash-error {
            background: var(--cb-error-bg);
            border-left-color: var(--cb-error);
            color: var(--cb-error);
        }

        .card {
            background: var(--cb-card);
            border: 1px solid var(--cb-border);
            border-radius: 20px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2), 0 16px 40px -16px rgba(0, 0, 0, 0.5);
        }
        form.upload {
            display: flex;
            gap: 1rem;
            align-items: center;
            padding: 1.5rem 1.75rem;
        }
        input[type=file] {
            flex: 1;
            font-family: inherit;
            font-size: 0.9rem;
            color: var(--cb-text);
        }
        form.upload button {
            background: linear-gradient(135deg, var(--cb-purple), var(--cb-blue));
            color: #0d0b1f;
            border: none;
            border-radius: 12px;
            padding: 0.75rem 1.4rem;
            font-family: 'Space Grotesk', ui-sans-serif, sans-serif;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            white-space: nowrap;
            box-shadow: 0 8px 18px -6px rgba(168, 85, 247, 0.45);
            transition: filter 0.15s ease;
        }
        form.upload button:hover { filter: brightness(1.08); }

        table { width: 100%; border-collapse: collapse; }
        thead th {
            text-align: left;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--cb-muted);
            padding: 1.1rem 1.75rem 0.75rem;
            border-bottom: 1px solid var(--cb-border);
        }
        tbody td {
            padding: 1.05rem 1.75rem;
            border-bottom: 1px solid var(--cb-border);
            font-size: 0.95rem;
            vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }

        .status {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-pending { background: var(--cb-warn-bg); color: var(--cb-warn); }
        .status-ingested { background: var(--cb-ok-bg); color: var(--cb-ok); }
        .status-failed { background: var(--cb-error-bg); color: var(--cb-error); }

        .actions { display: flex; gap: 0.6rem; }
        .actions form { margin: 0; }
        .actions button {
            border-radius: 10px;
            padding: 0.5rem 0.95rem;
            font-family: inherit;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: filter 0.15s ease, background 0.15s ease;
        }
        button.ingest { background: linear-gradient(135deg, var(--cb-purple), var(--cb-blue)); color: #0d0b1f; border: none; }
        button.ingest:hover { filter: brightness(1.08); }
        button.delete { background: none; color: var(--cb-error); border: 1.5px solid rgba(248, 113, 113, 0.4); }
        button.delete:hover { background: var(--cb-error-bg); }
        .empty { color: var(--cb-muted); padding: 2rem 1.75rem; text-align: center; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div class="brand">
            @include('partials.compass-logo', ['size' => 44, 'layout' => 'row'])
            <span class="tagline">Slide del corso GPS</span>
        </div>
        <form class="logout-form" method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button type="submit">Esci</button>
        </form>
    </header>

    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif
    @error('ingest')
        <div class="flash flash-error">{{ $message }}</div>
    @enderror

    <div class="card">
        <form class="upload" method="POST" action="{{ route('admin.slides.store') }}" enctype="multipart/form-data">
            @csrf
            <input type="file" name="pdf" accept="application/pdf" required>
            <button type="submit">Carica PDF</button>
        </form>
    </div>

    <div class="card" style="margin-top: 1.5rem;">
        <table>
            <thead>
                <tr><th>Nome file</th><th>Stato</th><th>Passaggi</th><th></th></tr>
            </thead>
            <tbody>
            @forelse ($slides as $slide)
                <tr>
                    <td>{{ $slide->original_name }}</td>
                    <td><span class="status status-{{ $slide->status }}">{{ $slide->status }}</span></td>
                    <td>{{ $slide->chunk_count }}</td>
                    <td class="actions">
                        <form method="POST" action="{{ route('admin.slides.ingest', $slide) }}">
                            @csrf
                            <button class="ingest" type="submit">Ingest</button>
                        </form>
                        <form method="POST" action="{{ route('admin.slides.destroy', $slide) }}" onsubmit="return confirm('Rimuovere questa slide e i relativi passaggi indicizzati?')">
                            @csrf
                            @method('DELETE')
                            <button class="delete" type="submit">Rimuovi</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Nessuna slide caricata.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
