<?php

namespace AhmadChebbo\LaravelMontypay;

use AhmadChebbo\LaravelMontypay\Services\MontyPayService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MontyPayServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/montypay.php', 'montypay');

        $this->app->singleton('montypay', function ($app) {
            return new MontyPayService();
        });
    }

    public function boot()
    {
        $this->registerRoutes();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'montypay');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallCommand::class,
                Console\ReconcileCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/montypay.php' => config_path('montypay.php'),
            ], 'montypay-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/montypay'),
            ], 'montypay-views');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'montypay-migrations');
        }
    }

    protected function registerRoutes(): void
    {
        $prefix = config('montypay.routes.prefix', 'montypay');

        Route::group([
            'prefix' => $prefix,
            'middleware' => config('montypay.routes.middleware', ['web']),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });

        Route::group([
            'prefix' => $prefix,
            'middleware' => config('montypay.routes.callback_middleware', []),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/callback.php');
        });
    }

    public function provides()
    {
        return ['montypay'];
    }
}
