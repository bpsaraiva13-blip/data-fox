# Data Fox — notas para agentes

Laravel 13 + Filament 5 (Schemas API: `Filament\Schemas\Schema`, `Filament\Actions\*`, `Filament\Support\Icons\Heroicon`).
Banco PostgreSQL (usa a extensão `unaccent` na busca). Fotos no disco de `config('acervo.disk')`.

- Interface e textos em português do Brasil.
- Regras de acesso em `app/Policies` via `User::canEdit()` / `User::isAdmin()`.
- Toda alteração de peça é registrada em `activity_logs` (observer `ItemObserver`, `ActivityLog::record`).
- A assinatura visual (`ImageSignature`) precisa continuar compatível com a versão web anterior (dHash 9×8 + cor média 4×4).
- Estilo próprio em `public/css/datafox.css` (sem build de Tailwind); classes `df-*` nas views.
