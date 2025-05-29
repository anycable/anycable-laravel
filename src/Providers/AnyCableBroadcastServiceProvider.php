<?php

namespace AnyCable\Laravel\Providers;

use AnyCable\Laravel\Broadcasting\AnyCableBroadcaster;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class AnyCableBroadcastServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/anycable.php', 'broadcasting.connections.anycable'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/anycable.php' => config_path('broadcasting.connections.anycable'),
        ], 'anycable-config');

        Broadcast::extend('anycable', function ($app, $config) {
            return new AnyCableBroadcaster(
                new Client,
                $config
            );
        });
    }
}
