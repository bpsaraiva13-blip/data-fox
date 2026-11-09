@extends('auth.layout')
@section('title', 'Esqueci a senha')
@section('content')
    <p class="au-eyebrow">Esqueci a senha</p>
    <h1>Criar uma nova senha</h1>
    @if ($mail)
        <p class="au-lead">Digite o e-mail da sua conta. Enviamos um link para você criar uma nova senha.</p>
        @if (session('status'))<p class="au-ok" role="status">{{ session('status') }}</p>@endif
        @if ($errors->any())<p class="au-err" role="alert">{{ $errors->first() }}</p>@endif
        <form method="post" action="{{ route('password.forgot') }}" class="au-form" novalidate>
            @csrf
            <div class="au-f">
                <label for="email">E-mail</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" inputmode="email" autofocus>
            </div>
            <button class="au-btn" type="submit">Enviar link</button>
        </form>
    @else
        <p class="au-lead">Peça a um administrador do sistema um link para trocar a senha: em <b>Usuários</b>, ele abre a sua conta e clica em <b>Gerar link para trocar a senha</b>. O link chega por WhatsApp ou como ele preferir.</p>
    @endif
    <p class="au-note"><a href="{{ route('login') }}">Voltar para a tela de entrar</a></p>
@endsection
