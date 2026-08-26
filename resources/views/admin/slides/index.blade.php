<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>GPS Support Tool — Slide</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; padding: 2rem; }
        .wrap { max-width: 800px; margin: 0 auto; }
        h1 { font-size: 1.3rem; }
        header { display: flex; justify-content: space-between; align-items: baseline; }
        a.logout { color: #94a3b8; font-size: 0.85rem; }
        form.upload { background: #1e293b; padding: 1.2rem; border-radius: 8px; margin: 1.5rem 0; display: flex; gap: 0.6rem; align-items: center; }
        input[type=file] { color: #e2e8f0; flex: 1; }
        button { padding: 0.5rem 1rem; border: none; border-radius: 4px; background: #2563eb; color: white; font-weight: 600; cursor: pointer; }
        button.ingest { background: #16a34a; }
        button.delete { background: #dc2626; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 0.6rem; border-bottom: 1px solid #334155; font-size: 0.9rem; }
        .status { padding: 0.15rem 0.5rem; border-radius: 999px; font-size: 0.75rem; }
        .status-pending { background: #78350f; }
        .status-ingested { background: #14532d; }
        .status-failed { background: #7f1d1d; }
        .flash { background: #14532d; padding: 0.7rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }
        .flash-error { background: #7f1d1d; }
        .actions { display: flex; gap: 0.4rem; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <h1>Slide del corso GPS</h1>
        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button type="submit" style="background:none;border:none;color:#94a3b8;cursor:pointer;padding:0;">Esci</button>
        </form>
    </header>

    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif
    @error('ingest')
        <div class="flash flash-error">{{ $message }}</div>
    @enderror

    <form class="upload" method="POST" action="{{ route('admin.slides.store') }}" enctype="multipart/form-data">
        @csrf
        <input type="file" name="pdf" accept="application/pdf" required>
        <button type="submit">Carica PDF</button>
    </form>

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
            <tr><td colspan="4">Nessuna slide caricata.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
</body>
</html>
