@extends('public.layout')
@section('title', 'Verificação · Data Fox')
@section('content')
<div class="pb-card pb-narrow">
    <p class="pb-eyebrow">Verificação em duas etapas</p>
    <h1>Digite o código</h1>
    <p class="pb-muted">Você entrou com {{ $via }}. Para concluir, abra o app autenticador e digite o código de 6 números do Data Fox. Sem o celular? Use um dos códigos de recuperação.</p>
    <form method="post" action="{{ route('social.2fa.verify') }}" class="pb-form">@csrf
        <label>Código<input name="code" required autofocus autocomplete="one-time-code" inputmode="text" maxlength="64"></label>
        @if ($errors->any())<p class="pb-warn">{{ $errors->first() }}</p>@endif
        <button class="pb-btn">Entrar</button>
    </form>
    <p class="pb-muted pb-small"><a href="{{ \Filament\Facades\Filament::getLoginUrl() }}">Voltar ao login</a></p>
</div>
@endsection
