# hampel/paypal-api-laravel

Laravel integration for `hampel/paypal-api`. A service provider, a manager for named connections,
a facade, a token store over Laravel's cache, and the PSR-18 adapter that makes `Http::fake()` see
the traffic. Modelled on `hampel/saasu-api-laravel`, minus everything Saasu's rationing made
necessary there.

## Commands

```bash
composer check          # lint, analyse, test - what CI runs
composer test           # phpunit
composer analyse        # phpstan, level 10 with larastan, PHP 8.3-8.5 in one pass
composer format         # pint
```

## Layout

| path | what it is |
|---|---|
| `src/PayPalManager.php` | one client per configured connection, memoised; environment and credential checks |
| `src/PayPalServiceProvider.php` | the bindings, the merged config, the publish tag |
| `src/Cache/CacheTokenStore.php` | access tokens in a cache store |
| `src/Http/PendingRequestClient.php` | the PSR-18 adapter over Laravel's HTTP client |
| `src/Facades/PayPal.php` | the facade, and the `@method` block that types it |
| `src/Exception/` | configuration failures, in the core package's hierarchy |
| `config/paypal.php` | the published config |

## Sandbox unless a connection says live

**An unset or empty `environment` is sandbox; `live` is used only when a connection names it; any
other value is refused.** The core package has no default environment, on purpose. This one has
sandbox because an unset variable must never point an application at real payers' names, emails
and money — and refuses a typo rather than guessing, because the typo may have been for live, and
an empty sandbox read as the real account is a silent wrong answer. `ManagerTest` covers each case;
probed by defaulting to live, which fails five tests.

## Tokens: cached, unencrypted, no expiry, no lock

- **No lock around a fetch.** There is no refresh token to rotate, and PayPal answers a second token
  request while one is unexpired with the same token (measured by the core on 2026-09-15), so two
  workers fetching at once both get it.
- **No cache TTL.** The grant carries its own expiry, `ClientCredentials` checks it on every
  request, and an expired one is overwritten.
- **Stored as `AccessGrant::toArray()`**, never `jsonSerialize()`, which withholds the token on
  purpose. Probed: storing `jsonSerialize()` fails nine tests.
- **Unencrypted**, like everything else in a cache. Encrypting would need `APP_KEY`, which a Laravel
  Zero application often lacks. The README says to choose the store accordingly.
- **Keyed by the core's `ClientCredentials::keyFor($clientId, $host)`**, so connections naming one
  app share a token and a sandbox token is never offered to live, though all of them share one
  store. `TokenStoreTest` asserts both.
- **Resolved lazily** through `PayPalServiceProvider::TOKEN_STORE`, on the first client built, and
  only after that connection's settings have been validated. `ConfigurationTest` pins both with an
  unusable binding.

## What is deliberately not here

- **No throttle.** `saasu-api-laravel` has one because Saasu rations requests and blocks a file for
  24 hours past its quota. PayPal documents no comparable limit for these endpoints and its token
  response carries no rate-limit header. Add one only for something measured.
- **No refresh logic and no request budget.** The first is PayPal's design; the second was a Saasu
  quota concern.
- **No write path.** The core is read-only by decision — its `CLAUDE.md` says why — and a wrapper
  that added one would be a scope change to both.
- **No harness.** Nothing here owns behaviour Testbench cannot reach: no artisan command, no queued
  job. The live API is the core package's harness's job, and a Laravel harness would reach it
  through a thicker stack with Laravel inside it.

## The transport lives under `paypal.http_client`, never the shared PSR-18 key

**The provider binds `PendingRequestClient` under `PayPalServiceProvider::HTTP_CLIENT` with
`singletonIf()`, and builds the manager from that key alone.** `singletonIf()` because Laravel Zero
lists `AppServiceProvider` before a package provider, so `singleton()` would silently replace an
application's override there; probed, `HttpClientOverrideTest` fails with `singleton()`.

It does not bind `Psr\Http\Client\ClientInterface`, and does not read it: in an application with
several API wrappers installed, the last provider to claim that key would supply every package's
transport. **Do not add a fallback to `ClientInterface` when it is bound.**
`SharedPsr18BindingTest` covers both registration orders and a faked sibling adapter.

The adapter is a copy of the one in the sibling Laravel API wrappers, and its docblock carries the
reasoning for each load-bearing decision: rebuilt per send, factory resolved per send, one Guzzle
handler reused, `send()` with four options set by hand (Laravel 12 reads `laravel_data` and
`on_stats` without a default, so only the Laravel 12 CI job catches their removal), and transport
options passed on from an allowlist. `TransportTest` pins the options. **The core catches
`ClientExceptionInterface`, not `\Throwable`**, so `StrayRequestException` reaches a consumer's
test untouched; `HttpFakeTest` asserts it.

## Facts worth not rediscovering

- **The core is required as `dev-master` through a `vcs` repository at `../paypal-api`** until it
  is tagged. CI cannot resolve that path, so CI fails until the constraint becomes a tagged range
  and the `repositories` entry is removed — which is the first step of the first release.
- **The named-connection accessor is `client()` because `connection()` is taken** by `Client`.
  `FacadeConformanceTest::the_manager_does_not_shadow_a_client_method` keeps any name from being
  reintroduced. The config key is still `connections`.
- **Every client asks for a token before its first request**, so a test faking only the endpoint
  under test fails on the token. `TestCase::fakePayPal()` registers the token route first, because
  `Http::fake()` answers from the first matching pattern.
- **`TestCase::setUp()` calls `Http::preventStrayRequests()` for every test.** Added on 2026-11-18
  after probing the sandbox default by reverting it sent an unfaked token request, with the suite's
  dummy credentials, to the live host. Keep it; a reverted fix is exactly the request that escapes.
  Tests that swap the factory set it again on the new one.
- **`Transactions::each()` is passed through untouched**, so `getReturn()` still reads its last
  page through the facade. `HttpFakeTest::the_walk_hands_back_its_last_page_through_the_facade`.
- **An empty 200 to the token request surfaces as the core's `InvalidArgumentException`**, from
  `AccessGrant::fromResponse()`, not `MalformedResponseException`: the core refuses an empty body
  on a read but not on a POST. Loud either way; `ExceptionPassthroughTest` pins it, and the type is
  the core's to change.
- **`failOnDeprecation` is inert in a Testbench package without help.**
  `withoutDeprecationHandling()` in `setUp()` covers test-executed paths; `ConfigurationTest`
  registers and boots the provider against an application built in the test body to cover
  `register()` and `boot()`.
- **Laravel Zero does not bind the HTTP client factory, nor run package discovery.** The provider
  binds the factory with `singletonIf`; `LaravelZeroTest` builds that container by hand, including
  two runs over one file cache store, and cannot test the README's registration instructions.
- **`composer-require-checker` carries the undeclared-dependency check.** Every whitelisted symbol
  in `.github/composer-require-checker.json` belongs to a component in `require`, unattributable
  because `laravel/framework` `replace`s them. A *new* `Illuminate` symbol reported there is a
  prompt to check `require`, not to extend the list.

## The facade's annotations are the only types it has

`PayPal::transactions()` goes through the manager's `__call()` and returns `mixed`; the
`@method static` block is what makes the chain analysable. `tests/FacadeConformanceTest.php`
compares that block with `Client`'s public methods in both directions and checks every annotated
type resolves.

## Version support

PHP `>=8.3`, Laravel `^12.0|^13.0`. PHPStan runs at level 10 with `phpVersion` spanning 8.3 to 8.5;
keep `phpstan.neon.dist` in step with the `php` constraint. CI tests three corners — PHP 8.3 with
Laravel 12 and `--prefer-lowest`, PHP 8.3 with Laravel 13, PHP 8.5 with Laravel 13 — and runs
`composer-require-checker` and a dev-free PHPStan pass as separate checks.

## Git

Commit freely; never push.
