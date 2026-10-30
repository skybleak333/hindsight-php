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

];
