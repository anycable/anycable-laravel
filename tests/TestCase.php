<?php

namespace AnyCable\Laravel\Tests;

use AnyCable\Laravel\Providers\AnyCableBroadcastServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The previous error handler.
     *
     * @var callable|null
     */
    protected $previousErrorHandler;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Store the current error handler so we can restore it later
        $this->previousErrorHandler = set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    /**
     * Clean up the testing environment before the next test.
     */
    protected function tearDown(): void
    {
        // Restore the previous error handler if it exists
        if ($this->previousErrorHandler) {
            restore_error_handler();
        }

        parent::tearDown();
    }

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
