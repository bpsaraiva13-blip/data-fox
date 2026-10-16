<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Tenancy\EditAcervo;
use App\Filament\Pages\Tenancy\RegisterAcervo;
use App\Filament\Widgets\AcervoStats;
use App\Filament\Widgets\BackupReminder;
use App\Models\Acervo;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
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
    public function boot(): void
    {
        // O escopo do Filament filtraria só por acervo_id e esconderia as caixas vinculadas;
        // o global scope "acervo" (BelongsToAcervo) já faz esse papel.
        Resource::scopeToTenant(false);
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            // trocar o e-mail do perfil exige confirmar pelo novo endereço
            ->emailChangeVerification()
            ->profile(EditProfile::class, isSimple: true)   // tela simples: abre sem acervo selecionado (usada por todos a partir do Data Fox)
            // verificação em duas etapas opcional (cada pessoa ativa em Meu perfil)
            ->multiFactorAuthentication([
                \Filament\Auth\MultiFactor\App\AppAuthentication::make()->recoverable()->brandName('Data Fox'),
            ])
            // Vários acervos: cada pessoa cria o seu ou participa de acervos compartilhados.
            // O filtro por acervo é feito pelo trait BelongsToAcervo (inclui caixas vinculadas).
            ->tenant(Acervo::class, slugAttribute: 'slug', ownershipRelationship: 'acervo')
            ->tenantRegistration(RegisterAcervo::class)
            ->tenantProfile(EditAcervo::class)
            ->tenantMenuItems([
                Action::make('vitrine')->label('Abrir vitrine')->icon('heroicon-o-eye')
                    ->url(fn () => route('showcase', Filament::getTenant()?->slug ?? 'x'), shouldOpenInNewTab: true)
                    ->visible(fn () => Filament::getTenant() !== null),
            ])
            ->brandName('Data Fox')
            ->homeUrl(fn () => url('/'))
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
            ->databaseNotifications()
            ->databaseNotificationsPolling('20s')
            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->navigationGroups([
                NavigationGroup::make('Acervo'),
                NavigationGroup::make('Administração')->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([Dashboard::class])
            ->widgets([BackupReminder::class, AcervoStats::class])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString(
                '<link rel="stylesheet" href="'.asset('css/datafox.css').'?v='.filemtime(public_path('css/datafox.css')).'">'
            ))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => view('filament.social-buttons'))
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
                \App\Http\Middleware\UseDataFoxLogin::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                \App\Http\Middleware\AdminAreaOnly::class,
            ]);
    }
}
