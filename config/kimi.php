<?php

return [

    /*
    |--------------------------------------------------------------------------
    | RunPod queue
    |--------------------------------------------------------------------------
    |
    | The application submits one queue job and polls its status. The API key
    | and endpoint id stay in this configuration. They are never shared with
    | Inertia. The base URL is pinned to the RunPod API host in KimiService
    | so a mistyped host cannot receive the key.
    |
    */

    'base_url' => env('KIMI_BASE_URL', 'https://api.runpod.ai/v2'),

    'endpoint_id' => env('RUNPOD_ENDPOINT_ID'),

    'api_key' => env('RUNPOD_API_KEY'),

    'model' => env('KIMI_MODEL', 'kimi-vl') ?: 'kimi-vl',

    'submit_timeout' => max(1, (int) env('KIMI_SUBMIT_TIMEOUT', 20)),

    'status_timeout' => max(1, (int) env('KIMI_STATUS_TIMEOUT', 15)),

    'connect_timeout' => max(1, (int) env('KIMI_CONNECT_TIMEOUT', 5)),

    /*
    | The browser waits this long between status checks. The server enforces
    | the same floor so a shorter environment value cannot hammer the queue.
    |
    */
    'poll_interval_ms' => max(2000, (int) env('KIMI_POLL_INTERVAL_MS', 2000)),

    /*
    | 900 seconds covers the worker startup cap. 120 seconds covers generation.
    | The budget is measured across polls, not inside one PHP request.
    |
    */
    'poll_budget_seconds' => max(1, (int) env('KIMI_POLL_BUDGET', 1020)),

    'transport_attempts' => max(1, (int) env('KIMI_TRANSPORT_ATTEMPTS', 5)),

    'temperature' => (float) env('KIMI_TEMPERATURE', 0.2),

    'max_tokens' => max(1, min(1024, (int) env('KIMI_MAX_TOKENS', 512))),

    /*
    | Count of stored turns forwarded after the fixed system line. The worker
    | max model length remains the hard limit.
    |
    */
    'context_messages' => max(1, (int) env('KIMI_CONTEXT_MESSAGES', 6)),

    'system_prompt' => env('KIMI_SYSTEM_PROMPT', 'You are a helpful assistant.'),

];
