<?php

require __DIR__.'/../vendor/autoload.php';

// We'll register the error handler in TestCase.php instead
// This allows PHPUnit to properly detect when tests modify error handlers

// Mock Laravel's Log facade if needed
if (! class_exists('Illuminate\Support\Facades\Log')) {
    class_alias('AnyCable\Laravel\Tests\Mocks\LogMock', 'Illuminate\Support\Facades\Log');
}

// Set default environment variables for testing if not already set
if (! getenv('ANYCABLE_SECRET')) {
    putenv('ANYCABLE_SECRET=testing_secret');
}

if (! getenv('ANYCABLE_BROADCAST_URL')) {
    putenv('ANYCABLE_BROADCAST_URL=http://localhost:8090/_broadcast');
}
