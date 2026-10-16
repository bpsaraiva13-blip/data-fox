@extends('auth.layout')
@section('title', 'Convite')
@section('content')
    @if ($state === 'invalid')
        <p class="au-eyebrow">Convite</p>
        <h1>Convite indisponível</h1>
        <p class="au-lead">Este convite expirou, foi revogado ou já foi usado. Peça um novo link a quem enviou.</p>
        <a class="au-btn ghost" href="{{ route('login') }}">Ir para a tela de entrar</a>
    @else
        <p class="au-eyebrow">Convite para o acervo</p>
        <h1>{{ $inv->acervo->name }}</h1>
        <p class="au-lead">{{ $inv->inviter?->name ?? 'Um administrador' }} convidou {!! $inv->email ? '<b>'.e($inv->email).'</b>' : 'você' !!} para participar como
            <span class="au-chip">{{ \App\Models\Acervo::ROLES[$inv->role] ?? $inv->role }}</span></p>
        @if ($errors->any())<p class="au-err" role="alert">{{ $errors->first() }}</p>@endif
        @if (session('social_error'))<p class="au-err" role="alert">{{ session('social_error') }}</p>@endif

        @if ($state === 'accept')
            @if ($inv->email && strcasecmp($user->email, $inv->email) !== 0)
                <p class="au-ok">Você está conectado como {{ $user->email }}. O convite foi enviado para {{ $inv->email }}, mas pode ser aceito nesta conta.</p>
            @endif
            <form method="post" action="{{ route('invitations.accept', $inv->token) }}">@csrf
                <button class="au-btn">Aceitar e abrir o acervo</button>
            </form>
        @elseif ($state === 'login')
            <p class="au-lead">Você já tem conta com <b>{{ $inv->email }}</b>. Entre e o convite é aceito em seguida.</p>
            @include('auth.social', ['invite' => $inv->token, 'sep' => 'ou'])
            <a class="au-btn" href="{{ $loginUrl }}">Entrar com e-mail e senha</a>
        @else
            @include('auth.social', ['invite' => $inv->token, 'sep' => 'ou crie uma senha'])
            <form method="post" action="{{ route('invitations.register', $inv->token) }}" class="au-form">@csrf
                <div class="au-f"><label for="name">Seu nome</label><input id="name" type="text" name="name" value="{{ old('name', $inv->name) }}" required autocomplete="name"></div>
                @if ($inv->email)
                    <div class="au-f"><label>E-mail</label><input type="email" value="{{ $inv->email }}" disabled></div>
                @else
                    <div class="au-f"><label for="email">Seu e-mail</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" inputmode="email"></div>
                @endif
                <div class="au-f"><label for="password">Crie uma senha</label><input id="password" type="password" name="password" required minlength="8" autocomplete="new-password"></div>
                <div class="au-f"><label for="password_confirmation">Repita a senha</label><input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"></div>
                <button class="au-btn">Criar conta e entrar no acervo</button>
            </form>
            @unless ($inv->email)
                <p class="au-note">Já tem conta no Data Fox? <a href="{{ $loginUrl }}">Entre</a> e o convite é aceito em seguida.</p>
            @endunless
        @endif
        <p class="au-note">Convite válido até {{ $inv->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}.</p>
    @endif
@endsection
