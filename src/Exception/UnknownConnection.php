<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Laravel\Exception;

use Hampel\PayPal\Api\Exception\PayPalException;

/**
 * A connection was asked for by a name that is not in the configuration.
 *
 * Extends the core package's base exception, so an application already catching PayPalException -
 * or ExceptionInterface - catches a misconfigured connection name alongside every other way a call
 * can fail.
 */
final class UnknownConnection extends PayPalException
{
    /**
     * @param  list<string>  $configured
     */
    public static function named(string $name, array $configured): self
    {
        return new self(sprintf(
            'PayPal connection "%s" is not configured. %s',
            $name,
            $configured === []
                ? 'No connections are configured; add one under paypal.connections.'
                : 'Configured connections: ' . implode(', ', $configured) . '.'
        ));
    }
}
