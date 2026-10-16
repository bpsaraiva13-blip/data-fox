@php($brand = \App\Support\Site::public())
@extends('auth.layout')
@section('title', 'Entrar')
@section('content')
    <p class="au-eyebrow">Bem-vindo de volta</p>
    <h1>Entrar no {{ $brand['name'] }}</h1>
    <p class="au-lead">Acesse o seu acervo com a sua conta.</p>

    @if (session('social_error'))<p class="au-err" role="alert">{{ session('social_error') }}</p>@endif
    @if (session('status'))<p class="au-ok">{{ session('status') }}</p>@endif
    @if ($errors->any())<p class="au-err" role="alert">{{ $errors->first() }}</p>@endif

    @include('auth.social')

    <form method="post" action="{{ route('login') }}" class="au-form" novalidate>
        @csrf
        <div class="au-f">
            <label for="email">E-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" inputmode="email" autofocus>
        </div>
        <div class="au-f">
            <label for="password">Senha</label>
            <div class="au-in">
                <input id="password" type="password" name="password" required autocomplete="current-password">
                <button type="button" class="au-eye" data-eye aria-label="Mostrar senha">Mostrar</button>
            </div>
        </div>
        <div class="au-row">
            <label><input type="checkbox" name="remember" value="1" checked> Manter conectado</label>
            <a href="{{ route('filament.admin.auth.password-reset.request') }}">Esqueci a senha</a>
        </div>
        <button class="au-btn" type="submit">Entrar</button>
    </form>

    <p class="au-note">Ainda não tem conta? O acesso é por convite: peça o link a quem administra o acervo.</p>
@endsection
@push('scripts')
<script>
document.querySelector('[data-eye]')?.addEventListener('click',e=>{const i=document.getElementById('password');const on=i.type==='password';i.type=on?'text':'password';e.currentTarget.textContent=on?'Ocultar':'Mostrar';e.currentTarget.setAttribute('aria-label',on?'Ocultar senha':'Mostrar senha')});
</script>
@endpush
