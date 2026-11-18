<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Laravel\Exception;

use Hampel\PayPal\Api\Exception\PayPalException;

/**
 * The configuration cannot be turned into a client.
 *
 * Raised when the client is built rather than letting a half-configured connection through. A
 * missing secret would otherwise reach PayPal and come back 401 `invalid_client` - which reads as a
 * revoked app rather than as an unset environment variable.
 */
final class InvalidConfiguration extends PayPalException
{
    public static function missingCredential(string $connection): self
    {
        return new self(sprintf(
            'PayPal connection "%s" has no credential. Set its client_id and secret in config/paypal.php '
                . '- or in the environment if the shipped config is in use.',
            $connection
        ));
    }

    /**
     * One half of the client credentials.
     */
    public static function incompleteCredential(string $connection, string $missing): self
    {
        return new self(sprintf(
            'PayPal connection "%s" has a %s but no %s. A REST app needs both.',
            $connection,
            $missing === 'secret' ? 'client_id' : 'secret',
            $missing
        ));
    }

    /**
     * An environment that is neither name. Refused rather than read as sandbox, since the typo
     * might have been for live - and never read as live, since live is only ever asked for.
     */
    public static function environment(string $connection, mixed $value): self
    {
        return new self(sprintf(
            'PayPal connection "%s" has an environment of %s. Use "sandbox" or "live" - or leave it '
                . 'unset for sandbox.',
            $connection,
            is_scalar($value) ? var_export($value, true) : get_debug_type($value)
        ));
    }

    /**
     * A container binding an application rebinds to supply its own implementation holds
     * something else.
     */
    public static function binding(string $key, string $expected, string $type): self
    {
        return new self(sprintf(
            'The container binding %s must be a %s; it resolved to %s.',
            $key,
            $expected,
            $type
        ));
    }

    /**
     * The core package refused a setting.
     *
     * Wrapped rather than passed through, so the message names the configuration rather than an
     * argument - and kept as the previous exception, so the original reason is still readable.
     */
    public static function api(string $connection, \Throwable $previous): self
    {
        return new self(
            sprintf(
                'The paypal.page_size and connection "%s" settings do not describe an API this package '
                    . 'can talk to. %s',
                $connection,
                $previous->getMessage()
            ),
            0,
            $previous
        );
    }
}
