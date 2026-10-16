<?php

namespace App\Models;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Throwable;

class Acervo extends Model
{
    protected $guarded = ['id'];

    public const ROLES = [
        'admin' => 'Administrador',
        'editor' => 'Editor',
        'viewer' => 'Somente leitura',
        'showcase' => 'Só vitrine',
    ];

    protected function casts(): array
    {
        return ['showcase_public' => 'boolean', 'last_backup_at' => 'datetime', 'page' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (Acervo $a) {
            $a->slug ??= static::uniqueSlug($a->name);
            $a->owner_id ??= auth()->id();
        });
        static::created(function (Acervo $a) {
            if ($a->owner_id) {
                $a->members()->syncWithoutDetaching([$a->owner_id => ['role' => 'admin', 'status' => 'active']]);
            }
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'acervo';
        // nomes usados por rotas fora do painel
        if (in_array($base, ['vitrine', 'convite', 'pdf', 'pecas', 'anexo', 'login', 'logout', 'password-reset', 'up', 'storage', 'livewire', 'filament', 'novo'], true)) {
            $base .= '-acervo';
        }
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** Acervo aberto no painel (ou null em comandos/filas). */
    public static function current(): ?self
    {
        try {
            $t = Filament::getTenant();

            return $t instanceof self ? $t : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(Membership::class)
            ->withPivot(['id', 'role', 'status', 'boxes', 'invited_by'])->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function boxes(): HasMany
    {
        return $this->hasMany(Box::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(ItemCollection::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(AccessLog::class);
    }

    public function membershipFor(?User $user): ?Membership
    {
        if (! $user) {
            return null;
        }

        return $this->memberships()->where('user_id', $user->id)->first();
    }
}
