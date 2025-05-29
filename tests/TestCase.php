<?php

namespace AnyCable\Laravel\Tests;

use AnyCable\Laravel\Providers\AnyCableBroadcastServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string<\Illuminate\Support\ServiceProvider>>
     */
    protected function getPackageProviders($app)
    {
        return [
            AnyCableBroadcastServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Set broadcasting configuration
        $app['config']->set('broadcasting.default', 'anycable');
        $app['config']->set('broadcasting.connections.anycable', [
            'driver' => 'anycable',
            'secret' => env('ANYCABLE_SECRET', 'testing_secret'),
            'broadcast_url' => env('ANYCABLE_BROADCAST_URL', 'http://localhost:8090/_broadcast'),
        ]);
    }
}
