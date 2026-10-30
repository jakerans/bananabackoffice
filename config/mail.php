<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        // Email to customers (decision 214), on a sending domain of its own,
        // with its own Resend key. Local development writes it to the log.
        'customers' => [
            'transport' => env('MAIL_CUSTOMERS_MAILER', 'log'),
            'key' => env('RESEND_CUSTOMERS_API_KEY'),
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'backoffice@mail.bananapainting.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Banana Backoffice')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Global "Reply-To" Address
    |--------------------------------------------------------------------------
    |
    | Backoffice sends from a domain used only for sending, so nobody reads
    | mail delivered to the "from" address. When a monitored inbox is set
    | here, replies to any application email are directed to it instead.
    | Leave it unset and no reply-to header is added.
    |
    */

    'reply_to' => [
        'address' => env('MAIL_REPLY_TO_ADDRESS'),
        'name' => env('MAIL_REPLY_TO_NAME', env('MAIL_FROM_NAME', env('APP_NAME', 'Banana Backoffice'))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email to Customers
    |--------------------------------------------------------------------------
    |
    | The customer's copy of a signed agreement (decision 214). Sent through
    | the "customers" mailer from an address on its own sending domain, from
    | "Banana Painting" and the Location's name, with replies reaching the
    | salesperson. Nothing is sent until it is switched on. Outside
    | production only addresses at the test domains are emailed, so staging
    | never writes to a customer.
    |
    */

    'customers' => [
        'enabled' => (bool) env('MAIL_CUSTOMERS_ENABLED', false),
        'mailer' => 'customers',
        'from_address' => env('MAIL_CUSTOMERS_FROM_ADDRESS', 'hello@email.bananapainting.com'),
        'from_name' => env('MAIL_CUSTOMERS_FROM_NAME', 'Banana Painting'),
        'test_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('MAIL_CUSTOMERS_TEST_DOMAINS', 'bananapainting.com'))))),
    ],

];
