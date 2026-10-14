<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Data Fox')</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="{{ asset('css/datafox-public.css') }}?v={{ filemtime(public_path('css/datafox-public.css')) }}">
</head>
<body>
    <header class="pb-head">
        <a href="@yield('home', '#')" class="pb-brand"><span class="pb-mark">DF</span><span><b>@yield('brand', 'Data Fox')</b><small>@yield('brandSub', 'Acervo e raridades')</small></span></a>
        @yield('headRight')
    </header>
    <main class="pb-main">@yield('content')</main>
</body>
</html>
