<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    |
    | Which entry under "connections" a call without a name uses:
    | PayPal::transactions() is PayPal::client(<this>)->transactions(). An
    | application reading one PayPal account never needs to name it.
    |
    */

    'default' => env('PAYPAL_CONNECTION', 'main'),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | One entry per PayPal account you read, named however you like -- the name
    | is what PayPal::client('...') takes. Each is a REST app's client id and
    | secret, from the Apps & Credentials page of PayPal's developer dashboard.
    | The app belongs to one account, and that is the account the token reads.
    |
    | environment: "sandbox" or "live". SANDBOX UNLESS A CONNECTION SAYS LIVE.
    | An unset or empty value is sandbox; anything other than the two names is
    | refused rather than guessed at. Sandbox and live are separate hosts with
    | separate apps, so a sandbox credential is refused by the live host -- the
    | mistake that does not fail loudly is a live credential in a test, which
    | is why live has to be asked for.
    |
    | base_uri: null uses the environment's own host. For a recorded fixture
    | served locally or an outbound proxy that terminates the connection, and
    | nothing else.
    |
    | Connections sharing a client id and host share one cached token.
    |
    */

    'connections' => [

        'main' => [
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'secret' => env('PAYPAL_SECRET'),
            'environment' => env('PAYPAL_ENVIRONMENT', 'sandbox'),
            'base_uri' => env('PAYPAL_API_URL'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | Where access tokens are kept, so every process shares one rather than
    | asking PayPal again. Null uses the application's default store.
    |
    | A CACHED TOKEN IS A CREDENTIAL. It is stored as the cache stores anything
    | else, unencrypted, and reads the account's whole transaction history for
    | the nine hours it lasts. Name a store here that is no more widely
    | readable than your .env file. The "null" store keeps nothing, which asks
    | PayPal for a token in every PHP process.
    |
    */

    'cache_store' => env('PAYPAL_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Page Size
    |--------------------------------------------------------------------------
    |
    | Transactions per search request, from 1 to 500. Null uses 500, PayPal's
    | maximum, rather than its own default of 100: a reconciliation reads every
    | row, and every page is a request.
    |
    */

    'page_size' => env('PAYPAL_PAGE_SIZE'),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Applied to Laravel's HTTP client on every request, alongside a consumer's own
    | Http::globalRequestMiddleware() and the transport settings from
    | Http::globalOptions() -- proxy, TLS, protocol version, curl options. Global
    | headers, auth, query and body options are not applied: they would change the
    | request the core package built.
    |
    */

    'timeout' => env('PAYPAL_TIMEOUT', 30),

    'connect_timeout' => env('PAYPAL_CONNECT_TIMEOUT', 5),

];
