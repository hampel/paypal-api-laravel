<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Laravel\Tests;

use Hampel\PayPal\Api\Laravel\Facades\PayPal;
use Hampel\PayPal\Api\Laravel\PayPalServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Testbench boots a minimal Laravel application from inside this package, so the Laravel version
 * under test comes from Composer resolution rather than from an installed framework.
 *
 * Two connections, both sandbox: `main`, the default, and `second`, another app on another account.
 * Nothing in the suite is configured for live except where a test says so, and then it is faked or
 * pointed at a reserved .invalid host.
 */
abstract class TestCase extends BaseTestCase
{
    protected const CLIENT_ID = 'client-id-under-test';

    protected const SECOND_CLIENT_ID = 'second-client-id-under-test';

    protected const ACCESS_TOKEN = 'access-token-under-test';

    protected const SANDBOX = 'api-m.sandbox.paypal.com';

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PayPalServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['PayPal' => PayPal::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');

        $app['config']->set('paypal.default', 'main');
        $app['config']->set('paypal.connections', [
            'main' => [
                'client_id' => self::CLIENT_ID,
                'secret' => 'secret-under-test',
                'environment' => 'sandbox',
            ],
            'second' => [
                'client_id' => self::SECOND_CLIENT_ID,
                'secret' => 'second-secret-under-test',
                'environment' => 'sandbox',
            ],
        ]);
        $app['config']->set('paypal.cache_store', null);
        $app['config']->set('paypal.page_size', null);
    }

    /**
     * Fakes PayPal's token endpoint alongside the given routes.
     *
     * The token route is registered first because Http::fake() answers from the first pattern
     * that matches, and a test's catch-all `api-m.sandbox.paypal.com/*` would otherwise answer the
     * token request.
     *
     * @param  array<string, mixed>  $routes
     */
    protected function fakePayPal(array $routes, string $host = self::SANDBOX): void
    {
        Http::fake([$host . '/v1/oauth2/token' => Http::response(self::token())] + $routes);
    }

    /**
     * What PayPal's token endpoint answers with.
     *
     * @return array<string, mixed>
     */
    protected static function token(string $accessToken = self::ACCESS_TOKEN): array
    {
        return [
            'scope' => 'https://uri.paypal.com/services/reporting/search/read openid',
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'app_id' => 'APP-80W284485P519543T',
            'expires_in' => 32400,
            'nonce' => '2026-11-18T00:00:00ZexampleNonce',
        ];
    }

    /**
     * `GET /v1/reporting/balances`, trimmed to the fields the tests read.
     *
     * @return array<string, mixed>
     */
    protected static function balances(string $available = '1500.00'): array
    {
        return [
            'balances' => [[
                'currency' => 'AUD',
                'primary' => true,
                'total_balance' => ['currency_code' => 'AUD', 'value' => $available],
                'available_balance' => ['currency_code' => 'AUD', 'value' => $available],
            ]],
            'account_id' => 'XZXSPECPDZHZU',
            'as_of_time' => '2026-11-18T00:00:00Z',
            'last_refresh_time' => '2026-11-17T23:59:59Z',
        ];
    }

    /**
     * One page of `GET /v1/reporting/transactions`.
     *
     * @param  list<string>  $ids
     * @return array<string, mixed>
     */
    protected static function transactions(array $ids, string $endDate = '2026-11-17T22:59:59+0000'): array
    {
        return [
            'transaction_details' => array_map(static fn (string $id): array => [
                'transaction_info' => [
                    'transaction_id' => $id,
                    'transaction_event_code' => 'T0002',
                    'transaction_amount' => ['currency_code' => 'USD', 'value' => '10.00'],
                    'transaction_status' => 'S',
                    'custom_field' => 'purchase-request-key',
                ],
            ], $ids),
            'account_number' => 'XZXSPECPDZHZU',
            'start_date' => '2026-11-01T00:00:00+0000',
            'end_date' => $endDate,
            'last_refreshed_datetime' => $endDate,
            'page' => 1,
            'total_items' => count($ids),
            'total_pages' => 1,
        ];
    }

    /**
     * The application, narrowed.
     *
     * Testbench declares $app as nullable because it does not exist before setUp, so every use of
     * it in a test is otherwise a call on Application|null.
     */
    protected function container(): Application
    {
        $app = $this->app;

        $this->assertNotNull($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel's HandleExceptions bootstrapper replaces PHPUnit's error handler when the app
        // boots, and shouldIgnoreDeprecationErrors() discards deprecations outright while running
        // tests - so phpunit.xml's failOnDeprecation never sees one and is inert in any
        // Testbench-based package. This throws on them instead.
        $this->withoutDeprecationHandling();

        // Every test is contained, not only the ones that remember to be. A route a test did not
        // fake is refused rather than sent - which matters most when a fix is reverted to probe
        // it, since a reverted fix is exactly the request that escapes. Reverting the sandbox
        // default once sent an unfaked token request to the live host before this line existed.
        // A test that swaps the factory sets it again on the new one.
        Http::preventStrayRequests();
    }
}
