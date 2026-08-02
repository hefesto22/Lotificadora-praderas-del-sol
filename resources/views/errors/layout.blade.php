<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('codigo') · {{ config('app.name', 'Olympo') }}</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><text y='14' font-size='14'>⚠️</text></svg>">
    <style>
        :root {
            color-scheme: dark;
            --bg: #0a0a0a;
            --card: #171717;
            --border: #262626;
            --text: #f5f5f5;
            --muted: #a3a3a3;
            --primary: #f59e0b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
        }
        .wrap {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .card {
            max-width: 32rem;
            width: 100%;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 1rem;
            padding: 3rem 2.5rem;
            text-align: center;
        }
        .codigo {
            font-size: 5rem;
            font-weight: 800;
            letter-spacing: -0.05em;
            background: linear-gradient(135deg, var(--primary), #d97706);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            line-height: 1;
            margin-bottom: 1rem;
        }
        h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }
        p {
            color: var(--muted);
            line-height: 1.6;
            margin-bottom: 2rem;
        }
        .acciones {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-block;
            padding: 0.625rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 500;
            font-size: 0.875rem;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .btn-primary {
            background: var(--primary);
            color: #18181b;
        }
        .btn-primary:hover { opacity: 0.9; }
        .btn-secondary {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border);
        }
        .btn-secondary:hover { background: var(--border); }
        .footer {
            margin-top: 2rem;
            font-size: 0.75rem;
            color: var(--muted);
        }
        @media (prefers-color-scheme: light) {
            :root {
                --bg: #fafafa;
                --card: #ffffff;
                --border: #e5e5e5;
                --text: #18181b;
                --muted: #71717a;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="codigo">@yield('codigo')</div>
            <h1>@yield('titulo')</h1>
            <p>@yield('mensaje')</p>
            <div class="acciones">
                @yield('acciones')
            </div>
        </div>
        <p class="footer">{{ config('app.name', 'Plantilla Olympo') }}</p>
    </div>
</body>
</html>
