<x-mail::message>
# Olá, {{ \Illuminate\Support\Str::of($user->name)->before(' ') }}

Recebemos um pedido para criar uma nova senha no **{{ $brand }}**.

<x-mail::button :url="$link" color="success">
Criar nova senha
</x-mail::button>

O link vale 60 minutos e só pode ser usado uma vez. Se não foi você, ignore este e-mail: a sua senha continua a mesma.

{{ $brand }}
</x-mail::message>
