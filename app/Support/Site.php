<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Marca do sistema: nome, subtítulo, frase da tela de entrar e logo (também é o ícone do site). */
class Site
{
    public const DEFAULTS = [
        'name' => 'Data Fox',
        'sub' => 'Acervo e raridades',
        'tagline' => 'O acervo digital de antiguidades e coleções da Equipe Azzurra.',
        'logo' => null,       // caminho no disco das fotos
        'logo_mime' => null,
        'v' => 0,             // muda a cada troca de logo (evita cache antigo)
    ];

    public static function brand(): array
    {
        $saved = Cache::remember('site.brand', 300, function () {
            try {
                if (! Schema::hasTable('site_settings')) {
                    return [];
                }
                $v = DB::table('site_settings')->where('key', 'brand')->value('value');

                return $v ? (json_decode($v, true) ?: []) : [];
            } catch (\Throwable) {
                return [];
            }
        });

        return array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
    }

    public static function save(array $brand): array
    {
        $b = array_merge(self::brand(), array_intersect_key($brand, self::DEFAULTS));
        DB::table('site_settings')->updateOrInsert(['key' => 'brand'], ['value' => json_encode($b), 'updated_at' => now(), 'created_at' => now()]);
        Cache::forget('site.brand');

        return $b;
    }

    /** O que a interface e a tela de entrar precisam. */
    public static function public(): array
    {
        $b = self::brand();

        return ['name' => $b['name'], 'sub' => $b['sub'], 'tagline' => $b['tagline'], 'logo' => $b['logo'] ? url('/brand/icon?v='.$b['v']) : null];
    }

    public static function iconUrl(): string
    {
        $b = self::brand();

        return $b['logo'] ? url('/brand/icon?v='.$b['v']) : asset('favicon.svg');
    }
}
