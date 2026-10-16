<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#1F4D36">
    <title>@yield('title', 'Entrar') · Data Fox</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@600&display=swap">
    <link rel="stylesheet" href="{{ asset('css/datafox-auth.css') }}?v={{ @filemtime(public_path('css/datafox-auth.css')) }}">
</head>
<body>
<div class="au">
    <aside class="au-brand" aria-hidden="true">
        <a class="au-logo" href="{{ url('/') }}"><span class="au-mark">DF</span><span><b>Data Fox</b><small>Acervo e raridades</small></span></a>
        <div class="au-tiles"><span>01</span><span>02</span><span>03</span><span>04</span><span>05</span><span>06</span><span>07</span><span>08</span><span>09</span></div>
        <div class="au-pitch">
            <h2>O seu acervo de antiguidades e raridades, organizado peça por peça.</h2>
            <ul>
                <li><i>✦</i>Fichas preenchidas pela IA a partir das fotos</li>
                <li><i>▣</i>Caixas, locais e conferência do que está guardado</li>
                <li><i>⚇</i>Equipe com permissões, do jeito que você definir</li>
            </ul>
        </div>
        <p class="au-foot">Data Fox · gestão de acervo</p>
    </aside>
    <main class="au-panel">
        <div class="au-card">
            <a class="au-mobile-logo" href="{{ url('/') }}"><span class="au-mark">DF</span><span><b>Data Fox</b><small>Acervo e raridades</small></span></a>
            @yield('content')
        </div>
    </main>
</div>
@stack('scripts')
</body>
</html>
