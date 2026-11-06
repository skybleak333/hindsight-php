<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hindsight API
    |--------------------------------------------------------------------------
    |
    | Where the Hindsight API listens. A local server started with `hindsight-api`
    | or Docker listens on port 8888. For Hindsight Cloud use
    | https://api.hindsight.vectorize.io and your API key.
    |
    */

    'url' => env('HINDSIGHT_URL', 'http://localhost:8888'),

    'api_key' => env('HINDSIGHT_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to wait for a response. Synchronous retain and reflect run an LLM
    | on the server, so they need more time than recall.
    |
    */

    'timeout' => (float) env('HINDSIGHT_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Extra headers
    |--------------------------------------------------------------------------
    |
    | Sent with every request, e.g. for a proxy in front of the API.
    |
    */

    'headers' => [],

    /*
    |--------------------------------------------------------------------------
    | Queued retains
    |--------------------------------------------------------------------------
    |
    | Used by the RetainMemory job and by Memory::bank(...)->queue(). A failed
    | attempt (connection error, timeout, 429 or 5xx) is retried after the
    | backoff, in seconds; Laravel repeats the last step for later attempts.
    | Leave the connection and queue empty to use your application's defaults.
    |
    */

    'queue' => [
        'connection' => env('HINDSIGHT_QUEUE_CONNECTION'),
        'queue' => env('HINDSIGHT_QUEUE'),
        'tries' => (int) env('HINDSIGHT_QUEUE_TRIES', 6),
        // The last step repeats: six attempts span about 36 minutes.
        'backoff' => [10, 60, 300, 900],
        // The job carries the conversation text. Encrypt it while it waits in the queue (uses APP_KEY).
        'encrypt' => (bool) env('HINDSIGHT_QUEUE_ENCRYPT', false),
    ],

];
