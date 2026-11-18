<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Laravel\Tests;

use DateTimeImmutable;
use Hampel\PayPal\Api\Authentication\AccessGrant;
use Hampel\PayPal\Api\Authentication\ClientCredentials;
use Hampel\PayPal\Api\Laravel\Cache\CacheTokenStore;
use Hampel\PayPal\Api\Laravel\Facades\PayPal;
use Hampel\PayPal\Api\Laravel\PayPalManager;
use Hampel\PayPal\Api\Laravel\PayPalServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * One token per app and host, not one per process.
 *
 * A PHP application is many short processes, and the core package's in-memory store would ask
 * PayPal for a token in each. These tests are the claim that this package does not.
 */
final class TokenStoreTest extends TestCase
{
    #[Test]
    public function a_grant_round_trips_with_its_token(): void
    {
        $store = new CacheTokenStore(new Repository(new ArrayStore()));
        $grant = new AccessGrant('access', new DateTimeImmutable('2026-11-18T09:00:00+00:00'), 'Bearer', 'openid', 'APP-1');

        $store->put('key', $grant);
        $read = $store->get('key');

        $this->assertNotNull($read);
        $this->assertSame($grant->toArray(), $read->toArray());
    }

    #[Test]
    public function a_grant_is_stored_as_its_portable_form_not_its_json(): void
    {
        // jsonSerialize() withholds the token on purpose; storing that would store a grant that can
        // never authenticate.
        $cache = new Repository(new ArrayStore());

        (new CacheTokenStore($cache))->put('key', new AccessGrant('access', new DateTimeImmutable()));

        $stored = $cache->get('key');
        $this->assertIsArray($stored);
        $this->assertSame('access', $stored['access_token'] ?? null);
    }

    #[Test]
    public function a_grant_is_kept_without_a_cache_expiry(): void
    {
        // The grant carries its own, and ClientCredentials replaces an expired one.
        $cache = new Repository(new ArrayStore());
        $store = new CacheTokenStore($cache);

        $store->put('key', new AccessGrant('access', new DateTimeImmutable('+9 hours')));

        Carbon::setTestNow(Carbon::now()->addYear());

        try {
            $this->assertNotNull($store->get('key'));
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function an_unreadable_entry_is_forgotten_and_treated_as_absent(): void
    {
        $cache = new Repository(new ArrayStore());
        $cache->forever('key', ['access_token' => 'no expiry']);

        $this->assertNull((new CacheTokenStore($cache))->get('key'));
        $this->assertFalse($cache->has('key'));
    }

    #[Test]
    public function forgetting_removes_the_grant(): void
    {
        $store = new CacheTokenStore(new Repository(new ArrayStore()));
        $store->put('key', new AccessGrant('access', new DateTimeImmutable('+9 hours')));

        $store->forget('key');

        $this->assertNull($store->get('key'));
    }

    #[Test]
    public function a_second_process_reuses_the_first_ones_token(): void
    {
        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances())]);

        PayPal::transactions()->balances();

        // A new process: nothing memoised, only the cache left behind.
        $this->container()->forgetInstance(PayPalManager::class);
        $this->container()->forgetInstance(PayPalServiceProvider::TOKEN_STORE);
        PayPal::clearResolvedInstances();

        PayPal::transactions()->balances();

        $this->assertSame(1, $this->countTokenRequests());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'v1/reporting/balances')
            && $request->hasHeader('Authorization', 'Bearer ' . self::ACCESS_TOKEN));
    }

    #[Test]
    public function two_apps_each_get_a_token_of_their_own(): void
    {
        Http::fake([
            self::SANDBOX . '/v1/oauth2/token' => Http::sequence()
                ->push(self::token('first-token'))
                ->push(self::token('second-token')),
            self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances()),
        ]);

        PayPal::transactions()->balances();
        PayPal::client('second')->transactions()->balances();
        PayPal::transactions()->balances();

        $this->assertSame(2, $this->countTokenRequests());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'balances')
            && $request->hasHeader('Authorization', 'Bearer second-token'));
        $this->assertSame('first-token', $this->storedToken(self::CLIENT_ID));
        $this->assertSame('second-token', $this->storedToken(self::SECOND_CLIENT_ID));
    }

    #[Test]
    public function connections_naming_the_same_app_share_one_token(): void
    {
        $this->container()->make(Config::class)->set('paypal.connections.alias', [
            'client_id' => self::CLIENT_ID,
            'secret' => 'secret-under-test',
        ]);

        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances())]);

        PayPal::transactions()->balances();
        PayPal::client('alias')->transactions()->balances();

        $this->assertSame(1, $this->countTokenRequests());
    }

    #[Test]
    public function a_sandbox_token_is_never_offered_to_live(): void
    {
        // The core keys the store by host as well as client id. Asserted here because this package
        // puts every connection's tokens in one store.
        $this->container()->make(Config::class)->set('paypal.connections.live', [
            'client_id' => self::CLIENT_ID,
            'secret' => 'secret-under-test',
            'environment' => 'live',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            self::SANDBOX . '/v1/oauth2/token' => Http::response(self::token('sandbox-token')),
            'api-m.paypal.com/v1/oauth2/token' => Http::response(self::token('live-token')),
            '*/v1/reporting/balances*' => Http::response(self::balances()),
        ]);

        PayPal::transactions()->balances();
        PayPal::client('live')->transactions()->balances();

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api-m.paypal.com/v1/reporting/balances')
            && $request->hasHeader('Authorization', 'Bearer live-token'));
        $this->assertSame(2, $this->countTokenRequests());
    }

    #[Test]
    public function the_token_lands_in_the_configured_cache_store(): void
    {
        $config = $this->container()->make(Config::class);
        $config->set('cache.stores.tokens', ['driver' => 'array']);
        $config->set('paypal.cache_store', 'tokens');

        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances())]);

        PayPal::transactions()->balances();

        $key = ClientCredentials::keyFor(self::CLIENT_ID, self::SANDBOX);
        $this->assertIsArray(Cache::store('tokens')->get($key));
        $this->assertNull(Cache::store('array')->get($key));
    }

    #[Test]
    public function an_expired_grant_in_the_cache_is_replaced_by_asking_again(): void
    {
        // No refresh token: an expired token is replaced from the client id and secret.
        Cache::store('array')->forever(
            ClientCredentials::keyFor(self::CLIENT_ID, self::SANDBOX),
            (new AccessGrant('expired-token', new DateTimeImmutable('@0')))->toArray(),
        );

        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances())]);

        PayPal::transactions()->balances();

        $this->assertSame(1, $this->countTokenRequests());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'v1/oauth2/token')
            && $request['grant_type'] === 'client_credentials');
        Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer expired-token'));
        $this->assertSame(self::ACCESS_TOKEN, $this->storedToken(self::CLIENT_ID));
    }

    #[Test]
    public function an_application_can_bind_a_store_of_its_own(): void
    {
        $store = new CacheTokenStore(new Repository(new ArrayStore()));
        $this->container()->instance(PayPalServiceProvider::TOKEN_STORE, $store);

        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances())]);

        PayPal::transactions()->balances();

        $this->assertNotNull($store->get(ClientCredentials::keyFor(self::CLIENT_ID, self::SANDBOX)));
    }

    private function storedToken(string $clientId): mixed
    {
        $stored = Cache::store('array')->get(ClientCredentials::keyFor($clientId, self::SANDBOX));

        return is_array($stored) ? ($stored['access_token'] ?? null) : null;
    }

    private function countTokenRequests(): int
    {
        return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), 'v1/oauth2/token'))->count();
    }
}
