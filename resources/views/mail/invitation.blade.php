<x-mail::message>
# Você foi convidado para o Data Fox

{{ $inv->inviter?->name ?? 'Um administrador' }} convidou você para o acervo **{{ $acervo->name }}**, como **{{ $role }}**.

<x-mail::button :url="$url" color="success">
Aceitar convite
</x-mail::button>

O convite vale até {{ $inv->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}. Se você ainda não tem conta, é só criar a sua senha ao abrir o link.

Data Fox · Acervo e raridades
</x-mail::message>
