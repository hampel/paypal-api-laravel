<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Laravel\Tests;

use Hampel\PayPal\Api\Authentication\ClientCredentials;
use Hampel\PayPal\Api\Laravel\PayPalManager;
use Hampel\PayPal\Api\Laravel\PayPalServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\Test;

/**
 * The package on a Laravel Zero application, which is not the same container as a Laravel one.
 *
 * Laravel binds Illuminate\Http\Client\Factory as a singleton in FoundationServiceProvider. Laravel
 * Zero does not register that provider, so unless this package binds one, the container builds a
 * fresh Factory on every make() and the instance this package sends through is not the instance
 * the Http facade configures - the fake does not intercept and the request goes to the real API.
 * Testbench boots a full application, so nothing in the rest of the suite can see it.
 *
 * Laravel Zero does register the cache, which the token store uses - and a CLI tool points it at a
 * file store, so a token outlives the run. The last test here is that.
 *
 * WHAT THIS DOES NOT PROVE: that a Laravel Zero application registers the provider. It does not -
 * Laravel Zero empties the package manifest, so a consumer lists the provider in config/app.php.
 * The application below registers it by hand.
 */
final class LaravelZeroTest extends \PHPUnit\Framework\TestCase
{
    private ?string $cacheDirectory = null;

    #[Test]
    public function a_fake_registered_after_the_client_was_resolved_still_intercepts(): void
    {
        $app = $this->laravelZeroApplication();

        $client = $app->make(PayPalManager::class)->client('main');

        Http::preventStrayRequests();
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'zero-token', 'expires_in' => 32400]),
            'api-m.sandbox.paypal.com/*' => Http::response(['account_id' => 'ZERO', 'balances' => []]),
        ]);

        try {
            $this->assertSame('ZERO', $client->transactions()->balances()->accountId);
        } finally {
            $this->tearDownFacades();
        }
    }

    #[Test]
    public function the_http_factory_is_bound_as_a_singleton_when_nothing_else_binds_one(): void
    {
        $app = $this->laravelZeroApplication();

        try {
            $this->assertTrue($app->bound(HttpClientFactory::class));
            $this->assertSame($app->make(HttpClientFactory::class), $app->make(HttpClientFactory::class));
            $this->assertSame($app->make(HttpClientFactory::class), Http::getFacadeRoot());
        } finally {
            $this->tearDownFacades();
        }
    }

    #[Test]
    public function an_application_that_already_binds_a_factory_keeps_its_own(): void
    {
        $existing = new HttpClientFactory();

        $app = $this->laravelZeroApplication(function (Application $app) use ($existing): void {
            $app->instance(HttpClientFactory::class, $existing);
        });

        try {
            $this->assertSame($existing, $app->make(HttpClientFactory::class));
        } finally {
            $this->tearDownFacades();
        }
    }

    #[Test]
    public function a_token_in_a_file_store_outlives_the_run_that_fetched_it(): void
    {
        // Two runs of a CLI tool, each a fresh application over the same cache directory.
        $this->cacheDirectory = sys_get_temp_dir() . '/' . uniqid('paypal-api-laravel-', true);

        foreach (['first-run-token', 'second-run-token'] as $offered) {
            $app = $this->laravelZeroApplication(function (Application $app): void {
                $app->make('config')->set('cache.stores.paypal', ['driver' => 'file', 'path' => $this->cacheDirectory]);
            });
            $app->make('config')->set('paypal.cache_store', 'paypal');

            Http::preventStrayRequests();
            Http::fake([
                'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => $offered, 'expires_in' => 32400]),
                'api-m.sandbox.paypal.com/*' => Http::response(['balances' => []]),
            ]);

            try {
                $app->make(PayPalManager::class)->client('main')->transactions()->balances();

                $stored = $app->make('cache')->store('paypal')->get(ClientCredentials::keyFor('zero-client-id', 'api-m.sandbox.paypal.com'));
                $this->assertIsArray($stored);
                $this->assertSame('first-run-token', $stored['access_token'] ?? null);

                Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'balances')
                    && $request->hasHeader('Authorization', 'Bearer first-run-token'));
            } finally {
                $this->tearDownFacades();
            }
        }
    }

    private function laravelZeroApplication(?callable $before = null): Application
    {
        $app = new Application(__DIR__ . '/..');
        $app->instance('config', new ConfigRepository());
        $app->singleton('files', static fn (): Filesystem => new Filesystem());
        $app->register(new EventServiceProvider($app));
        $app->register(new LogServiceProvider($app));
        $app->register(new CacheServiceProvider($app));

        $config = $app->make('config');
        $config->set('logging.default', 'null');
        $config->set('logging.channels.null', ['driver' => 'monolog', 'handler' => NullHandler::class]);
        $config->set('cache.default', 'array');
        $config->set('cache.stores.array', ['driver' => 'array']);

        if ($before !== null) {
            $before($app);
        }

        (new PayPalServiceProvider($app))->register();

        $config->set('paypal.connections.main', [
            'client_id' => 'zero-client-id',
            'secret' => 'zero-secret',
            'environment' => 'sandbox',
        ]);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        return $app;
    }

    private function tearDownFacades(): void
    {
        // A facade left pointing at a dead application fails a later test for a reason that has
        // nothing to do with it.
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }

    protected function tearDown(): void
    {
        if ($this->cacheDirectory !== null) {
            (new Filesystem())->deleteDirectory($this->cacheDirectory);
            $this->cacheDirectory = null;
        }

        parent::tearDown();
    }
}
