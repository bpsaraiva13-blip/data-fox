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
    <script>
    /* mesma cor de destaque e tema escolhidos em Aparência (guardados neste navegador pela interface) */
    (function(){try{var lk=JSON.parse(localStorage.getItem('datafox.look')||'null');if(!lk)return;
      if(lk.theme==='light'||lk.theme==='dark')document.documentElement.setAttribute('data-theme',lk.theme);
      var h=String(lk.accent||'').replace('#','');if(h.length!==6||h.toLowerCase()==='1f4d36')return;var n=parseInt(h,16);if(isNaN(n))return;
      var raw=[n>>16&255,n>>8&255,n&255],W=[255,255,255],K=[0,0,0],D=[18,20,18];
      var mix=function(a,b,t){return a.map(function(v,i){return Math.round(v+(b[i]-v)*t)})};
      var hex=function(c){return'#'+c.map(function(v){return v.toString(16).padStart(2,'0')}).join('')};
      var lum=function(c){var f=function(v){v/=255;return v<=.03928?v/12.92:Math.pow((v+.055)/1.055,2.4)};return .2126*f(c[0])+.7152*f(c[1])+.0722*f(c[2])};
      var b=raw;for(var i=0;i<20&&lum(b)>.11;i++)b=mix(b,K,.12);
      var a=mix(raw,W,.45);for(var j=0;j<12&&lum(a)<.32;j++)a=mix(a,W,.15);
      var L='--accent:'+hex(b)+';--accent-ink:#fff;--mint:'+hex(mix(b,W,.84))+';--brand:'+hex(b)+';--brand-soft:'+hex(mix(b,W,.84))+';';
      var DK='--accent:'+hex(a)+';--accent-ink:'+hex(mix(raw,K,.85))+';--mint:'+hex(mix(raw,D,.72))+';--brand:'+hex(mix(raw,D,.62))+';--brand-soft:'+hex(mix(a,W,.55))+';';
      document.getElementById('accent-css').textContent=':root{'+L+'}@media (prefers-color-scheme:dark){:root:not([data-theme=light]){'+DK+'}}:root[data-theme=dark]{'+DK+'}';
      var m=document.querySelector('meta[name=theme-color]');if(m)m.content=hex(b);
    }catch(e){}})();
    </script>
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
