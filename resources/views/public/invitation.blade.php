@extends('public.layout')
@section('title', 'Convite · Data Fox')
@section('content')
<div class="pb-card pb-narrow">
    @if ($state === 'invalid')
        <h1>Convite indisponível</h1>
        <p class="pb-muted">Este convite expirou, foi revogado ou já foi usado. Peça um novo convite a quem enviou.</p>
    @else
        <p class="pb-eyebrow">Convite</p>
        <h1>{{ $inv->acervo->name }}</h1>
        <p class="pb-muted">{{ $inv->inviter?->name ?? 'Um administrador' }} convidou {!! $inv->email ? '<b>'.e($inv->email).'</b>' : 'você' !!} como <b>{{ \App\Models\Acervo::ROLES[$inv->role] ?? $inv->role }}</b>.</p>

        @if ($state === 'accept')
            @if ($inv->email && strcasecmp($user->email, $inv->email) !== 0)
                <p class="pb-warn">Você está conectado como {{ $user->email }}. O convite foi enviado para {{ $inv->email }}, mas pode ser aceito nesta conta.</p>
            @endif
            <form method="post" action="{{ route('invitations.accept', $inv->token) }}">@csrf
                <button class="pb-btn">Aceitar convite</button>
            </form>
        @elseif ($state === 'login')
            <p>Você já tem conta. Entre com <b>{{ $inv->email }}</b> e abra este link de novo para aceitar.</p>
            <a class="pb-btn" href="{{ $loginUrl }}">Entrar</a>
            @include('filament.social-buttons', ['invite' => $inv->token])
        @else
            @include('filament.social-buttons', ['invite' => $inv->token])
            @unless ($inv->email)
                <p class="pb-muted pb-small">Já tem conta no Data Fox? <a href="{{ $loginUrl }}">Entre</a> e o convite é aceito em seguida.</p>
            @endunless
            <p class="pb-muted pb-small">Ou crie uma conta com senha:</p>
            <form method="post" action="{{ route('invitations.register', $inv->token) }}" class="pb-form">@csrf
                <label>Seu nome<input name="name" value="{{ old('name', $inv->name) }}" required autocomplete="name"></label>
                @if ($inv->email)
                    <label>E-mail<input value="{{ $inv->email }}" disabled></label>
                @else
                    <label>Seu e-mail<input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
                @endif
                <label>Crie uma senha<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
                <label>Repita a senha<input type="password" name="password_confirmation" required autocomplete="new-password"></label>
                @if ($errors->any())<p class="pb-warn">{{ $errors->first() }}</p>@endif
                <button class="pb-btn">Criar conta e aceitar</button>
            </form>
        @endif
        <p class="pb-muted pb-small">Válido até {{ $inv->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}.</p>
    @endif
</div>
@endsection
