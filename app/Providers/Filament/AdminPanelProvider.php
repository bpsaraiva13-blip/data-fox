<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\AcervoStats;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login()
            ->passwordReset()
            ->brandName('Data Fox')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('favicon.svg'))
            ->font('Plus Jakarta Sans')
            ->colors([
                'primary' => Color::hex('#1F4D36'),
                'success' => Color::hex('#2F7A4C'),
                'warning' => Color::hex('#A8710E'),
                'danger' => Color::hex('#B6423A'),
                'gray' => Color::Stone,
            ])
            ->spa()
            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->navigationGroups([
                NavigationGroup::make('Acervo'),
                NavigationGroup::make('Caixas')->collapsible(),
                NavigationGroup::make('Administração')->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([Dashboard::class])
            ->widgets([AcervoStats::class])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString(
                '<link rel="stylesheet" href="'.asset('css/datafox.css').'?v='.filemtime(public_path('css/datafox.css')).'">'
            ))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => new HtmlString(
                '<script src="'.asset('js/datafox.js').'?v='.filemtime(public_path('js/datafox.js')).'" defer></script>'
            ))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                class_exists(PreventRequestForgery::class) ? PreventRequestForgery::class : VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
