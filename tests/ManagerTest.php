<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Laravel\Tests;

use Hampel\PayPal\Api\Authentication\ClientCredentials;
use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Environment;
use Hampel\PayPal\Api\Exception\ExceptionInterface;
use Hampel\PayPal\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\PayPal\Api\Laravel\Exception\UnknownConnection;
use Hampel\PayPal\Api\Laravel\Facades\PayPal;
use Hampel\PayPal\Api\Laravel\PayPalManager;
use Illuminate\Contracts\Config\Repository as Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class ManagerTest extends TestCase
{
    #[Test]
    public function it_resolves_a_client_for_a_named_connection(): void
    {
        $client = $this->manager()->client('second');

        $this->assertInstanceOf(Client::class, $client);
        $authentication = $client->authentication();
        $this->assertInstanceOf(ClientCredentials::class, $authentication);
        $this->assertSame(self::SECOND_CLIENT_ID, $authentication->clientId());
    }

    #[Test]
    public function it_defaults_to_the_configured_connection(): void
    {
        $this->assertSame('main', $this->manager()->getDefaultConnection());
        $this->assertSame($this->manager()->client('main'), $this->manager()->client());
    }

    #[Test]
    public function clients_are_memoised_per_connection(): void
    {
        $manager = $this->manager();

        $this->assertSame($manager->client('main'), $manager->client('main'));
        $this->assertNotSame($manager->client('main'), $manager->client('second'));
    }

    #[Test]
    public function the_manager_is_a_singleton_and_the_facade_resolves_it(): void
    {
        $this->assertSame($this->manager(), $this->container()->make(PayPalManager::class));
        $this->assertSame($this->manager(), PayPal::getFacadeRoot());
    }

    #[Test]
    public function it_lists_the_configured_connections(): void
    {
        $this->assertSame(['main', 'second'], $this->manager()->configuredConnections());
    }

    #[Test]
    public function an_unknown_connection_names_the_ones_that_are_configured(): void
    {
        $this->expectException(UnknownConnection::class);
        $this->expectExceptionMessage('Configured connections: main, second.');

        $this->manager()->client('nope');
    }

    #[Test]
    public function the_packages_exceptions_are_catchable_alongside_the_cores(): void
    {
        $this->expectException(ExceptionInterface::class);

        $this->manager()->client('nope');
    }

    #[Test]
    public function the_environment_is_sandbox_when_the_connection_says_sandbox(): void
    {
        $config = $this->manager()->client()->config();

        $this->assertSame(Environment::Sandbox, $config->environment);
        $this->assertSame('https://api-m.sandbox.paypal.com', $config->baseUri);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unsetEnvironments(): array
    {
        return [
            'absent' => [null],
            'empty, as an unset variable arrives' => [''],
            'whitespace' => ['  '],
        ];
    }

    #[Test]
    #[DataProvider('unsetEnvironments')]
    public function an_unset_environment_is_sandbox_never_live(mixed $environment): void
    {
        $this->configure('unset', ['client_id' => 'id', 'secret' => 's', 'environment' => $environment]);

        $this->assertSame(Environment::Sandbox, $this->manager()->client('unset')->config()->environment);
    }

    #[Test]
    public function a_connection_without_an_environment_key_is_sandbox(): void
    {
        $this->configure('bare', ['client_id' => 'id', 'secret' => 's']);

        $this->assertSame(Environment::Sandbox, $this->manager()->client('bare')->config()->environment);
    }

    #[Test]
    public function live_is_used_only_when_a_connection_names_it(): void
    {
        $this->configure('live', ['client_id' => 'id', 'secret' => 's', 'environment' => ' Live ']);

        $config = $this->manager()->client('live')->config();

        $this->assertSame(Environment::Live, $config->environment);
        $this->assertSame('https://api-m.paypal.com', $config->baseUri);
    }

    #[Test]
    public function an_environment_set_as_the_enum_in_a_php_config_is_honoured(): void
    {
        $this->configure('enum', ['client_id' => 'id', 'secret' => 's', 'environment' => Environment::Live]);

        $this->assertSame(Environment::Live, $this->manager()->client('enum')->config()->environment);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unknownEnvironments(): array
    {
        return [
            'a typo for live' => ['lve'],
            'another word for live' => ['production'],
            'a boolean' => [true],
        ];
    }

    #[Test]
    #[DataProvider('unknownEnvironments')]
    public function an_environment_that_is_neither_name_is_refused_rather_than_guessed(mixed $environment): void
    {
        // Not read as sandbox, because the typo might have been for live and the application would
        // then report an empty sandbox account as though it were the real one.
        $this->configure('typo', ['client_id' => 'id', 'secret' => 's', 'environment' => $environment]);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('PayPal connection "typo" has an environment of');

        $this->manager()->client('typo');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function halfACredential(): array
    {
        return [
            'a client id without a secret' => [['client_id' => 'id'], 'has a client_id but no secret'],
            'a secret without a client id' => [['secret' => 's'], 'has a secret but no client_id'],
            'a blank secret beside a client id' => [['client_id' => 'id', 'secret' => '   '], 'has a client_id but no secret'],
        ];
    }

    /**
     * @param  array<string, mixed>  $credential
     */
    #[Test]
    #[DataProvider('halfACredential')]
    public function half_a_credential_is_refused_by_name(array $credential, string $message): void
    {
        $this->configure('half', $credential);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage($message);

        $this->manager()->client('half');
    }

    #[Test]
    public function a_connection_without_a_credential_is_refused(): void
    {
        // Refused here rather than allowed to 401 on first use, which would read as a revoked app
        // rather than as an unset environment variable.
        $this->configure('nowhere', ['client_id' => '', 'secret' => null]);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('PayPal connection "nowhere" has no credential');

        $this->manager()->client('nowhere');
    }

    #[Test]
    public function a_credential_is_trimmed_of_the_newline_a_secrets_file_leaves(): void
    {
        $this->configure('padded', ['client_id' => " padded-id\n", 'secret' => "s\n"]);

        $authentication = $this->manager()->client('padded')->authentication();

        $this->assertInstanceOf(ClientCredentials::class, $authentication);
        $this->assertSame('padded-id', $authentication->clientId());
    }

    #[Test]
    public function the_page_size_defaults_to_paypals_maximum(): void
    {
        $this->assertSame(500, $this->manager()->client()->config()->pageSize);
    }

    #[Test]
    public function the_api_settings_reach_the_cores_config(): void
    {
        $config = $this->container()->make(Config::class);
        $config->set('paypal.page_size', '50');
        $config->set('paypal.connections.main.base_uri', 'https://paypal.example.test/');

        $api = $this->manager()->client()->config();

        $this->assertSame('https://paypal.example.test', $api->baseUri);
        $this->assertSame(50, $api->pageSize);
        $this->assertSame(Environment::Sandbox, $api->environment);
    }

    #[Test]
    public function a_base_uri_belongs_to_its_connection_alone(): void
    {
        $this->container()->make(Config::class)->set('paypal.connections.main.base_uri', 'https://paypal.example.test');

        $this->assertSame('https://api-m.sandbox.paypal.com', $this->manager()->client('second')->config()->baseUri);
    }

    #[Test]
    public function a_page_size_above_the_apis_maximum_names_the_configuration(): void
    {
        $this->container()->make(Config::class)->set('paypal.page_size', 501);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('paypal.page_size');

        $this->manager()->client();
    }

    #[Test]
    public function a_base_uri_without_a_scheme_is_refused_before_a_request_is_made(): void
    {
        $this->container()->make(Config::class)->set('paypal.connections.main.base_uri', 'api-m.paypal.com');

        try {
            $this->manager()->client();
            $this->fail('Expected an InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            $this->assertStringContainsString('connection "main"', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
            $this->assertStringContainsString('must be absolute', $e->getPrevious()->getMessage());
        }
    }

    #[Test]
    public function an_unset_default_falls_back_to_main(): void
    {
        $this->container()->make(Config::class)->set('paypal.default', '');

        $this->assertSame('main', $this->manager()->getDefaultConnection());
    }

    #[Test]
    public function an_empty_inventory_is_inspectable_rather_than_fatal(): void
    {
        $this->container()->make(Config::class)->set('paypal.connections', []);

        $manager = $this->manager();

        $this->assertSame([], $manager->configuredConnections());

        $this->expectException(UnknownConnection::class);
        $this->expectExceptionMessage('No connections are configured');

        $manager->client('somewhere');
    }

    #[Test]
    public function unnamed_calls_are_forwarded_to_the_default_connection(): void
    {
        $this->assertSame($this->manager()->client()->transactions(), $this->manager()->transactions());
    }

    #[Test]
    public function a_method_the_client_does_not_have_is_a_bad_method_call(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Call to undefined method');

        $this->manager()->__call('noSuchEndpoint', []);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function configure(string $name, array $settings): void
    {
        $config = $this->container()->make(Config::class);

        $connections = $config->get('paypal.connections');
        $config->set('paypal.connections', array_merge(is_array($connections) ? $connections : [], [$name => $settings]));
    }

    private function manager(): PayPalManager
    {
        return $this->container()->make(PayPalManager::class);
    }
}
