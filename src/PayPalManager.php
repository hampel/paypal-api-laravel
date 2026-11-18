<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Laravel;

use BadMethodCallException;
use Closure;
use Hampel\PayPal\Api\Authentication\ClientCredentials;
use Hampel\PayPal\Api\Authentication\TokenStore;
use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Config as ApiConfig;
use Hampel\PayPal\Api\Environment;
use Hampel\PayPal\Api\Exception\InvalidArgumentException;
use Hampel\PayPal\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\PayPal\Api\Laravel\Exception\UnknownConnection;
use Hampel\PayPal\Api\Result\TransactionPage;
use Illuminate\Contracts\Config\Repository as Config;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * One PayPal client per configured connection.
 *
 *     PayPal::transactions()->each($from, $to);              // the default connection
 *     PayPal::client('second-account')->transactions();      // a named one
 *
 * A connection is one REST app, and so one PayPal account: the app decides which account its token
 * reads.
 *
 * IT IS NAMED client() AND NOT connection(), which would be the Laravel word and is the one name
 * not available: `Client::connection()` is the core package's transport, and the manager forwards
 * unknown calls to the default client, so a connection() here would shadow it.
 *
 * SANDBOX UNLESS A CONNECTION SAYS LIVE. The core package has no default environment, on purpose;
 * this one has sandbox, because an unset variable must not point an application at real payers'
 * names, emails and money. A value that is neither name is refused, not guessed at.
 *
 * Clients are memoised per name for the life of the process. The token store is shared, keyed by
 * client id and host, so two connections naming the same app in the same environment share a token.
 *
 * The @mixin is what makes $manager->transactions() analysable: __call() forwards anything the
 * client answers to, and one line that cannot drift says so. The facade repeats the list explicitly
 * because @method static is the only form __callStatic() can carry, and a test keeps that copy
 * honest.
 *
 * @mixin Client
 */
final class PayPalManager
{
    /** @var array<string, Client> */
    private array $clients = [];

    private ?TokenStore $resolvedTokenStore = null;

    /**
     * @param  Closure(): TokenStore  $tokenStore  resolved on the first client built, so an
     *         application that never reaches PayPal never touches the cache
     */
    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
        private readonly Closure $tokenStore,
    ) {
    }

    /**
     * The client for a configured connection, or for the default when no name is given.
     */
    public function client(?string $name = null): Client
    {
        $name ??= $this->getDefaultConnection();

        return $this->clients[$name] ??= $this->build($name);
    }

    public function getDefaultConnection(): string
    {
        $default = $this->config->get('paypal.default');

        return is_string($default) && trim($default) !== '' ? trim($default) : 'main';
    }

    /**
     * The configured connection names, in the order they were declared.
     *
     * @return list<string>
     */
    public function configuredConnections(): array
    {
        $connections = $this->config->get('paypal.connections');

        return is_array($connections) ? array_values(array_filter(array_keys($connections), 'is_string')) : [];
    }

    private function build(string $name): Client
    {
        $connections = $this->config->get('paypal.connections');
        $settings = is_array($connections) ? ($connections[$name] ?? null) : null;

        if (! is_array($settings)) {
            throw UnknownConnection::named($name, $this->configuredConnections());
        }

        // Validated before the credential, so a refused environment or base URI is reported even
        // when the credential is missing too - and before the token store is resolved.
        $api = $this->api($name, $settings);
        [$clientId, $secret] = $this->credential($name, $settings);

        return new Client(
            $api,
            new ClientCredentials($clientId, $secret, $this->tokenStore()),
            $this->client,
            $this->requestFactory,
            $this->streamFactory,
            $this->logger,
        );
    }

    /**
     * The client id and secret, both or neither - and neither is refused too.
     *
     * @param  array<mixed>  $settings
     * @return array{string, string}
     */
    private function credential(string $name, array $settings): array
    {
        $clientId = $this->string($settings, 'client_id');
        $secret = $this->string($settings, 'secret');

        if ($clientId === null && $secret === null) {
            throw InvalidConfiguration::missingCredential($name);
        }

        if ($clientId === null) {
            throw InvalidConfiguration::incompleteCredential($name, 'client_id');
        }

        if ($secret === null) {
            throw InvalidConfiguration::incompleteCredential($name, 'secret');
        }

        return [$clientId, $secret];
    }

    /**
     * The core package's Config for one connection: its environment and base URI, plus the page
     * size shared by all.
     *
     * Its constructor validates, so a page size outside 1 to 500 or a base URI with no scheme is
     * refused here rather than on the first request - re-raised as InvalidConfiguration, so the
     * message names the configuration and not an argument.
     *
     * @param  array<mixed>  $settings
     */
    private function api(string $name, array $settings): ApiConfig
    {
        $environment = $this->environment($name, $settings['environment'] ?? null);
        $pageSize = $this->config->get('paypal.page_size');

        try {
            return new ApiConfig(
                $environment,
                is_numeric($pageSize) ? (int) $pageSize : TransactionPage::MAX_PAGE_SIZE,
                $this->string($settings, 'base_uri'),
            );
        } catch (InvalidArgumentException $e) {
            throw InvalidConfiguration::api($name, $e);
        }
    }

    /**
     * Sandbox when unset; live only when the connection names it.
     */
    private function environment(string $name, mixed $value): Environment
    {
        if ($value instanceof Environment) {
            return $value;
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return Environment::Sandbox;
        }

        if (! is_string($value)) {
            throw InvalidConfiguration::environment($name, $value);
        }

        try {
            return Environment::fromName($value);
        } catch (InvalidArgumentException) {
            throw InvalidConfiguration::environment($name, $value);
        }
    }

    private function tokenStore(): TokenStore
    {
        return $this->resolvedTokenStore ??= ($this->tokenStore)();
    }

    /**
     * One setting, as a non-empty trimmed string.
     *
     * Empty is treated as absent throughout, because an unset environment variable reaches config
     * as an empty string as readily as it reaches it as null. Trimmed, secret included: PayPal's
     * client ids and secrets are drawn from letters, digits, `-` and `_`, so surrounding whitespace
     * is always a stray newline from a secrets file and never part of the value.
     *
     * @param  array<mixed>  $settings
     */
    private function string(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Anything else goes to the default connection's client, so an application reading one account
     * never has to name one: `PayPal::transactions()` rather than
     * `PayPal::client('main')->transactions()`.
     *
     * The facade carries a @method line for each of these, which is where the types come from -
     * see Facades\PayPal, and the test that keeps the two in step.
     *
     * @param  array<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $client = $this->client();

        if (! method_exists($client, $method)) {
            throw new BadMethodCallException(sprintf(
                'Call to undefined method %s::%s(). The manager forwards to %s.',
                self::class,
                $method,
                Client::class
            ));
        }

        return $client->{$method}(...$arguments);
    }
}
