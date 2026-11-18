<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Laravel\Cache;

use Hampel\PayPal\Api\Authentication\AccessGrant;
use Hampel\PayPal\Api\Authentication\TokenStore;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Illuminate\Contracts\Cache\Repository;

/**
 * Keeps PayPal access tokens in a Laravel cache store, so every process shares one per app.
 *
 * A PHP application is many short processes. With the core package's in-memory store, each web
 * request that touches PayPal and each worker restart would ask for a token again - a doubled
 * request every time, for a token that lasts about nine hours.
 *
 * NO LOCK AROUND A FETCH. There is no refresh token to rotate: a token comes from the client
 * credentials grant alone, and measured on 2026-09-15, a second request while one is unexpired
 * answers the SAME token. Two workers that find the store empty both ask, and both get the token
 * the other got.
 *
 * STORED WITHOUT AN EXPIRY. The grant carries its own, and ClientCredentials checks it on every
 * request and replaces an expired one. A cache TTL would be a second clock to keep in step with
 * the first, and buys nothing an overwrite does not.
 *
 * Stored as AccessGrant::toArray() - the token, in plain form. jsonSerialize() withholds the
 * token on purpose, so it is not a storable form. A cache store is as sensitive as whatever it
 * holds.
 */
final class CacheTokenStore implements TokenStore
{
    public function __construct(
        private readonly Repository $cache,
    ) {
    }

    public function get(string $key): ?AccessGrant
    {
        $stored = $this->cache->get($key);

        if (! is_array($stored)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $stored */
            return AccessGrant::fromArray($stored);
        } catch (InvalidArgumentException) {
            // Unreadable - written by something else under the same key, or damaged. Treated as
            // absent, so the next request obtains a token rather than failing on every request.
            $this->cache->forget($key);

            return null;
        }
    }

    public function put(string $key, AccessGrant $grant): void
    {
        $this->cache->forever($key, $grant->toArray());
    }

    public function forget(string $key): void
    {
        $this->cache->forget($key);
    }
}
