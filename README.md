[![Build](https://github.com/anycable/anycable-laravel/workflows/Test/badge.svg)](https://github.com/anycable/anycable-laravel/actions)

# AnyCable Laravel Broadcaster

A Laravel broadcaster implementation to use [AnyCable](https://anycable.io/) as a WebSocket server.

The broadcaster allows you to use AnyCable as a drop-in replacement for Reverb, or Pusher, or whatever is supported by Laravel Echo. By "drop-in", we mean that no client-side changes required to use AnyCable, all you need is to update the server configuration (and, well, launch an AnyCable server).

> [!TIP]
> The quickest way to get started with AnyCable server is to use our free managed offering: [plus.anycable.io](https://plus.anycable.io)

> [!NOTE]
> AnyCable Laravel support is still in its early days. Please, let us know if anything goes wrong. See also the [limitations](#limitations) section below.

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

Your client-side Echo configuration can stay almost unchanged (in case you used Reverb):

```js
import Echo from "laravel-echo";

// We use Pusher protocol for now
import Pusher from "pusher-js";
window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: "reverb", // reverb or pusher would work
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? "https") === "https",
    enabledTransports: ["ws", "wss"],
});
```

Just make sure you point to to the AnyCable server (locally it runs on the same host and port as Reverb). You must also **configure AnyCable to use the same app key** as `VITE_REVERB_APP_KEY`:

```sh
anycable-go --pusher-app-key=my-app-key

# or
ANYCABLE_PUSHER_APP_KEY=my-app-key anycable-go
```

To use public channels, make sure you have enabled them in AnyCable:


```sh
anycable-go --public_streams

# or full public mode
anycable-go --public

# or
ANYCABLE_PUBLIC_STREAMS=true anycable-go

# or
ANYCABLE_PUBLIC=true anycable-go
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

### Private Channels

AnyCable supports private channels. To use them, you need to set the `ANYCABLE_SECRET` environment variable.

Then, don't forget to add authorization callbacks like this:

```php
Broadcast::channel('private-channel', function ($user) {
    return true;
});
```

## Limitations

- Presence channels are not supported yet.

- Only HTTP broadcasting adapter for AnyCable is supported for now.

- Pusher's signing functionality is not supported by AnyCable yet (is it used by Laravel at all?).

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
