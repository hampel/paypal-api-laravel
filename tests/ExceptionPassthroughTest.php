<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Laravel\Tests;

use Hampel\PayPal\Api\Exception\ClientException;
use Hampel\PayPal\Api\Exception\ExceptionInterface;
use Hampel\PayPal\Api\Exception\MalformedResponseException;
use Hampel\PayPal\Api\Exception\NotAuthenticatedException;
use Hampel\PayPal\Api\Exception\NotFoundException;
use Hampel\PayPal\Api\Exception\NotPermittedException;
use Hampel\PayPal\Api\Exception\ServerException;
use Hampel\PayPal\Api\Exception\TooManyRequestsException;
use Hampel\PayPal\Api\Exception\UnprocessableException;
use Hampel\PayPal\Api\Exception\ValidationException;
use Hampel\PayPal\Api\Laravel\Facades\PayPal;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The status-to-exception mapping survives the trip through Laravel's HTTP client.
 *
 * What the core package is worth is not its transport but its taxonomy: a client that reports
 * every unsuccessful status the same way cannot tell a missing capture from a refused app, and only
 * one of them is a configuration error. Laravel's own Http:: is the thing that flattens them.
 */
final class ExceptionPassthroughTest extends TestCase
{
    /**
     * @return array<string, array{int, class-string<\Throwable>}>
     */
    public static function statuses(): array
    {
        return [
            'a rejected request' => [400, ValidationException::class],
            'a feature the app does not have' => [403, NotPermittedException::class],
            'a missing capture' => [404, NotFoundException::class],
            'a request PayPal understood and refused' => [422, UnprocessableException::class],
            'rate limiting is typed so a caller can back off' => [429, TooManyRequestsException::class],
            'PayPal broke, not the caller' => [500, ServerException::class],
            'anything else the caller got wrong' => [418, ClientException::class],
        ];
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    #[Test]
    #[DataProvider('statuses')]
    public function a_status_arrives_as_its_own_exception(int $status, string $expected): void
    {
        $this->fakePayPal([self::SANDBOX . '/v2/payments/captures/*' => Http::response(['name' => 'ERROR', 'message' => 'Refused.'], $status)]);

        $this->expectException($expected);

        PayPal::captures()->get('2GG279541U471931P');
    }

    #[Test]
    public function a_refused_token_is_replaced_once_and_then_reported(): void
    {
        // A token can be revoked before its recorded expiry. The core package forgets it, asks for
        // another - through the token endpoint, which has to be faked like anything else - and
        // reports the second refusal rather than looping.
        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response(['error' => 'invalid_token'], 401)]);

        try {
            PayPal::transactions()->balances();
            $this->fail('Expected a NotAuthenticatedException.');
        } catch (NotAuthenticatedException) {
            $this->assertSame(2, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'v1/reporting/balances'))->count());
            $this->assertSame(2, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'v1/oauth2/token'))->count());
        }
    }

    #[Test]
    public function a_refused_client_id_and_secret_say_so(): void
    {
        // What PayPal answers a wrong secret with, measured 2026-09-15.
        Http::fake([self::SANDBOX . '/v1/oauth2/token' => Http::response(['error' => 'invalid_client', 'error_description' => 'Client Authentication failed'], 401)]);

        try {
            PayPal::transactions()->balances();
            $this->fail('Expected a NotAuthenticatedException.');
        } catch (NotAuthenticatedException $e) {
            $this->assertSame('invalid_client', $e->oauthError());
        }
    }

    #[Test]
    public function the_retry_after_header_survives_the_trip(): void
    {
        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response('', 429, ['Retry-After' => '30'])]);

        try {
            PayPal::transactions()->balances();
            $this->fail('Expected a TooManyRequestsException.');
        } catch (TooManyRequestsException $e) {
            $this->assertSame(30, $e->retryAfter);
        }
    }

    #[Test]
    public function a_success_whose_body_is_not_json_is_somebody_elses_answer(): void
    {
        // A maintenance page, a CDN challenge and a truncated body are all a 200 with something
        // other than JSON in it.
        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response('<html><body>Down for maintenance</body></html>', 200)]);

        $this->expectException(MalformedResponseException::class);

        PayPal::transactions()->balances();
    }

    #[Test]
    public function faking_with_no_arguments_fails_loudly_rather_than_reporting_an_empty_account(): void
    {
        // Http::fake() with no arguments answers every request with an empty 200 - the easiest
        // mistake to make in a consumer's suite. The token request is the first to reach it.
        //
        // The core refuses an empty body on a read as MalformedResponseException, but accepts one
        // on a POST, so the token request's empty answer reaches AccessGrant::fromResponse() and is
        // refused there as the core's InvalidArgumentException. Loud either way, which is what
        // this pins; the narrower type is the core's to choose.
        Http::fake();

        $this->expectException(ExceptionInterface::class);
        $this->expectExceptionMessage('without an access_token');

        PayPal::transactions()->balances();
    }

    #[Test]
    public function an_empty_answer_to_a_read_is_refused_once_a_token_is_in_hand(): void
    {
        $this->fakePayPal([self::SANDBOX . '/v1/reporting/balances*' => Http::response('', 200)]);

        $this->expectException(MalformedResponseException::class);

        PayPal::transactions()->balances();
    }
}
