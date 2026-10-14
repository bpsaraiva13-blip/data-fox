<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $guarded = ['id'];

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class);
    }

    public static function normalize(?string $name): string
    {
        return Str::of((string) $name)->ascii()->lower()->trim()->ltrim('#')
            ->replaceMatches('/\s+/', '-')->replaceMatches('/[^a-z0-9-]/', '')->limit(40, '')->toString();
    }

    /** Renomeia; se o destino já existir, mescla as duas tags. Retorna nº de peças afetadas. */
    public function renameOrMerge(string $newName): int
    {
        $newName = static::normalize($newName);
        if ($newName === '' || $newName === $this->name) {
            return 0;
        }

        return DB::transaction(function () use ($newName) {
            $count = $this->items()->count();
            $target = static::where('name', $newName)->first();

            if (! $target) {
                $old = $this->name;
                $this->update(['name' => $newName]);
                ActivityLog::record('tag', null, "Renomeou #{$old} → #{$newName} em {$count} peça(s)");

                return $count;
            }

            $ids = $this->items()->pluck('items.id');
            $target->items()->syncWithoutDetaching($ids);
            ActivityLog::record('tag', null, "Mesclou #{$this->name} → #{$newName} em {$count} peça(s)");
            $this->delete();

            return $count;
        });
    }
}
