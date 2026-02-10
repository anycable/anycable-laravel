# Change log

## master

- Refactor server command: load server configuration from `config/broadcasting.php` first.

## 0.2.0

- Refactor broadcaster to work with AnyCable Echo adapter (Reverb-compatible Echo adapters don't require a custom broadcaster anymore).

## 0.1.2

- Use Reverb env vars as defaults in `anycable:server`.

## 0.1.1

- Fix using `http_broadcas_url` configuration parameter.

## 0.1.0

- Initial release.
