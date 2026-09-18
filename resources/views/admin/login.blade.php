<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>CompassBot — Accesso</title>
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
            --cb-error: #f87171;
            --cb-error-bg: rgba(248, 113, 113, 0.12);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(50rem 34rem at 12% -8%, rgba(168, 85, 247, 0.16) 0%, transparent 60%),
                radial-gradient(46rem 32rem at 108% 112%, rgba(56, 189, 248, 0.14) 0%, transparent 55%),
                var(--cb-bg);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: var(--cb-text);
            padding: 1.5rem;
        }
        .card {
            width: 100%;
            max-width: 460px;
            background: var(--cb-card);
            border: 1px solid var(--cb-border);
            border-radius: 24px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2), 0 24px 48px -12px rgba(0, 0, 0, 0.5);
            padding: 3rem 3rem 2.5rem;
            text-align: center;
        }
        .brand { margin-bottom: 2.25rem; display: flex; justify-content: center; }
        form { text-align: left; }
        label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--cb-text);
            margin-bottom: 0.5rem;
        }
        input[type=password] {
            width: 100%;
            padding: 0.85rem 1rem;
            border: 1.5px solid var(--cb-border);
            border-radius: 12px;
            background: var(--cb-input-bg);
            color: var(--cb-text);
            font-family: inherit;
            font-size: 1rem;
            margin-bottom: 1.4rem;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        input[type=password]:focus {
            outline: none;
            border-color: var(--cb-purple);
            box-shadow: 0 0 0 4px rgba(168, 85, 247, 0.2);
        }
        button {
            width: 100%;
            padding: 0.9rem;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--cb-purple), var(--cb-blue));
            color: #0d0b1f;
            font-family: 'Space Grotesk', ui-sans-serif, sans-serif;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            box-shadow: 0 8px 20px -6px rgba(168, 85, 247, 0.5);
            transition: transform 0.1s ease, box-shadow 0.15s ease, filter 0.15s ease;
        }
        button:hover { filter: brightness(1.08); box-shadow: 0 10px 24px -6px rgba(56, 189, 248, 0.55); }
        button:active { transform: translateY(1px); }
        .error {
            background: var(--cb-error-bg);
            color: var(--cb-error);
            border-radius: 10px;
            padding: 0.7rem 0.9rem;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 1.2rem;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">
            @include('partials.compass-logo', ['size' => 96, 'layout' => 'stack'])
        </div>

        @error('password')
            <div class="error">{{ $message }}</div>
        @enderror

        <form method="POST" action="{{ route('admin.login.post') }}">
            @csrf
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autofocus>
            <button type="submit">Accedi</button>
        </form>
    </div>
</body>
</html>
