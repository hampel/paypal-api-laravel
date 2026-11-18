<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Laravel\Tests;

use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Entity\Capture;
use Hampel\PayPal\Api\Exception\NotFoundException;
use Hampel\PayPal\Api\Laravel\Facades\PayPal;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Http::fake() sees the core package's traffic.
 *
 * hampel/paypal-api holds its own PSR-18 client, so by default nothing it sends is visible to
 * Http::fake() and an application testing against it has to fake at the transport library
 * instead. Everything below goes through the package's real request building, token handling,
 * status mapping and exception hierarchy; only the socket is replaced.
 */
final class HttpFakeTest extends TestCase
{
    private const CAPTURE = '2GG279541U471931P';

    #[Test]
    public function a_faked_response_reaches_the_caller_as_a_typed_entity(): void
    {
        $this->fakePayPal([self::SANDBOX . '/v2/payments/captures/*' => Http::response(['id' => self::CAPTURE, 'status' => 'COMPLETED'])]);

        $capture = PayPal::captures()->get(self::CAPTURE);

        $this->assertInstanceOf(Capture::class, $capture);
        $this->assertSame(self::CAPTURE, $capture->id);
        $this->assertSame('COMPLETED', $capture->status);
    }

    #[Test]
    public function the_token_request_and_the_data_request_are_both_recorded_for_assertion(): void
    {
        $this->fakePayPal([self::SANDBOX . '/v2/payments/captures/*' => Http::response(['id' => self::CAPTURE])]);

        PayPal::captures()->get(self::CAPTURE);

        Http::assertSentInOrder([
            fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://api-m.sandbox.paypal.com/v1/oauth2/token'
                && $request->hasHeader('Authorization', 'Basic ' . base64_encode(self::CLIENT_ID . ':secret-under-test'))
                && $request['grant_type'] === 'client_credentials',
            fn (Request $request): bool => $request->method() === 'GET'
                && $request->url() === 'https://api-m.sandbox.paypal.com/v2/payments/captures/' . self::CAPTURE
                && $request->hasHeader('Authorization', 'Bearer ' . self::ACCESS_TOKEN),
        ]);
    }

    #[Test]
    public function a_live_connection_sends_to_the_live_host_and_only_there(): void
    {
        $this->container()->make(Config::class)->set('paypal.connections.live', [
            'client_id' => 'live-client-id',
            'secret' => 'live-secret',
            'environment' => 'live',
        ]);

        Http::preventStrayRequests();
        $this->fakePayPal(['api-m.paypal.com/v1/reporting/balances*' => Http::response(self::balances())], 'api-m.paypal.com');

        PayPal::client('live')->transactions()->balances();

        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'sandbox'));
    }

    #[Test]
    public function faking_after_the_client_was_resolved_still_intercepts(): void
    {
        // The ordering a cached transport gets wrong. Factory::fake() REPLACES the factory's stub
        // collection, and createPendingRequest() copies whatever is there when it is called.
        $client = PayPal::client();

        Http::preventStrayRequests();
        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances('1.00'))]);

        $this->assertSame('1.00', self::available($client));
    }

    #[Test]
    public function swapping_the_factory_after_the_client_was_resolved_still_intercepts(): void
    {
        // Http::swap(new Factory) is how a test gets a clean set of fakes, because fake() merges and
        // the first match wins. The transport resolves the factory at the moment of sending.
        $client = PayPal::client();

        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances('1.00'))]);
        $this->assertSame('1.00', self::available($client));

        Http::swap(new Factory());
        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances('2.00'))]);

        $this->assertSame('2.00', self::available($client));
    }

    #[Test]
    public function a_swapped_factorys_stray_request_guard_applies_to_a_client_resolved_before_it(): void
    {
        // The dangerous half of the same property. preventStrayRequests() is set on the NEW factory;
        // a transport still holding the old, unfaked one would send the request for real, carrying
        // the configured credential.
        //
        // Pointed at a reserved .invalid host, so that if this ever regresses the escaped request
        // fails to resolve instead of reaching PayPal.
        $this->container()->make(Config::class)->set('paypal.connections.main.base_uri', 'https://api-m.paypal.invalid');

        $client = PayPal::client();

        Http::swap(new Factory());
        Http::preventStrayRequests();

        $this->expectException(StrayRequestException::class);

        $client->transactions()->balances();
    }

    #[Test]
    public function a_stray_request_is_reported_as_laravel_reports_it(): void
    {
        // Not disguised as the package's RequestException. Connection::dispatch() catches
        // ClientExceptionInterface, and StrayRequestException is a plain RuntimeException, so it
        // arrives with Laravel's own message and the URL still in it. The first request a client
        // makes is for its token, so that is the one named.
        $this->container()->make(Config::class)->set('paypal.connections.main.base_uri', 'https://api-m.paypal.invalid');

        Http::preventStrayRequests();
        Http::fake(['example.test/*' => Http::response([])]);

        $this->expectException(StrayRequestException::class);
        $this->expectExceptionMessage('https://api-m.paypal.invalid/v1/oauth2/token');

        PayPal::transactions()->balances();
    }

    #[Test]
    public function an_error_status_still_maps_to_the_packages_exception_hierarchy(): void
    {
        // The layer supplies the transport and nothing else. A 404 has to arrive as
        // NotFoundException, not as an unsuccessful Response.
        $this->fakePayPal([self::SANDBOX . '/v2/payments/captures/*' => Http::response(self::notFound(), 404)]);

        $this->expectException(NotFoundException::class);

        PayPal::captures()->get(self::CAPTURE);
    }

    #[Test]
    public function find_answers_null_for_a_missing_resource(): void
    {
        $this->fakePayPal([self::SANDBOX . '/v2/payments/captures/*' => Http::response(self::notFound(), 404)]);

        $this->assertNull(PayPal::captures()->find(self::CAPTURE));
    }

    #[Test]
    public function the_walk_hands_back_its_last_page_through_the_facade(): void
    {
        // Transactions::each() returns its last page from the generator, and its endDate is how far
        // PayPal's data reached. The manager and facade pass the generator through untouched, so
        // getReturn() still works on it.
        $this->fakePayPal([self::SANDBOX . '/v1/reporting/transactions*' => Http::response(self::transactions(['5TY05013RG002845M', '8MC585209K746392H']))]);

        $rows = PayPal::transactions()->each(new \DateTimeImmutable('2026-11-01T00:00:00Z'), new \DateTimeImmutable('2026-11-18T00:00:00Z'));

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame(['5TY05013RG002845M', '8MC585209K746392H'], $ids);
        $this->assertSame('2026-11-17T22:59:59+00:00', $rows->getReturn()->endDate?->format(DATE_ATOM));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'page_size=500'));
    }

    /**
     * The primary balance's available amount - read through a method so each call is a fresh
     * request to PHPStan as well as to PayPal.
     */
    private static function available(Client $client): ?string
    {
        return $client->transactions()->balances()->primary()?->available?->value;
    }

    /**
     * @return array<string, string>
     */
    private static function notFound(): array
    {
        return [
            'name' => 'RESOURCE_NOT_FOUND',
            'debug_id' => 'b1d1f06c7246c',
            'message' => 'The specified resource does not exist.',
        ];
    }
}
