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
            --cb-graphite: #2e2a26;
            --cb-amber: #d1701f;
            --cb-paper: #f4ede0;
            --cb-card: #ffffff;
            --cb-border: #ece3d4;
            --cb-muted: #948a79;
            --cb-error: #b3261e;
            --cb-error-bg: #fbeae8;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(60rem 40rem at 15% -10%, #fbf1e2 0%, transparent 60%),
                radial-gradient(50rem 35rem at 110% 110%, #f6e3cd 0%, transparent 55%),
                var(--cb-paper);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: var(--cb-graphite);
            padding: 1.5rem;
        }
        .card {
            width: 100%;
            max-width: 460px;
            background: var(--cb-card);
            border-radius: 24px;
            box-shadow: 0 1px 2px rgba(46, 42, 38, 0.04), 0 24px 48px -12px rgba(46, 42, 38, 0.16);
            padding: 3rem 3rem 2.5rem;
            text-align: center;
        }
        .brand { margin-bottom: 2.25rem; display: flex; justify-content: center; }
        form { text-align: left; }
        label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--cb-graphite);
            margin-bottom: 0.5rem;
        }
        input[type=password] {
            width: 100%;
            padding: 0.85rem 1rem;
            border: 1.5px solid var(--cb-border);
            border-radius: 12px;
            background: var(--cb-paper);
            color: var(--cb-graphite);
            font-family: inherit;
            font-size: 1rem;
            margin-bottom: 1.4rem;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        input[type=password]:focus {
            outline: none;
            border-color: var(--cb-amber);
            box-shadow: 0 0 0 4px rgba(209, 112, 31, 0.15);
            background: var(--cb-card);
        }
        button {
            width: 100%;
            padding: 0.9rem;
            border: none;
            border-radius: 12px;
            background: var(--cb-amber);
            color: #fff;
            font-family: 'Space Grotesk', ui-sans-serif, sans-serif;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            box-shadow: 0 8px 18px -6px rgba(209, 112, 31, 0.55);
            transition: transform 0.1s ease, box-shadow 0.15s ease, filter 0.15s ease;
        }
        button:hover { filter: brightness(1.05); box-shadow: 0 10px 22px -6px rgba(209, 112, 31, 0.6); }
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
