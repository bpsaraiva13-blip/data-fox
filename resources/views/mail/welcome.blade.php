<x-mail::message>
# Olá, {{ \Illuminate\Support\Str::of($user->name)->before(' ') }}

{{ $by?->name ?? 'Um administrador' }} criou a sua conta no **{{ $brand }}**@if ($acervos->isNotEmpty()), com acesso a: {{ $acervos->implode(', ') }}@endif.

Clique no botão para criar a sua senha e entrar:

<x-mail::button :url="$link" color="success">
Criar minha senha
</x-mail::button>

O link vale por {{ $days }} dias. Seu e-mail de acesso é **{{ $user->email }}**.

{{ $brand }}
</x-mail::message>
