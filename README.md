# PayPal API for Laravel

[![Tests](https://github.com/hampel/paypal-api-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/hampel/paypal-api-laravel/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hampel/paypal-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/paypal-api-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/hampel/paypal-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/paypal-api-laravel)
[![Open Issues](https://img.shields.io/github/issues-raw/hampel/paypal-api-laravel.svg?style=flat-square)](https://github.com/hampel/paypal-api-laravel/issues)
[![License](https://img.shields.io/packagist/l/hampel/paypal-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/paypal-api-laravel)

By [Simon Hampel](mailto:simon@hampelgroup.com)

Laravel integration for [`hampel/paypal-api`][core] — a service provider, a manager for named
connections, and a facade.

- **Named connections.** A REST app's client id, secret and environment per connection — one per
  PayPal account you read — a default, and `PayPal::client('name')` to reach one.
- **Sandbox unless a connection says live.** An unset environment is sandbox; a value that is
  neither `sandbox` nor `live` is refused.
- **Tokens are cached.** Every PHP process shares one token per app, rather than asking PayPal again
  on every web request and every worker restart.
- **`Http::fake()` sees the API client's traffic.** Every request goes through Laravel's own handler
  stack, so `Http::fake()`, `Http::assertSent()` and `Http::preventStrayRequests()` all work.

The request building, status mapping and exception hierarchy are the core package's, untouched. So
is its scope: **it reads.** Nothing here captures, refunds or cancels.

## Requirements

PHP 8.3 or later, and Laravel 12 or 13.

Laravel Zero works too, with the HTTP component installed (`php <app> app:install http`). Laravel
binds `Illuminate\Http\Client\Factory` as a singleton in `FoundationServiceProvider`, which a
Laravel Zero application does not register; unbound, `Http::fake()` silently fails to intercept
and the request reaches the real API. This package binds one when nothing else has, so the
behaviour is the same on both.

## Installation

```bash
composer require hampel/paypal-api-laravel
```

In a Laravel application the provider and the `PayPal` alias are discovered automatically. Publish
the config file if you want to edit it:

```bash
php artisan vendor:publish --tag=paypal-config
```

**Laravel Zero does not run package discovery**, so there the provider has to be listed by hand, in
`config/app.php`:

```php
'providers' => [
    Hampel\PayPal\Api\Laravel\PayPalServiceProvider::class,
],
```

The global `PayPal` alias is not registered either. Import the facade class —
`use Hampel\PayPal\Api\Laravel\Facades\PayPal;` — or inject `PayPalManager`.

**Laravel Zero has no persistent cache store until you define one.** Without a `config/cache.php`
its only store is `array`, which forgets everything when the process ends, so every run asks PayPal
for a token. Define a file store at an absolute path and name it in `cache_store`:

```php
// config/cache.php
return [
    'default' => 'array',
    'stores' => [
        'array' => ['driver' => 'array'],
        'paypal' => ['driver' => 'file', 'path' => '/var/lib/your-tool/cache'],
    ],
];
```

```dotenv
PAYPAL_CACHE_STORE=paypal
```

- **Not `storage_path()` in a compiled phar.** It resolves inside the archive, which is read-only.
- **Protect it with the directory above it, created `0700`, not with the store's `permission`
  option.** Laravel applies that mode to the cache's subdirectories as well as its files, and a
  directory at `0600` cannot be entered.

## Configuration

```dotenv
PAYPAL_CLIENT_ID=your-client-id
PAYPAL_SECRET=your-secret
PAYPAL_ENVIRONMENT=sandbox
```

The shipped `config/paypal.php` defines one connection called `main`. Add more by naming them — one
per PayPal account:

```php
'default' => 'main',

'connections' => [
    'main' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret' => env('PAYPAL_SECRET'),
        'environment' => env('PAYPAL_ENVIRONMENT', 'sandbox'),
    ],

    'second-account' => [
        'client_id' => env('SECOND_PAYPAL_CLIENT_ID'),
        'secret' => env('SECOND_PAYPAL_SECRET'),
        'environment' => env('SECOND_PAYPAL_ENVIRONMENT', 'sandbox'),
    ],
],
```

**The app decides which account is read.** A REST app belongs to one PayPal account, so there is no
account id to configure; a second account is a second app. Transaction search needs
**Transaction Search** ticked in the app's features.

**An application config file named `paypal.php` replaces these settings key by key.** Laravel merges
a package's configuration shallowly, and the application's file wins for every top-level key it
defines. Publish this file and edit it, or name your own settings file something else.

### Environment

**Sandbox unless the connection says live.**

| `environment` is | the connection uses |
|---|---|
| `live` | live, `api-m.paypal.com` |
| `sandbox` | sandbox, `api-m.sandbox.paypal.com` |
| unset or empty | sandbox |
| anything else | nothing — refused |

Sandbox and live are separate hosts with separate apps and credentials, so a sandbox credential
pointed at live fails loudly. The mistake that does not is a live credential in a test, which is
why live has to be asked for, and why a typo is refused rather than read as either. Names are read
in any case.

### Credentials

A connection needs both `client_id` and `secret`. **Neither, or only one, raises
`Hampel\PayPal\Api\Laravel\Exception\InvalidConfiguration` when the client is built**, rather than
reaching PayPal as a 401 that reads like a revoked app. An empty string counts as unset, and both
values are trimmed.

### Tokens

**A token is cached in the configured store, without a cache expiry**, under a key derived from the
client id and the host — so connections naming the same app share one token, and a sandbox token is
never offered to live. A token lasts about nine hours and carries its own expiry; an expired one is
replaced by asking PayPal again. There is no refresh token, and no lock: two workers asking at once
both get the same token.

```php
'cache_store' => env('PAYPAL_CACHE_STORE'),   // null uses the default store
```

**A cached token is a credential, stored unencrypted**, as the cache stores anything else, and it
reads the account's whole transaction history while it lasts. Point `cache_store` at a store no more
widely readable than your `.env` file.

To keep tokens somewhere else, bind your own `Hampel\PayPal\Api\Authentication\TokenStore`:

```php
use Hampel\PayPal\Api\Laravel\PayPalServiceProvider;

$this->app->singleton(PayPalServiceProvider::TOKEN_STORE, fn () => new MyTokenStore());
```

### Page size and base URI

```php
'page_size' => env('PAYPAL_PAGE_SIZE'),   // 1 to 500; null uses 500
```

**The page size defaults to PayPal's maximum** rather than its own default of 100, because a
reconciliation reads every row and every page is a request. It is shared by every connection.

A connection may also set `base_uri`, for a recorded fixture served locally or an outbound proxy
that terminates the connection. Both are validated when the client is built.

### Transport

```php
'timeout' => 30,
'connect_timeout' => 5,
```

Applied to every request, alongside any `Http::globalRequestMiddleware()` the application has
configured and the transport settings from `Http::globalOptions()` — a proxy, a CA bundle or client
certificate, the protocol version, curl options. **Global options that would change the request
itself are not applied**: `headers`, `auth`, `query` and the body options would overwrite what the
core package built, including its credential.

## Usage

The facade reaches the default connection directly:

```php
use Hampel\PayPal\Api\Laravel\Facades\PayPal;

$grant = PayPal::verify();                  // one token request, or none if one is cached
$grant->canSearchTransactions();

$balances = PayPal::transactions()->balances();

$rows = PayPal::transactions()->each(new DateTimeImmutable('-90 days'), new DateTimeImmutable());

foreach ($rows as $row) {
    echo $row->id, ' ', $row->amount, ' ', $row->customField, "\n";
}

$rows->getReturn()->endDate;                // how far PayPal's data reached
```

Name a connection to reach another:

```php
$capture = PayPal::client('second-account')->captures()->find($captureId);
```

**It is `client()` and not `connection()`.** `PayPal::connection()` is the core package's transport,
and the manager forwards unknown calls to the default client, so a method on both would mean
different things depending on which class you thought you were calling.

Inject the manager where a facade is not wanted:

```php
use Hampel\PayPal\Api\Laravel\PayPalManager;

public function __construct(private readonly PayPalManager $paypal) {}

$this->paypal->client('second-account')->transactions()->balances();
```

Everything past that point is the core package — see [its documentation][core] for transaction
search and its limits, captures, refunds, subscriptions, plans and webhook verification.

## Errors

The core package's exceptions arrive untouched:

```php
use Hampel\PayPal\Api\Exception\NotAuthenticatedException;
use Hampel\PayPal\Api\Exception\NotPermittedException;

try {
    $page = PayPal::transactions()->search($from, $to);
} catch (NotAuthenticatedException $e) {
    // the client id or secret is no good. A configuration error, not an empty result.
} catch (NotPermittedException $e) {
    // a 403: PayPal will not let this app do this
}
```

`UnknownConnection` and `InvalidConfiguration` extend the core package's `PayPalException`, so an
application already catching that catches these too.

## Testing

Fake the API with the vocabulary the rest of your suite already uses:

```php
use Illuminate\Support\Facades\Http;

Http::preventStrayRequests();

Http::fake([
    'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
        'access_token' => 'test-token',
        'expires_in' => 32400,
    ]),
    'api-m.sandbox.paypal.com/v1/reporting/balances*' => Http::response([
        'balances' => [],
    ]),
]);

$balances = PayPal::transactions()->balances();

Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
```

The package's real code path runs; only the socket is replaced. So a faked 404 still arrives as
`NotFoundException`, and a faked 200 whose body is HTML still arrives as
`MalformedResponseException`.

- **A client asks for a token first.** Fake `/v1/oauth2/token`, and list it before any catch-all
  such as `api-m.sandbox.paypal.com/*`: `Http::fake()` answers from the first pattern that matches.
  Once a token is cached, later requests in the same test do not ask again.
- **Give every fake a body.** `Http::fake()` with no arguments answers every request with an empty
  200, which the core package refuses.
- **Order does not matter.** Faking after the client has been resolved works, and so does
  `Http::swap(new Factory)` to start a test from a clean set of fakes: the transport looks the
  factory up at the moment of sending.

Replace the transport entirely by binding `paypal.http_client` — how an application with its own
outbound HTTP policy makes this package use it:

```php
use Hampel\PayPal\Api\Laravel\PayPalServiceProvider;

$this->app->singleton(PayPalServiceProvider::HTTP_CLIENT, fn () => $myPsr18Client);
```

The package binds that key and `paypal.token_store` only if nothing has already, so an override
works from any service provider, whether it registers before or after this package's — including
`AppServiceProvider` in a Laravel Zero `config/app.php`, where it is listed first.

**This package does not use a `Psr\Http\Client\ClientInterface` binding**, yours or another
package's, and does not bind that key itself. It is one key shared by every package that uses it,
so an application with several API integrations installed would otherwise get whichever registered
last.

### What Laravel's HTTP events see

**`RequestSending` fires; `ResponseReceived` and `ConnectionFailed` do not.** Laravel raises the
first from inside the handler stack this package sends through, and the other two from a layer
above it. So Telescope's HTTP client watcher, which listens for `ResponseReceived`, will not show
this traffic. The core package logs every request at `debug` through PSR-3.

## Versioning

This package requires `hampel/paypal-api` `^0.1`. That package is 0.x, so its public API can
change in a minor release; this package constrains it narrowly and expects to bump.

## License

MIT. See [LICENSE.md](LICENSE.md).

[core]: https://github.com/hampel/paypal-api
