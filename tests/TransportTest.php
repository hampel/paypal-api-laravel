<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Laravel\Tests;

use Hampel\PayPal\Api\Laravel\Facades\PayPal;
use Hampel\PayPal\Api\Laravel\PayPalManager;
use Hampel\PayPal\Api\Laravel\PayPalServiceProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class TransportTest extends TestCase
{
    #[Test]
    public function a_replacement_transport_is_used_by_every_connection(): void
    {
        // The reason the transport is bound under its own key rather than constructed inside the
        // manager: an application with its own outbound HTTP policy binds it there and this package
        // uses it, token requests included, instead of the application writing a second client.
        $recorder = new class () implements ClientInterface {
            /** @var list<string> */
            public array $sent = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request->getMethod() . ' ' . $request->getUri();

                $body = str_ends_with($request->getUri()->getPath(), 'v1/oauth2/token')
                    ? '{"access_token":"recorded-token","expires_in":32400}'
                    : '{"balances":[]}';

                return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], $body);
            }
        };

        $this->container()->instance(PayPalServiceProvider::HTTP_CLIENT, $recorder);

        PayPal::transactions()->balances();
        PayPal::client('second')->transactions()->balances();

        $this->assertSame([
            'POST https://api-m.sandbox.paypal.com/v1/oauth2/token',
            'GET https://api-m.sandbox.paypal.com/v1/reporting/balances',
            'POST https://api-m.sandbox.paypal.com/v1/oauth2/token',
            'GET https://api-m.sandbox.paypal.com/v1/reporting/balances',
        ], $recorder->sent);
    }

    #[Test]
    public function global_request_middleware_reaches_this_packages_requests(): void
    {
        Http::globalRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Application', 'under-test'));

        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances())]);

        PayPal::transactions()->balances();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'v1/reporting/balances')
            && $request->hasHeader('X-Application', 'under-test'));
    }

    #[Test]
    public function the_configured_timeouts_reach_the_request(): void
    {
        // Not automatic. PendingRequest merges its options only inside its own sendRequest(), and
        // the adapter sends through the built Guzzle client instead, so the timeouts set on the
        // pending request never reach a request unless passed on by hand. Read at the handler,
        // which is where Guzzle acts on them.
        $this->container()->make(Config::class)->set('paypal.timeout', 7);
        $this->container()->make(Config::class)->set('paypal.connect_timeout', 3);
        $this->container()->forgetInstance(PayPalServiceProvider::HTTP_CLIENT);
        $this->container()->forgetInstance(PayPalManager::class);

        $options = $this->optionsSeenByTheHandler();

        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances())]);

        PayPal::transactions()->balances();

        $this->assertCount(2, $options);

        foreach ($options as $seen) {
            $this->assertSame(7.0, $seen['timeout'] ?? null);
            $this->assertSame(3.0, $seen['connect_timeout'] ?? null);
        }
    }

    #[Test]
    public function a_global_transport_option_reaches_the_request(): void
    {
        Http::globalOptions([
            'verify' => '/etc/ssl/certs/corporate-ca.pem',
            'proxy' => ['https' => 'http://proxy.example.test:3128', 'no' => ['localhost']],
        ]);

        $options = $this->optionsSeenByTheHandler();

        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances())]);

        PayPal::transactions()->balances();

        $this->assertSame('/etc/ssl/certs/corporate-ca.pem', $options[0]['verify'] ?? null);
        $this->assertSame(['https' => 'http://proxy.example.test:3128', 'no' => ['localhost']], $options[0]['proxy'] ?? null);
    }

    #[Test]
    public function a_global_option_that_would_change_the_request_is_not_applied(): void
    {
        // The core package builds the request; the transport sends it as built. A global header
        // would replace the bearer token or the token request's Basic credential, and a global
        // query or body the search parameters or the grant type.
        Http::globalOptions([
            'headers' => ['Accept' => 'text/html', 'Authorization' => 'Bearer not-this-one'],
            'query' => ['injected' => '1'],
            'form_params' => ['grant_type' => 'password'],
            'json' => ['injected' => true],
        ]);

        $this->fakePayPal([self::SANDBOX . '/v1/reporting/transactions*' => Http::response(self::transactions([]))]);

        PayPal::transactions()->search(new \DateTimeImmutable('2026-11-01T00:00:00Z'), new \DateTimeImmutable('2026-11-10T00:00:00Z'));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'v1/oauth2/token')
            && $request->hasHeader('Authorization', 'Basic ' . base64_encode(self::CLIENT_ID . ':secret-under-test'))
            && $request['grant_type'] === 'client_credentials');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'v1/reporting/transactions?start_date=')
            && ! str_contains($request->url(), 'injected')
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Authorization', 'Bearer ' . self::ACCESS_TOKEN));
    }

    #[Test]
    public function request_sending_fires_and_response_received_does_not(): void
    {
        // RequestSending is dispatched from a before-sending callback INSIDE the handler stack this
        // adapter drives, so it fires. ResponseReceived is dispatched from PendingRequest::send(),
        // a layer above that stack, which the adapter never calls.
        $sending = 0;
        $received = 0;

        Event::listen(RequestSending::class, function () use (&$sending): void {
            $sending++;
        });
        Event::listen(ResponseReceived::class, function () use (&$received): void {
            $received++;
        });

        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances())]);

        PayPal::transactions()->balances();

        $this->assertSame(2, $sending);
        $this->assertSame(0, $received);
    }

    #[Test]
    public function the_configured_base_uri_is_where_the_requests_go(): void
    {
        // Asserted through the transport rather than only on the Config object, because a base URI
        // that reached Config and not the wire would look configured and change nothing.
        $this->container()->make(Config::class)->set('paypal.connections.main.base_uri', 'http://localhost:8080');

        Http::fake([
            'localhost:8080/v1/oauth2/token' => Http::response(self::token()),
            'localhost:8080/*' => Http::response(self::balances()),
        ]);

        PayPal::transactions()->balances();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8080/v1/oauth2/token');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8080/v1/reporting/balances');
    }

    #[Test]
    public function the_configured_page_size_is_asked_for_on_every_search(): void
    {
        $this->container()->make(Config::class)->set('paypal.page_size', 40);

        $this->fakePayPal([self::SANDBOX . '/v1/reporting/transactions*' => Http::response(self::transactions([]))]);

        PayPal::transactions()->search(new \DateTimeImmutable('2026-11-01T00:00:00Z'), new \DateTimeImmutable('2026-11-10T00:00:00Z'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'v1/reporting/transactions?')
            && str_contains($request->url(), 'page_size=40'));
    }

    /**
     * Every transport option the adapter passes on, one case each.
     *
     * A line dropped from the allowlist loses an application's certificate, proxy or curl
     * settings without an error: the request still goes out, without them. The values are inert -
     * the fake answers before Guzzle opens a file or a connection.
     *
     * @return array<string, array{string, mixed, mixed}>
     */
    public static function transportOptions(): array
    {
        $proxy = ['http' => 'http://proxy.example.test:3128', 'no' => 'localhost'];

        return [
            'read_timeout' => ['read_timeout', 12.5, 12.5],
            'version' => ['version', '2.0', '2.0'],
            'force_ip_resolve' => ['force_ip_resolve', 'v4', 'v4'],
            'crypto_method' => ['crypto_method', STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT],
            'crypto_method_max' => ['crypto_method_max', STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT, STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT],
            'decode_content' => ['decode_content', 'gzip', 'gzip'],
            'cert as a path' => ['cert', '/etc/ssl/client.pem', '/etc/ssl/client.pem'],
            'cert with a password' => ['cert', ['/etc/ssl/client.pem', 'cert-password'], ['/etc/ssl/client.pem', 'cert-password']],
            'cert with no password' => ['cert', ['/etc/ssl/client.pem'], ['/etc/ssl/client.pem']],
            'cert_type' => ['cert_type', 'P12', 'P12'],
            'ssl_key as a path' => ['ssl_key', '/etc/ssl/client.key', '/etc/ssl/client.key'],
            'ssl_key with a password' => ['ssl_key', ['/etc/ssl/client.key', 'key-password'], ['/etc/ssl/client.key', 'key-password']],
            'ssl_key_type' => ['ssl_key_type', 'PEM', 'PEM'],
            'proxy for every scheme' => ['proxy', 'http://proxy.example.test:3128', 'http://proxy.example.test:3128'],
            'proxy for http, excluding one host' => ['proxy', $proxy, $proxy],
            // 213 is CURLOPT_TCP_KEEPALIVE, by number so the suite does not need ext-curl.
            'curl' => ['curl', [213 => 1], [213 => 1]],
        ];
    }

    #[Test]
    #[DataProvider('transportOptions')]
    public function each_allowlisted_transport_option_reaches_the_request(string $option, mixed $value, mixed $expected): void
    {
        Http::globalOptions([$option => $value]);

        $options = $this->optionsSeenByTheHandler();

        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response(self::balances())]);

        PayPal::transactions()->balances();

        // The token request and the balances request.
        $this->assertCount(2, $options);

        foreach ($options as $seen) {
            $this->assertArrayHasKey($option, $seen);
            $this->assertSame($expected, $seen[$option]);
        }
    }

    /**
     * Records the Guzzle options of every request, as the handler receives them.
     *
     * @return \ArrayObject<int, array<array-key, mixed>>
     */
    private function optionsSeenByTheHandler(): \ArrayObject
    {
        /** @var \ArrayObject<int, array<array-key, mixed>> $seen */
        $seen = new \ArrayObject();

        Http::globalMiddleware(static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler, $seen): mixed {
            $seen[] = $options;

            return $handler($request, $options);
        });

        return $seen;
    }
}
