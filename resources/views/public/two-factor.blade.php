@extends('auth.layout')
@section('title', 'Verificação')
@section('content')
    <p class="au-eyebrow">Verificação em duas etapas</p>
    <h1>Digite o código</h1>
    <p class="au-lead">Você entrou com {{ $via }}. Abra o app autenticador e digite o código de 6 números do Data Fox. Sem o celular? Use um código de recuperação.</p>
    @if ($errors->any())<p class="au-err" role="alert">{{ $errors->first() }}</p>@endif
    <form method="post" action="{{ route('social.2fa.verify') }}" class="au-form">@csrf
        <div class="au-f">
            <label for="code">Código</label>
            <input id="code" type="text" name="code" class="au-code" required autofocus autocomplete="one-time-code" inputmode="numeric" maxlength="64">
        </div>
        <button class="au-btn">Confirmar e entrar</button>
    </form>
    <p class="au-note"><a href="{{ route('login') }}">Voltar para a tela de entrar</a></p>
@endsection
