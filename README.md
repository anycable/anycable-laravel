[![Build](https://github.com/anycable/anycable-laravel/workflows/Test/badge.svg)](https://github.com/anycable/anycable-laravel/actions)

# AnyCable Laravel Broadcaster

A Laravel broadcaster implementation to use [AnyCable](https://anycable.io/) as a WebSocket server with Laravel Echo clients. For client-side integration, see [@anycable/echo][] package.

> [!TIP]
> The quickest way to get started with AnyCable server is to use our free managed offering: [plus.anycable.io](https://plus.anycable.io)

## Requirements

- PHP 8.2+
- Laravel 11+

## Installation

You can install the package via composer:

```bash
composer require anycable/laravel-broadcaster
```

## Configuration

First, add the AnyCable provider to the `bootstrap/providers.php` file:

```diff
 <?php

 return [
     App\Providers\AppServiceProvider::class,
     // ...
+    AnyCable\Laravel\Providers\AnyCableBroadcastServiceProvider::class,
 ];
```

Then, add the following to your `config/broadcasting.php` file:

```php
'anycable' => [
    'driver' => 'anycable',
],
```

That's a minimal configuration, all AnyCable related parameters would be inferred from the default env. This is our default config:

```php
'anycable' => [
    'secret' => env('ANYCABLE_SECRET', null),
    'http_broadcast_url' => env('ANYCABLE_HTTP_BROADCAST_URL', null),
    'timeout' => env('ANYCABLE_BROADCAST_TIMEOUT', 5) // timeout for broadcast HTTP requests
]
```

On the client-side, configure Echo to use AnyCable adapter:

```js
import Echo from "laravel-echo";
import { EchoCable } from "@anycable/echo";

window.Echo = new Echo({
    broadcaster: EchoCable,
    cableOptions: {
        url: url: import.meta.env.VITE_WEBSOCKET_URL || 'ws://localhost:8080/cable',
    },
    // other configuration options such as auth, etc
});
```

## Usage

You can use Laravel's broadcasting features as you normally would:

```php
MyEvent::dispatch($data);
```

See [Broadcasting documentation](https://laravel.com/docs/12.x/broadcasting).

### AnyCable server management

This package includes a command to manage the AnyCable server binary. The command will automatically download the binary if it's not found in the specified path:

```bash
# Run AnyCable server, downloading the binary if necessary
php artisan anycable:server

# Download the binary without running the server
php artisan anycable:server --download-only

# Specify a custom path for the binary
php artisan anycable:server --binary-path=/path/to/anycable-go

# Specify a custom version to download
php artisan anycable:server --version=1.6.3

# Specify download directory
php artisan anycable:server --download-dir=/path/to/directory
```

The command will detect your platform and architecture automatically and download the appropriate binary from the AnyCable GitHub releases. The default download path is `storage/dist`, so make sure it's Git-ignored.

You can also pass AnyCable flags as follows:

```sh
php artisan anycable:server -- --public_streams --pusher_app_key=my-app-key
```

**IMPORTANT:** In containerized enviroments, we recommend using our official [Docker images](https://hub.docker.com/r/anycable/anycable-go/).

#### Configuration

You can use CLI options, env variables, or in the `config/broadcasting.php`. The latter is recommended to avoid potential issues with cached env (due to `php artisan config:cache`).

Add a `server` key to your AnyCable broadcasting connection in `config/broadcasting.php`:

```php
'anycable' => [
    'driver' => 'anycable',
    // ...
    'server' => [
        'broadcast_adapter' => env('ANYCABLE_BROADCAST_ADAPTER', 'http'),
        'presets' => env('ANYCABLE_PRESETS', 'broker'),
        'public' => env('ANYCABLE_PUBLIC', false),
        // Add any AnyCable server option here
    ],
],
```

Each key in the `server` array is automatically converted to an `ANYCABLE_*` environment variable and passed to the anycable-go binary (e.g., `'broadcast_adapter'` becomes `ANYCABLE_BROADCAST_ADAPTER`). See [AnyCable configuration docs](https://docs.anycable.io/anycable-go/configuration) for available options.

The server command also recognizes Reverb env variables (such as `REVERB_APP_ID`, `REVERB_APP_KEY`, etc.) providing Pusher credentials. You can also provide AnyCable specific env vars to configure the Pusher compatibility feature for AnyCable: [docs](https://docs.anycable.io/anycable-go/pusher).

### Private Channels

AnyCable supports private and presence channels. To use them, you need to set the `ANYCABLE_SECRET` environment variable.

Then, don't forget to add authorization callbacks like this:

```php
Broadcast::channel('private-channel', function ($user) {
    return true;
});
```

## Contributing

Contributions are welcomed! All you need to start developing the project locally is PHP 8.2+, Composer and:

```sh
# install deps
composer install

# run tests
composer test

# run linters
composer lint
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

[@anycable/echo]: https://github.com/anycable/anycable-client/tree/master/packages/echo
