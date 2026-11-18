<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Laravel\Tests;

use DateTimeImmutable;
use Hampel\PayPal\Api\Authentication\AccessGrant;
use Hampel\PayPal\Api\Entity\AccountBalances;
use Hampel\PayPal\Api\Exception\NotAuthenticatedException;
use Hampel\PayPal\Api\Laravel\Facades\PayPal;
use Hampel\PayPal\Api\Laravel\PayPalManager;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * The README's examples, run.
 *
 * A pasted snippet gets hand-edited when the code around it changes and quietly stops matching what
 * the package does. These are the ones with a signature in them, so a renamed accessor, a changed
 * argument name or a changed return type fails here rather than being discovered by somebody
 * following the documentation.
 */
final class ReadmeExamplesTest extends TestCase
{
    #[Test]
    public function the_usage_examples_return_what_the_readme_says_they_do(): void
    {
        $this->fakePayPal([
            self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances()),
            self::SANDBOX . '/v1/reporting/transactions*' => Http::response(self::transactions(['5TY05013RG002845M'])),
        ]);

        $grant = PayPal::verify();
        $balances = PayPal::transactions()->balances();
        $rows = PayPal::transactions()->each(new DateTimeImmutable('-90 days'), new DateTimeImmutable());

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = $row->id;
            $this->assertSame('purchase-request-key', $row->customField);
        }

        $this->assertInstanceOf(AccessGrant::class, $grant);
        $this->assertTrue($grant->canSearchTransactions());
        $this->assertInstanceOf(AccountBalances::class, $balances);
        $this->assertContains('5TY05013RG002845M', $ids);
        $this->assertNotNull($rows->getReturn()->endDate);
    }

    #[Test]
    public function a_named_connection_and_an_injected_manager_are_reached_the_way_the_readme_says(): void
    {
        $this->container()->make(Config::class)->set('paypal.connections.second-account', [
            'client_id' => self::SECOND_CLIENT_ID,
            'secret' => 'second-secret-under-test',
            'environment' => 'sandbox',
        ]);

        $this->fakePayPal([
            self::SANDBOX . '/v2/payments/captures/*' => Http::response(['id' => '2GG279541U471931P']),
            self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances()),
        ]);

        $capture = PayPal::client('second-account')->captures()->find('2GG279541U471931P');
        $this->container()->make(PayPalManager::class)->client('second-account')->transactions()->balances();

        $this->assertSame('2GG279541U471931P', $capture?->id);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'v1/oauth2/token')
            && $request->hasHeader('Authorization', 'Basic ' . base64_encode(self::SECOND_CLIENT_ID . ':second-secret-under-test')));
    }

    #[Test]
    public function a_refused_credential_is_the_exception_the_readme_catches(): void
    {
        Http::fake([self::SANDBOX . '/v1/oauth2/token' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->expectException(NotAuthenticatedException::class);

        PayPal::transactions()->search(new DateTimeImmutable('-7 days'), new DateTimeImmutable());
    }

    #[Test]
    public function the_testing_example_runs_as_written(): void
    {
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

        $this->assertSame([], $balances->balances);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-token'));
    }
}
