<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\NestJSIntegrationService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register NestJS Integration Service
        $this->app->singleton(NestJSIntegrationService::class, function ($app) {
            return new NestJSIntegrationService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
