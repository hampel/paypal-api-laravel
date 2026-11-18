# CHANGELOG

## Unreleased

- Requires PHP 8.3 or later and Laravel 12.71 or 13.40 or later
- Requires `hampel/paypal-api` `^0.1`
- `PayPalServiceProvider`, `PayPalManager` and the `PayPal` facade for `hampel/paypal-api`
- Named connections, each a REST app's `client_id`, `secret` and `environment`;
  `PayPal::client('name')` reaches one
- The environment is sandbox unless a connection says `live`; any other value raises
  `InvalidConfiguration`, as does a connection missing its client id, its secret, or both
- Access tokens are kept in a cache store by `CacheTokenStore`, bound as `paypal.token_store`
- Requests are sent through Laravel's HTTP client, so `Http::fake()`, `Http::assertSent()` and
  `Http::preventStrayRequests()` apply to them; the transport is bound as `paypal.http_client`
- `paypal.page_size`, `paypal.timeout` and `paypal.connect_timeout` settings, and a `base_uri` per
  connection
