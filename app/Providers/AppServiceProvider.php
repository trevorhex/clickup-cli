<?php

namespace App\Providers;

use App\ClickUp\Client;
use App\ClickUp\TokenStore;
use App\Support\UserConfig;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TokenStore::class);
        $this->app->singleton(Client::class);
        $this->app->singleton(UserConfig::class);
    }
}
