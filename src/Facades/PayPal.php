<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Laravel\Facades;

use Hampel\PayPal\Api\Authentication\AccessGrant;
use Hampel\PayPal\Api\Authentication\Authentication;
use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Config;
use Hampel\PayPal\Api\Connection;
use Hampel\PayPal\Api\Endpoint\Authorisation;
use Hampel\PayPal\Api\Endpoint\Captures;
use Hampel\PayPal\Api\Endpoint\Endpoint;
use Hampel\PayPal\Api\Endpoint\Plans;
use Hampel\PayPal\Api\Endpoint\Refunds;
use Hampel\PayPal\Api\Endpoint\Subscriptions;
use Hampel\PayPal\Api\Endpoint\Transactions;
use Hampel\PayPal\Api\Endpoint\Webhooks;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the PayPal API manager.
 *
 *     PayPal::transactions()->each($from, $to);              // the default connection
 *     PayPal::client('second-account')->transactions();      // a named one
 *
 * The operations are one hop further in, so the annotations below cover the hop and the endpoint
 * classes carry the typed signatures from there. Without them every call through the facade is
 * untyped to both the IDE and PHPStan, which is most of what a facade costs you.
 *
 * Everything after configuredConnections() mirrors a method on the core package's Client, reached
 * through the manager's __call(). FacadeConformanceTest asserts that the two lists stay identical,
 * so a method added to the client in a later release shows up as a failing test rather than as a
 * call that silently loses its type.
 *
 * NOTE WHICH connection() THIS IS. It is the core package's transport, not a way to reach a
 * configured connection, which is client(). See PayPalManager.
 *
 * endpoint() is the one annotation that gives something up: on the client it is generic, returning
 * the class it was handed, and a @method line cannot express that. Reach for
 * `PayPal::client()->endpoint(Foo::class)` where the generic return matters.
 *
 * @method static Client client(?string $name = null)
 * @method static string getDefaultConnection()
 * @method static list<string> configuredConnections()
 * @method static Config config()
 * @method static Authentication authentication()
 * @method static AccessGrant verify()
 * @method static Client withCredential(Authentication $authentication)
 * @method static Client withConfig(Config $config)
 * @method static Endpoint endpoint(string $class)
 * @method static Transactions transactions()
 * @method static Captures captures()
 * @method static Refunds refunds()
 * @method static Subscriptions subscriptions()
 * @method static Plans plans()
 * @method static Webhooks webhooks()
 * @method static Authorisation authorisation()
 * @method static Connection connection()
 *
 * @see \Hampel\PayPal\Api\Laravel\PayPalManager
 * @see \Hampel\PayPal\Api\Client
 */
final class PayPal extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Hampel\PayPal\Api\Laravel\PayPalManager::class;
    }
}
