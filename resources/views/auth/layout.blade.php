@php($brand = \App\Support\Site::public())
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#1F4D36">
    <title>@yield('title', 'Entrar') · {{ $brand['name'] }}</title>
    <link rel="icon" href="{{ \App\Support\Site::iconUrl() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@600&display=swap">
    <link rel="stylesheet" href="{{ asset('css/datafox-auth.css') }}?v={{ @filemtime(public_path('css/datafox-auth.css')) }}">
    <style id="accent-css"></style>
    {{-- mesma cor de destaque e tema de Aparência (guardados neste navegador pela interface) --}}
    <script src="{{ asset('js/datafox-look.js') }}?v={{ @filemtime(public_path('js/datafox-look.js')) }}"></script>
</head>
<body>
<div class="au">
    <aside class="au-brand" aria-hidden="true">
        <a class="au-logo" href="{{ url('/') }}">@include('auth.brandmark')</a>
        <div class="au-tiles"><span>01</span><span>02</span><span>03</span><span>04</span><span>05</span><span>06</span><span>07</span><span>08</span><span>09</span></div>
        <div class="au-pitch">
            @if ($brand['tagline'])<h2>{{ $brand['tagline'] }}</h2>@endif
            <ul>
                <li><i>✦</i>Fichas preenchidas pela IA a partir das fotos</li>
                <li><i>▣</i>Caixas, locais e conferência do que está guardado</li>
                <li><i>⚇</i>Equipe com permissões, do jeito que você definir</li>
            </ul>
        </div>
        <p class="au-foot">{{ $brand['name'] }} · gestão de acervo</p>
    </aside>
    <main class="au-panel">
        <div class="au-card">
            <a class="au-mobile-logo" href="{{ url('/') }}">@include('auth.brandmark')</a>
            @yield('content')
        </div>
    </main>
</div>
@stack('scripts')
</body>
</html>
