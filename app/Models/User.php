<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'email', 'password', 'role', 'active', 'avatar_path', 'phone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAvatar, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Perfil do sistema: "admin" gerencia todos os usuários; os demais acessam só os próprios acervos. */
    public const ROLES = [
        'admin' => 'Administrador do sistema',
        'editor' => 'Usuário',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->active;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }
        $disk = Storage::disk(config('acervo.disk'));
        try {
            return $disk->temporaryUrl($this->avatar_path, now()->addHour());
        } catch (\Throwable) {
            return $disk->url($this->avatar_path);
        }
    }

    public function acervos(): BelongsToMany
    {
        return $this->belongsToMany(Acervo::class)->using(Membership::class)
            ->withPivot(['id', 'role', 'status', 'boxes', 'invited_by'])->withTimestamps();
    }

    public function getTenants(Panel $panel): array|Collection
    {
        return $this->acervos()->wherePivot('status', 'active')->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->acervos()->whereKey($tenant->getKey())->wherePivot('status', 'active')->exists();
    }

    /** Administrador do sistema (gerencia todos os usuários). */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** @var array<int, Membership|null> */
    protected array $membershipCache = [];

    public function membership(?Acervo $acervo = null): ?Membership
    {
        $acervo ??= Acervo::current();
        if (! $acervo) {
            return null;
        }

        if (! array_key_exists($acervo->id, $this->membershipCache)) {
            $this->membershipCache[$acervo->id] = Membership::where('acervo_id', $acervo->id)->where('user_id', $this->id)->first();
        }

        return $this->membershipCache[$acervo->id];
    }

    public function roleIn(?Acervo $acervo = null): ?string
    {
        $m = $this->membership($acervo);

        return $m && $m->isActive() ? $m->role : null;
    }

    /** Pode cadastrar, editar, mover, unir e excluir no acervo aberto. */
    public function canEdit(?Acervo $acervo = null): bool
    {
        return (bool) $this->membership($acervo)?->canEdit();
    }

    /** Administrador do acervo aberto (gerencia a equipe e as configurações dele). */
    public function isAcervoAdmin(?Acervo $acervo = null): bool
    {
        return $this->roleIn($acervo) === 'admin';
    }

    /** Pode ver o acervo completo (não é "Só vitrine"). */
    public function canBrowse(?Acervo $acervo = null): bool
    {
        return in_array($this->roleIn($acervo), ['admin', 'editor', 'viewer'], true);
    }
}
