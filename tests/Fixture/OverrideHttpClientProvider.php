<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Laravel\Tests\Fixture;

use GuzzleHttp\Psr7\Response;
use Hampel\PayPal\Api\Laravel\PayPalServiceProvider;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * An application's own provider overriding this package's transport - AppServiceProvider, as a
 * Laravel Zero application lists it: before the package provider, because Laravel Zero runs no
 * package discovery and config/app.php is the whole order.
 */
final class OverrideHttpClientProvider extends ServiceProvider
{
    public static ?OverrideHttpClient $client = null;

    public function register(): void
    {
        self::$client = new OverrideHttpClient();

        $this->app->singleton(PayPalServiceProvider::HTTP_CLIENT, static fn (): ClientInterface => self::$client ?? new OverrideHttpClient());
    }
}

/**
 * @internal
 */
final class OverrideHttpClient implements ClientInterface
{
    /** @var list<string> */
    public array $sent = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request->getMethod() . ' ' . $request->getUri();

        $body = str_ends_with($request->getUri()->getPath(), 'v1/oauth2/token')
            ? '{"access_token":"override-token","expires_in":32400}'
            : '{"account_id":"FROM-OVERRIDE","balances":[]}';

        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }
}
