@php($brand = \App\Support\Site::public())
@extends('auth.layout')
@section('title', 'Primeiro acesso')
@section('content')
    @if ($state === 'invalid')
        <p class="au-eyebrow">Link de acesso</p>
        <h1>Este link não vale mais</h1>
        <p class="au-lead">Ele já foi usado ou passou do prazo. Peça um novo a quem administra o {{ $brand['name'] }}, ou entre com a sua senha.</p>
        <a class="au-btn" href="{{ route('login') }}" style="display:block;text-align:center;text-decoration:none">Ir para a tela de entrar</a>
    @else
        <p class="au-eyebrow">{{ $reset ? 'Nova senha' : 'Primeiro acesso' }}</p>
        <h1>Olá, {{ \Illuminate\Support\Str::of($user->name)->before(' ') }}</h1>
        <p class="au-lead">Crie a sua senha para entrar no {{ $brand['name'] }}. Seu e-mail de acesso é <b>{{ $user->email }}</b>.</p>

        @if ($acervos->isNotEmpty())
            <div class="au-acervos" aria-label="Seus acervos">
                @foreach ($acervos as $a)<span class="au-chip">{{ $a['name'] }} · {{ $a['role'] }}</span>@endforeach
            </div>
        @endif
        @if ($errors->any())<p class="au-err" role="alert">{{ $errors->first() }}</p>@endif
        @include('auth.social', ['sep' => 'ou crie uma senha'])

        <form method="post" action="{{ route('welcome.store', ['token' => $token]) }}" class="au-form" novalidate>
            @csrf
            <input type="hidden" name="email" value="{{ $user->email }}">@if ($reset)<input type="hidden" name="r" value="1">@endif
            <div class="au-f">
                <label for="password">Nova senha</label>
                <div class="au-in">
                    <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
                    <button type="button" class="au-eye" data-eye aria-label="Mostrar senha">Mostrar</button>
                </div>
            </div>
            <div class="au-f">
                <label for="password_confirmation">Repita a senha</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>
            <button class="au-btn" type="submit">{{ $reset ? 'Salvar senha e entrar' : 'Criar senha e entrar' }}</button>
        </form>
        <p class="au-note">A senha precisa de pelo menos 8 caracteres. Com o Google, use a conta do mesmo e-mail.</p>
    @endif
@endsection
@push('scripts')
<script>
document.querySelector('[data-eye]')?.addEventListener('click',e=>{const i=document.getElementById('password');const on=i.type==='password';i.type=on?'text':'password';e.currentTarget.textContent=on?'Ocultar':'Mostrar';e.currentTarget.setAttribute('aria-label',on?'Ocultar senha':'Mostrar senha')});
</script>
@endpush
