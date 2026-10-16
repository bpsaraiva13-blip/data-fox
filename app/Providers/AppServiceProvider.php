<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));
        Model::preventLazyLoading(! app()->isProduction());

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }

        // Entrar com Apple (provedor do SocialiteProviders); o Google já vem no Socialite
        if (class_exists(\SocialiteProviders\Manager\SocialiteWasCalled::class)) {
            Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
                $event->extendSocialite('apple', \SocialiteProviders\Apple\Provider::class);
            });
        }
        // chave .p8 da Apple colada na variável de ambiente: grava num arquivo para o provedor ler
        $key = (string) config('services.apple.private_key');
        if (str_contains($key, 'BEGIN PRIVATE KEY')) {
            $file = storage_path('app/private/apple-signin.p8');
            if (! is_file($file) || md5_file($file) !== md5(str_replace('\\n', "\n", $key))) {
                @mkdir(dirname($file), 0700, true);
                file_put_contents($file, str_replace('\\n', "\n", $key));
                @chmod($file, 0600);
            }
            config(['services.apple.private_key' => $file]);
        }
        // endereços de retorno relativos viram absolutos (APP_URL)
        foreach (['google', 'apple'] as $p) {
            $r = config("services.{$p}.redirect");
            if (is_string($r) && str_starts_with($r, '/')) {
                config(["services.{$p}.redirect" => rtrim((string) config('app.url'), '/').$r]);
            }
        }

        // último acesso de quem entra com senha (Google/Apple registram no próprio retorno)
        Event::listen(function (Login $event) {
            $u = $event->user;
            if ($u instanceof \App\Models\User && (! $u->last_login_at || $u->last_login_at->lt(now()->subMinute()))) {
                $u->forceFill(['last_login_at' => now(), 'last_login_via' => 'password'])->saveQuietly();
            }
        });
    }
}
