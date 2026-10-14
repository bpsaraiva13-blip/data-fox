<?php

namespace App\Filament\Widgets;

use App\Models\Box;
use App\Models\Item;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AcervoStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected static ?int $sort = 0;

    public static function canView(): bool
    {
        return auth()->user()?->canBrowse() ?? false;
    }

    protected function getStats(): array
    {
        $q = fn () => \App\Support\ItemAccess::restrict(Item::query());
        $week = $q()->where('created_at', '>=', now()->subDays(7))->count();
        $trend = collect(range(6, 0))->map(fn ($d) => $q()->whereDate('created_at', now()->subDays($d))->count())->all();

        return [
            Stat::make('Peças no acervo', number_format($q()->count(), 0, ',', '.'))->icon('heroicon-o-archive-box'),
            Stat::make('Caixas em uso', Box::has('items')->count())->icon('heroicon-o-cube'),
            Stat::make('Cadastradas nos últimos 7 dias', $week)->chart($trend)->color('success')->icon('heroicon-o-sparkles'),
        ];
    }
}
