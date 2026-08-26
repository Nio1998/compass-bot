<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>GPS Support Tool — Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        form { background: #1e293b; padding: 2rem; border-radius: 8px; width: 320px; }
        h1 { font-size: 1.1rem; margin-top: 0; }
        input { width: 100%; padding: 0.6rem; margin: 0.5rem 0 1rem; border-radius: 4px; border: 1px solid #334155; background: #0f172a; color: #e2e8f0; box-sizing: border-box; }
        button { width: 100%; padding: 0.6rem; border: none; border-radius: 4px; background: #2563eb; color: white; font-weight: 600; cursor: pointer; }
        .error { color: #f87171; font-size: 0.85rem; margin-bottom: 0.5rem; }
    </style>
</head>
<body>
    <form method="POST" action="{{ route('admin.login.post') }}">
        @csrf
        <h1>GPS Support Tool — Pannello slide</h1>
        @error('password')
            <div class="error">{{ $message }}</div>
        @enderror
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autofocus>
        <button type="submit">Accedi</button>
    </form>
</body>
</html>
