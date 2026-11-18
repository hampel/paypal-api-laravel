<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Laravel\Tests;

use Hampel\PayPal\Api\Laravel\Facades\PayPal;
use Hampel\PayPal\Api\Laravel\PayPalServiceProvider;
use Hampel\PayPal\Api\Laravel\Tests\Fixture\OverrideHttpClientProvider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * An application's override of paypal.http_client, registered before this package's provider.
 *
 * In a full Laravel application package providers register before the application's own, so an
 * override in AppServiceProvider comes last and would win whatever the package did. Laravel Zero
 * runs no discovery: the order is config/app.php, where AppServiceProvider is listed first. A
 * provider that claimed the key with singleton() would then replace the application's override
 * without a word, so it uses singletonIf().
 */
final class HttpClientOverrideTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [OverrideHttpClientProvider::class, PayPalServiceProvider::class];
    }

    #[Test]
    public function an_override_registered_before_this_provider_supplies_the_transport(): void
    {
        // Contained: stray requests are refused, so a request that reached Laravel's HTTP client
        // instead of the override fails rather than going out.
        Http::preventStrayRequests();

        $this->assertSame('FROM-OVERRIDE', PayPal::transactions()->balances()->accountId);
        $this->assertSame(
            [
                'POST https://api-m.sandbox.paypal.com/v1/oauth2/token',
                'GET https://api-m.sandbox.paypal.com/v1/reporting/balances',
            ],
            OverrideHttpClientProvider::$client->sent ?? []
        );
    }

    protected function tearDown(): void
    {
        OverrideHttpClientProvider::$client = null;

        parent::tearDown();
    }
}
